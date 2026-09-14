<?php

declare(strict_types=1);

namespace Tests\Feature\Announcements;

use App\Enums\AnnouncementCategory;
use App\Enums\RoleCode;
use App\Models\Announcement;
use App\Models\Building;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsAGuestScenario;
use Tests\TestCase;

/**
 * FR-11, «Announcement feed», one test per acceptance criterion still in the
 * MVP.
 *
 * The criterion about marking unread items has gone with the acknowledgement
 * it was derived from: the mark was a left join against `announcement_acks`
 * and there is no such table. What is left — the interval of NFR-01, the
 * ordering, the category filter and the pagination — is kept in separate tests
 * on purpose, because they fail for different reasons.
 */
final class AnnouncementFeedTest extends TestCase
{
    use BuildsAGuestScenario, RefreshDatabase;

    private Building $building;

    private User $warden;

    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 09:30:00'));

        $this->building = $this->dormitory('Block A');
        $this->warden = $this->staff(RoleCode::Warden, $this->building, 'warden@example.test');
        $this->resident = $this->residentOf($this->building, 'resident@example.test', '305');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * First criterion: «the feed opens within NFR-01».
     *
     * NFR-01 gives all pages other than the security post's lookup three
     * seconds, and §4.7.3 states that this clause is checked by **spot
     * measurement** rather than by a load run: a load test with fifty
     * concurrent users belongs to a tool outside the suite, and a PHPUnit test
     * pretending to be one would measure the test runner. What is measured
     * here is the server time of one request against a feed of a hundred
     * announcements, which is what catches the failure this endpoint could
     * actually have — a query per row where the design has a join.
     *
     * The budget comes from configuration rather than from a literal 3, so the
     * requirement and the assertion cannot drift apart.
     */
    public function test_the_feed_opens_within_the_interval_of_nfr_01(): void
    {
        Announcement::factory()
            ->count(100)
            ->forBuilding($this->building)
            ->by($this->warden)
            ->create();

        Sanctum::actingAs($this->resident);

        $budget = (float) config('dormitory.announcements.feed_budget_seconds');

        $startedAt = hrtime(true);

        $this->getJson('/api/v1/announcements')->assertStatus(200);

        $elapsed = (hrtime(true) - $startedAt) / 1_000_000_000;

        $this->assertLessThan(
            $budget,
            $elapsed,
            sprintf('The feed took %.3f s, and NFR-01 allows %.3f s.', $elapsed, $budget),
        );
    }

    /**
     * Second criterion, first half: «the feed is sorted by date».
     *
     * Newest first, which is the order a feed is read in. The identifier
     * breaks a tie, so two notices posted in the same second keep their places
     * between one page and the next.
     */
    public function test_the_feed_is_sorted_by_date_newest_first(): void
    {
        $oldest = $this->announcementPublishedAt('2026-09-01 10:00:00', 'Lift out of service');
        $newest = $this->announcementPublishedAt('2026-09-13 18:00:00', 'Cold water off on Wednesday');
        $middle = $this->announcementPublishedAt('2026-09-07 12:00:00', 'Board games on Thursdays');

        Sanctum::actingAs($this->resident);

        $this->getJson('/api/v1/announcements')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $newest->getKey())
            ->assertJsonPath('data.1.id', $middle->getKey())
            ->assertJsonPath('data.2.id', $oldest->getKey());
    }

    /**
     * Second criterion, second half: «filterable by category».
     *
     * The category is a free label, so the filter is an exact match on what
     * was stored and a heading nobody has posted under is an empty page. It is
     * **not** a 422 any more: there is no closed list left for a value to be
     * outside of, and refusing an unknown label would refuse the very headings
     * the free field exists to admit.
     */
    public function test_the_feed_is_filterable_by_category(): void
    {
        $utilities = Announcement::factory()
            ->forBuilding($this->building)
            ->by($this->warden)
            ->ofCategory(AnnouncementCategory::Utilities)
            ->create(['title' => 'Cold water off on Wednesday']);

        Announcement::factory()
            ->forBuilding($this->building)
            ->by($this->warden)
            ->ofCategory(AnnouncementCategory::Events)
            ->create(['title' => 'Board games on Thursdays']);

        Sanctum::actingAs($this->resident);

        $this->getJson('/api/v1/announcements?category='.AnnouncementCategory::Utilities->value)
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $utilities->getKey());

        $this->getJson('/api/v1/announcements?category=safety')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        // A heading nobody has posted under is an empty feed and not a 422.
        $this->getJson('/api/v1/announcements?category=gossip')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    /**
     * The same filter over a heading the author typed rather than chose, which
     * is the case the closed enumeration used to make impossible.
     */
    public function test_the_feed_is_filterable_by_a_category_nobody_chose_from_the_catalogue(): void
    {
        $typed = Announcement::factory()
            ->forBuilding($this->building)
            ->by($this->warden)
            ->ofCategory('water_supply')
            ->create(['title' => 'Cold water off on Wednesday']);

        Announcement::factory()
            ->forBuilding($this->building)
            ->by($this->warden)
            ->ofCategory(AnnouncementCategory::Events)
            ->create(['title' => 'Board games on Thursdays']);

        Sanctum::actingAs($this->resident);

        $this->getJson('/api/v1/announcements?category=water_supply')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $typed->getKey())
            // Nothing in the catalogue answers to it, so the label is its own
            // name.
            ->assertJsonPath('data.0.category_label', 'water_supply');
    }

    /**
     * The feed is paginated, which is what makes the archive usable after a
     * few academic years. The page size is configuration.
     */
    public function test_the_feed_is_paginated_at_the_configured_page_size(): void
    {
        $size = (int) config('dormitory.announcements.page_size');

        Announcement::factory()
            ->count($size + 3)
            ->forBuilding($this->building)
            ->by($this->warden)
            ->create();

        Sanctum::actingAs($this->resident);

        $this->getJson('/api/v1/announcements')
            ->assertStatus(200)
            ->assertJsonCount($size, 'data')
            ->assertJsonPath('meta.total', $size + 3);

        $this->getJson('/api/v1/announcements?page=2')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    /**
     * FR-05 reaching the feed. A resident whose departure date has passed
     * keeps their account and loses the building-bound functions, and a feed
     * of a dormitory one no longer lives in is such a function.
     */
    public function test_a_resident_who_has_moved_out_no_longer_sees_the_feed_of_that_dormitory(): void
    {
        Announcement::factory()
            ->forBuilding($this->building)
            ->by($this->warden)
            ->create();

        // FR-05 asks for the date and the ground together, and the database
        // enforces the pair.
        $this->resident->openResidency?->update([
            'moved_out_at' => CarbonImmutable::now()->subDay()->toDateString(),
            'moved_out_ground' => 'End of the accommodation contract',
        ]);

        Sanctum::actingAs($this->resident->fresh());

        $this->getJson('/api/v1/announcements')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_the_feed_needs_a_session(): void
    {
        $this->getJson('/api/v1/announcements')->assertStatus(401);
    }

    private function announcementPublishedAt(string $moment, string $title): Announcement
    {
        return Announcement::factory()
            ->forBuilding($this->building)
            ->by($this->warden)
            ->publishedAt(CarbonImmutable::parse($moment))
            ->create(['title' => $title]);
    }
}

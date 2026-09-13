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
 * FR-11, «Announcement feed», one test per acceptance criterion.
 *
 * The three criteria are the three properties of one query, and the tests keep
 * them apart on purpose: the ordering, the category filter and the unread mark
 * fail for different reasons and a single test asserting all three would hide
 * which.
 */
final class AnnouncementFeedTest extends TestCase
{
    use BuildsAGuestScenario, RefreshDatabase;

    private Building $building;

    private User $warden;

    private User $resident;

    private User $neighbour;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 09:30:00'));

        $this->building = $this->dormitory('Block A');
        $this->warden = $this->staff(RoleCode::Warden, $this->building, 'warden@example.test');
        $this->resident = $this->residentOf($this->building, 'resident@example.test', '305');
        $this->neighbour = $this->residentOf($this->building, 'neighbour@example.test', '306');
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
     * Second criterion: «unread items are visually marked».
     *
     * The mark is `is_unread`, computed by the left join of §4.6.1 against
     * `announcement_acks`, and it clears when the acknowledgement is recorded
     * — which is the same row FR-12 counts, not a second «read» flag that
     * could disagree with it.
     */
    public function test_unread_items_are_marked_and_the_mark_clears_on_acknowledgement(): void
    {
        $announcement = Announcement::factory()
            ->forBuilding($this->building)
            ->by($this->warden)
            ->create();

        Sanctum::actingAs($this->resident);

        $this->getJson('/api/v1/announcements')
            ->assertStatus(200)
            ->assertJsonPath('data.0.is_unread', true)
            ->assertJsonPath('data.0.acknowledged_at', null);

        $this->postJson("/api/v1/announcements/{$announcement->getKey()}/ack")
            ->assertStatus(200);

        $this->getJson('/api/v1/announcements')
            ->assertStatus(200)
            ->assertJsonPath('data.0.is_unread', false)
            ->assertJsonPath('data.0.acknowledged_at', CarbonImmutable::now()->toIso8601String());
    }

    /**
     * The same criterion at the point it is easiest to get wrong.
     *
     * The join is keyed on the reader, so one resident's acknowledgement must
     * not mark the notice read for the whole dormitory. A join written without
     * the `user_id` condition passes the test above and fails this one, which
     * is why the two are separate.
     */
    public function test_one_residents_acknowledgement_leaves_the_item_unread_for_another(): void
    {
        $announcement = Announcement::factory()
            ->forBuilding($this->building)
            ->by($this->warden)
            ->create();

        Sanctum::actingAs($this->resident);
        $this->postJson("/api/v1/announcements/{$announcement->getKey()}/ack")->assertStatus(200);

        Sanctum::actingAs($this->neighbour);

        $this->getJson('/api/v1/announcements')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_unread', true);
    }

    /**
     * Third criterion, first half: «the feed is sorted by date».
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
     * Third criterion, second half: «filterable by category».
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

        // A category the enumeration does not know is a malformed request and
        // not an empty feed: a client filtering on a typo must be told.
        $this->getJson('/api/v1/announcements?category=gossip')
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');
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

<?php

declare(strict_types=1);

namespace Tests\Feature\Announcements;

use App\Models\Announcement;
use App\Models\AnnouncementAck;
use App\Services\AnnouncementQuery;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The development stand for the announcement module, asserted rather than
 * assumed.
 *
 * A seeder is the one piece of the system nobody runs in anger and everybody
 * relies on at the demonstration. What matters here is what makes the module's
 * two invisible behaviours visible: a feed with a history of different ages, so
 * that FR-09's expiry is something already shown rather than something waited
 * for; and a mandatory notice acknowledged by some residents and not by
 * others, so that FR-12's list of those who have not read is not empty — the
 * one state that would prove nothing.
 */
final class AnnouncementSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_stand_carries_announcements_of_different_ages(): void
    {
        $this->seed(DatabaseSeeder::class);

        $now = CarbonImmutable::now();

        $this->assertTrue(
            Announcement::query()->currentAt($now)->exists(),
            'The stand has no current announcement, so the feed is empty.',
        );

        $this->assertTrue(
            Announcement::query()->archivedAt($now)->exists(),
            'The stand has no expired announcement, so FR-09 cannot be demonstrated.',
        );

        $this->assertTrue(
            Announcement::query()->whereNull('expires_at')->exists(),
            'The stand has no open-ended announcement.',
        );

        // §3.4.2: NULL addresses every dormitory, and a stand without one
        // cannot show that a resident of either building sees it.
        $this->assertTrue(Announcement::query()->whereNull('building_id')->exists());
    }

    public function test_the_stand_carries_a_mandatory_notice_that_some_have_read_and_some_have_not(): void
    {
        $this->seed(DatabaseSeeder::class);

        $mandatory = Announcement::query()
            ->where('is_mandatory', true)
            ->whereNotNull('building_id')
            ->orderBy('id')
            ->first();

        $this->assertNotNull($mandatory, 'The stand has no mandatory announcement.');

        $acknowledged = AnnouncementAck::query()
            ->where('announcement_id', $mandatory->getKey())
            ->count();

        $this->assertGreaterThan(0, $acknowledged, 'Nobody has acknowledged the mandatory notice.');

        $audience = app(AnnouncementQuery::class)->recipientsOf($mandatory)->count();

        $this->assertGreaterThan(
            $acknowledged,
            $audience,
            'Everybody has acknowledged the mandatory notice, so FR-12 has an empty list to show.',
        );
    }

    public function test_a_second_run_does_not_double_the_feed(): void
    {
        $this->seed(DatabaseSeeder::class);

        $announcements = Announcement::query()->count();
        $acks = AnnouncementAck::query()->count();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($announcements, Announcement::query()->count());
        $this->assertSame($acks, AnnouncementAck::query()->count());
    }
}

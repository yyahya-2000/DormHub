<?php

declare(strict_types=1);

namespace Tests\Feature\Announcements;

use App\Enums\AnnouncementCategory;
use App\Models\Announcement;
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
 * for; and a heading outside the catalogue, so that FR-11's filter is shown
 * working on a label somebody typed and not only on the five the enumeration
 * suggests.
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

    public function test_the_stand_carries_a_category_from_outside_the_catalogue(): void
    {
        $this->seed(DatabaseSeeder::class);

        $typed = Announcement::query()
            ->whereNotIn('category', AnnouncementCategory::values())
            ->exists();

        $this->assertTrue(
            $typed,
            'Every heading on the stand comes from the catalogue, so a free label is never demonstrated.',
        );
    }

    public function test_a_second_run_does_not_double_the_feed(): void
    {
        $this->seed(DatabaseSeeder::class);

        $announcements = Announcement::query()->count();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($announcements, Announcement::query()->count());
    }
}

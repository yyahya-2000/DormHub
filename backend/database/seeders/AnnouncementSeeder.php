<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AnnouncementCategory;
use App\Enums\RoleCode;
use App\Models\Announcement;
use App\Models\Building;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * The announcement module on the development stand: a feed with a history in
 * it, an archive that is not empty, and headings from inside the catalogue and
 * outside it.
 *
 * **Every notice here is invented (C-05).** The titles and bodies describe
 * events any dormitory has — a riser being replaced, a fire drill, a change to
 * the reception hours — and none of them refers to a real building, a real date
 * or a real person. Nothing is sampled: the notices are a fixed list, so a
 * second run on a fresh database produces the same stand.
 *
 * **Why the ages differ, and why that is the point.** FR-09's second criterion
 * is that an expired announcement leaves the feed, and the feed decides it by
 * comparing `expires_at` with the present moment rather than by a status
 * somebody sets. That behaviour is invisible on a stand where every notice was
 * written today: the archive is empty, the feed holds everything, and the two
 * queries cannot be told apart. So the list below spans a month — one notice
 * already expired, one expiring shortly, two open-ended — and the demonstration
 * of «it left by itself» is a page that already has rows on it.
 *
 * **One notice carries a heading nobody chose from the list.** The category is
 * a free label of at most 32 characters and the catalogue is a suggestion, so
 * the stand has to show a filter working on a heading a warden typed —
 * otherwise the demonstration proves only that the five cases still work.
 *
 * The seeder runs after the housing register and the staff accounts, because
 * every notice names an author and a dormitory. It seeds nothing when either is
 * missing, exactly as the consent, notification and guest seeders do.
 */
class AnnouncementSeeder extends Seeder
{
    public function run(): void
    {
        $buildings = Building::query()->orderBy('id')->get();

        if ($buildings->isEmpty()) {
            return;
        }

        if (Announcement::query()->exists()) {
            // Idempotent in the only way that matters: a second run must not
            // double the feed the demonstration is walked through.
            return;
        }

        $today = CarbonImmutable::now();

        foreach ($buildings as $building) {
            $author = $this->staffOf($building, RoleCode::Warden)
                ?? $this->staffOf($building, RoleCode::Manager);

            if ($author === null) {
                continue;
            }

            $this->noticesOf($building, $author, $today);
        }

        $this->noticeToEveryBuilding($today);
    }

    /**
     * Five notices for one dormitory, spanning a month.
     */
    private function noticesOf(Building $building, User $author, CarbonImmutable $today): void
    {
        // Already in the archive: published a month ago, expired a fortnight
        // ago. The feed must not show it and the archive must.
        $this->write($building, $author, [
            'title' => 'Lift out of service, entrance 2',
            'body' => 'The lift in entrance 2 is under repair. Use entrance 1 until the work is finished.',
            'category' => AnnouncementCategory::Utilities->value,
            'published_at' => $today->subDays(30),
            'expires_at' => $today->subDays(16),
        ]);

        // Open-ended and routine: the kind of notice a resident may switch the
        // optional notification category off for.
        $this->write($building, $author, [
            'title' => 'Board games on Thursday evenings',
            'body' => 'The common room on the first floor is open from 19:00 on Thursdays. Bring your own tea.',
            'category' => AnnouncementCategory::Events->value,
            'published_at' => $today->subDays(14),
            'expires_at' => null,
        ]);

        $this->write($building, $author, [
            'title' => 'Reception hours of the warden have changed',
            'body' => 'From the first of next month the warden receives residents on Tuesdays and '
                .'Thursdays from 16:00 to 19:00.',
            'category' => AnnouncementCategory::HouseRules->value,
            'published_at' => $today->subDays(7),
            'expires_at' => $today->addDays(23),
        ]);

        $this->write($building, $author, [
            'title' => 'Fire drill on the twenty-second',
            'body' => 'An evacuation drill begins at 11:00. Leave the building by the nearest staircase '
                .'and gather at the sports ground.',
            'category' => AnnouncementCategory::Safety->value,
            'published_at' => $today->subDays(2),
            'expires_at' => $today->addDays(12),
        ]);

        // The heading nobody chose from the catalogue: the stand has to hold
        // one, or the category filter is only ever demonstrated on the five
        // cases the enumeration already knew.
        $this->write($building, $author, [
            'title' => 'Cold water off on Wednesday, 09:00 to 17:00',
            'body' => 'The riser on floors three to five is being replaced. Water returns the same evening.',
            'category' => 'water_supply',
            'published_at' => $today->subHours(4),
            'expires_at' => $today->addDays(6),
        ]);
    }

    /**
     * `building_id` NULL: the administrator addressing every dormitory
     * (§3.4.2). One such notice, because the stand needs to show that a
     * resident of either building sees it in their own feed.
     */
    private function noticeToEveryBuilding(CarbonImmutable $today): void
    {
        $administrator = User::query()
            ->whereHas('roleGrants.role', fn ($query) => $query->where('code', RoleCode::Administrator->value))
            ->orderBy('id')
            ->first();

        if ($administrator === null) {
            return;
        }

        Announcement::query()->create([
            'building_id' => null,
            'author_id' => $administrator->getKey(),
            'title' => 'Passes for the winter holidays',
            'body' => 'Residents staying over the holidays must tell the warden of their dormitory by the '
                .'twentieth.',
            'category' => AnnouncementCategory::HouseRules->value,
            'published_at' => $today->subDays(3),
            'expires_at' => $today->addDays(27),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(Building $building, User $author, array $attributes): Announcement
    {
        return Announcement::query()->create($attributes + [
            'building_id' => $building->getKey(),
            'author_id' => $author->getKey(),
        ]);
    }

    private function staffOf(Building $building, RoleCode $code): ?User
    {
        $role = Role::query()->where('code', $code->value)->first();

        if ($role === null) {
            return null;
        }

        return User::query()
            ->whereHas('roleGrants', fn ($query) => $query
                ->where('role_id', $role->getKey())
                ->where('building_id', $building->getKey()))
            ->orderBy('id')
            ->first();
    }
}

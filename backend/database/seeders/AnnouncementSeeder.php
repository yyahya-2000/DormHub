<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AnnouncementCategory;
use App\Enums\RoleCode;
use App\Models\Announcement;
use App\Models\AnnouncementAck;
use App\Models\Building;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * The announcement module on the development stand: a feed with a history in
 * it, an archive that is not empty, and a mandatory notice that some residents
 * have acknowledged and some have not.
 *
 * **Every notice here is invented (C-05).** The titles and bodies describe
 * events any dormitory has — a riser being replaced, a fire drill, a change to
 * the reception hours — and none of them refers to a real building, a real date
 * or a real person. Nothing is sampled: the notices are a fixed list and the
 * acknowledgements are decided by the index of the resident, so a second run on
 * a fresh database produces the same stand.
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
 * **The acknowledgements are partial on purpose.** FR-12 puts a named list of
 * those who have *not* read in front of the warden, and a stand where everyone
 * has acknowledged shows an empty list, which is the one state that proves
 * nothing. Roughly half the residents acknowledge the mandatory notice, chosen
 * by the parity of their position in the register so the choice is
 * reproducible.
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
            'category' => AnnouncementCategory::Utilities,
            'is_mandatory' => false,
            'published_at' => $today->subDays(30),
            'expires_at' => $today->subDays(16),
        ]);

        // Open-ended and routine: the kind of notice a resident may switch the
        // optional notification category off for.
        $this->write($building, $author, [
            'title' => 'Board games on Thursday evenings',
            'body' => 'The common room on the first floor is open from 19:00 on Thursdays. Bring your own tea.',
            'category' => AnnouncementCategory::Events,
            'is_mandatory' => false,
            'published_at' => $today->subDays(14),
            'expires_at' => null,
        ]);

        // The one FR-12 is about: mandatory, still current, partly
        // acknowledged.
        $rules = $this->write($building, $author, [
            'title' => 'Reception hours of the warden have changed',
            'body' => 'From the first of next month the warden receives residents on Tuesdays and '
                .'Thursdays from 16:00 to 19:00. Confirm that you have read this notice.',
            'category' => AnnouncementCategory::HouseRules,
            'is_mandatory' => true,
            'published_at' => $today->subDays(7),
            'expires_at' => $today->addDays(23),
        ]);

        $drill = $this->write($building, $author, [
            'title' => 'Fire drill on the twenty-second',
            'body' => 'An evacuation drill begins at 11:00. Leave the building by the nearest staircase '
                .'and gather at the sports ground.',
            'category' => AnnouncementCategory::Safety,
            'is_mandatory' => true,
            'published_at' => $today->subDays(2),
            'expires_at' => $today->addDays(12),
        ]);

        $this->write($building, $author, [
            'title' => 'Cold water off on Wednesday, 09:00 to 17:00',
            'body' => 'The riser on floors three to five is being replaced. Water returns the same evening.',
            'category' => AnnouncementCategory::Utilities,
            'is_mandatory' => false,
            'published_at' => $today->subHours(4),
            'expires_at' => $today->addDays(6),
        ]);

        $residents = $this->residentsOf($building);

        // Every second resident has acknowledged the change to the reception
        // hours; every fourth has acknowledged the drill. Two different shares
        // so that the report is not the same number twice.
        foreach ($residents as $index => $resident) {
            if ($index % 2 === 0) {
                $this->acknowledge($rules, $resident, $today->subDays(6)->addHours($index));
            }

            if ($index % 4 === 0) {
                $this->acknowledge($drill, $resident, $today->subDay()->addHours($index));
            }
        }
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

        $notice = Announcement::query()->create([
            'building_id' => null,
            'author_id' => $administrator->getKey(),
            'title' => 'Passes for the winter holidays',
            'body' => 'Residents staying over the holidays must tell the warden of their dormitory by the '
                .'twentieth. Confirm that you have read this notice.',
            'category' => AnnouncementCategory::HouseRules,
            'is_mandatory' => true,
            'published_at' => $today->subDays(3),
            'expires_at' => $today->addDays(27),
        ]);

        // Acknowledged in one dormitory and not in the other, so that a warden
        // reading the readers of an all-buildings notice sees a list that is
        // demonstrably his own and not everybody's.
        $first = Building::query()->orderBy('id')->first();

        if ($first === null) {
            return;
        }

        foreach ($this->residentsOf($first) as $index => $resident) {
            if ($index % 3 === 0) {
                $this->acknowledge($notice, $resident, $today->subDays(2)->addHours($index));
            }
        }
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

    private function acknowledge(Announcement $announcement, User $resident, CarbonImmutable $at): void
    {
        AnnouncementAck::query()->firstOrCreate(
            [
                'announcement_id' => $announcement->getKey(),
                'user_id' => $resident->getKey(),
            ],
            ['acknowledged_at' => $at],
        );
    }

    /**
     * @return list<User>
     */
    private function residentsOf(Building $building): array
    {
        $role = Role::query()->where('code', RoleCode::Resident->value)->first();

        if ($role === null) {
            return [];
        }

        return User::query()
            ->whereHas('roleGrants', fn ($query) => $query
                ->where('role_id', $role->getKey())
                ->where('building_id', $building->getKey()))
            ->orderBy('id')
            ->get()
            ->values()
            ->all();
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

<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RoleCode;
use App\Models\Announcement;
use App\Models\Residency;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The read side of the announcement module (FR-11), named as §3.3.6 names it.
 *
 * *Purpose*: the feed a resident reads and the audience an announcement is
 * addressed to.
 * *Dependencies*: the models alone; no controller and no HTTP object (§3.3.1).
 *
 * **One definition of «the audience», used twice.** The people a notification
 * is sent to and the people whose feed the announcement appears in are the
 * same set, computed by `residentsOfBuildings()` and by `addressedTo()`
 * reading the same two facts — the resident grant that names a dormitory, and
 * the residency register that says whether the person has left it (FR-05).
 * Two definitions that agreed today would disagree the first time somebody
 * moved out, and the disagreement would surface as a resident missing a notice
 * the feed still showed him.
 */
final readonly class AnnouncementQuery
{
    /**
     * FR-11: the feed.
     *
     * Two properties of this query are the requirement rather than an
     * arrangement of it.
     *
     * The audience filter is `building_id`, with NULL meaning every dormitory
     * (§3.4.2), so FR-09's first criterion is enforced on the read and not by
     * hiding a button.
     *
     * The currency filter is `published_at` and `expires_at`, so FR-09's
     * second criterion needs no archiving job: an announcement leaves the feed
     * the moment it expires, and `$archived` shows the other side of the same
     * line rather than a table somebody moved rows into.
     *
     * FR-11's unread mark is gone with the acknowledgement it was derived
     * from: it was a left join against `announcement_acks`, and there is no
     * such table any more.
     *
     * `$category` is matched on the stored label exactly. Nothing is validated
     * against a list here, because there is no list — a heading nobody has
     * posted under is an empty page and not an error.
     */
    public function feed(
        User $reader,
        CarbonInterface $moment,
        ?string $category = null,
        bool $archived = false,
        ?int $perPage = null,
    ): LengthAwarePaginator {
        $query = Announcement::query()
            ->select('announcements.*')
            ->with(['building', 'author']);

        $buildingIds = $this->readableBuildingIdsFor($reader);

        if ($buildingIds !== null) {
            $query->addressedTo($buildingIds);
        }

        $archived
            ? $query->archivedAt($moment)
            : $query->currentAt($moment);

        if ($category !== null) {
            $query->ofCategory($category);
        }

        return $query
            // FR-11: «sorted by date». The identifier breaks the tie, because
            // two notices posted in the same second must not swap places
            // between one page of the feed and the next.
            ->orderByDesc('announcements.published_at')
            ->orderByDesc('announcements.id')
            ->paginate($perPage ?? (int) config('dormitory.announcements.page_size'));
    }

    /**
     * Whether this person is inside the audience of this announcement — the
     * question `AnnouncementPolicy::view` asks.
     */
    public function reaches(User $reader, Announcement $announcement): bool
    {
        $buildingIds = $this->readableBuildingIdsFor($reader);

        if ($buildingIds === null) {
            return true;
        }

        return $announcement->addressesEveryBuilding()
            || in_array((int) $announcement->building_id, $buildingIds, true);
    }

    /**
     * The people an announcement is to be delivered to (FR-09, FR-34).
     *
     * @return Collection<int, User>
     */
    public function recipientsOf(Announcement $announcement): Collection
    {
        return $this->residentsOfBuildings(
            $announcement->addressesEveryBuilding()
                ? null
                : [(int) $announcement->building_id]
        );
    }

    /**
     * The residents of these dormitories, by the register: everyone whose
     * resident grant names one of them, less everyone the residency register
     * says has moved out of it (FR-05).
     *
     * A null list means every dormitory, which is what an announcement
     * addressed to all of them needs.
     *
     * **Three queries and not one per person.** `User::hasMovedOutOf()`
     * answers the same question for one account in two, and calling it over a
     * dormitory of several hundred places would be several hundred round trips
     * inside a queued fan-out. The two sets are read whole instead: everyone
     * the register knows in these buildings, and everyone it says is there
     * today; the difference is who has left.
     *
     * @param  list<int>|null  $buildingIds
     * @return Collection<int, User>
     */
    public function residentsOfBuildings(?array $buildingIds): Collection
    {
        $known = $this->registerIds($buildingIds, currentOnly: false);
        $current = $this->registerIds($buildingIds, currentOnly: true);

        $movedOut = array_values(array_diff($known, $current));

        /** @var Collection<int, User> $residents */
        $residents = User::query()
            ->whereHas('roleGrants', function (Builder $grant) use ($buildingIds): void {
                $grant->whereHas(
                    'role',
                    fn (Builder $role) => $role->where('code', RoleCode::Resident->value)
                );

                if ($buildingIds !== null) {
                    $grant->whereIn('building_id', $buildingIds);
                }
            })
            ->when($movedOut !== [], fn (Builder $query) => $query->whereIntegerNotInRaw('id', $movedOut))
            ->orderBy('full_name')
            ->orderBy('id')
            ->get();

        return $residents;
    }

    /**
     * The dormitories whose feed this account reads, or null for «every one of
     * them».
     *
     * Null is the administrator, whose grant names no building; a null return
     * makes the caller skip the filter altogether rather than assemble a list
     * of every identifier in the register.
     *
     * @return list<int>|null
     */
    private function readableBuildingIdsFor(User $reader): ?array
    {
        if ($reader->isAdministrator()) {
            return null;
        }

        return array_values(array_filter(
            $reader->scopedBuildingIds(),
            // FR-05: the feed is a building-bound function and closes on the
            // stated departure date. A staff grant is never touched by this —
            // `hasMovedOutOf` asks the residency register, which has nothing
            // to say about an account that has never held a bed.
            fn (int $buildingId): bool => ! $reader->hasMovedOutOf($buildingId),
        ));
    }

    /**
     * Identifiers the residency register holds for these buildings, either
     * over the whole history or as of today.
     *
     * @param  list<int>|null  $buildingIds
     * @return list<int>
     */
    private function registerIds(?array $buildingIds, bool $currentOnly): array
    {
        $query = Residency::query()
            ->join('beds', 'beds.id', '=', 'residencies.bed_id')
            ->join('rooms', 'rooms.id', '=', 'beds.room_id');

        if ($buildingIds !== null) {
            $query->whereIn('rooms.building_id', $buildingIds);
        }

        if ($currentOnly) {
            $query->currentOn(now());
        }

        /** @var list<int> $ids */
        $ids = $query->pluck('residencies.user_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $ids;
    }
}

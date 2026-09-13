<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AnnouncementCategory;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\RoleCode;
use App\Models\Announcement;
use App\Models\AnnouncementAck;
use App\Models\Residency;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;

/**
 * The read side of the announcement module (FR-11, FR-12), named as §3.3.6
 * names it.
 *
 * *Purpose*: the feed a resident reads, the audience an announcement is
 * addressed to, and the two lists FR-12 puts in front of the warden.
 * *Dependencies*: the models and `AuditRecorder`; no controller and no HTTP
 * object (§3.3.1).
 *
 * **One definition of «the audience», used three times.** The people a
 * notification is sent to, the people whose feed the announcement appears in,
 * and the people counted in FR-12's share are the same set, computed by
 * `residentsOfBuildings()` and by `addressedTo()` reading the same two facts —
 * the resident grant that names a dormitory, and the residency register that
 * says whether the person has left it (FR-05). Three definitions that agreed
 * today would disagree the first time somebody moved out, and the disagreement
 * would surface as a warden chasing a resident who never received the notice.
 */
final readonly class AnnouncementQuery
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * FR-11: the feed.
     *
     * Three properties of this query are the requirement rather than a
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
     * The unread mark is a **left join** against `announcement_acks`, keyed on
     * the reader (§4.6.1). It is a join and not a second query for the reason
     * FR-11 pairs sorting with marking: the list is paginated, and a mark
     * fetched separately would have to be fetched for the whole table or
     * stitched onto a page in the client.
     */
    public function feed(
        User $reader,
        CarbonInterface $moment,
        ?AnnouncementCategory $category = null,
        bool $archived = false,
        ?int $perPage = null,
    ): LengthAwarePaginator {
        $query = Announcement::query()
            ->leftJoin('announcement_acks', function (JoinClause $join) use ($reader): void {
                $join
                    ->on('announcement_acks.announcement_id', '=', 'announcements.id')
                    ->where('announcement_acks.user_id', '=', $reader->getKey());
            })
            ->select([
                'announcements.*',
                'announcement_acks.acknowledged_at as acknowledged_at',
            ])
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
     * question `AnnouncementPolicy::view` and the acknowledgement both ask.
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
     * FR-12, second criterion: the acknowledged share and the named list of
     * those who have not read — **within the caller's own building**.
     *
     * The narrowing happens here and not in the presentation. An announcement
     * addressed to every dormitory has an audience of every resident of every
     * dormitory, and a warden of block A reading its readers must be shown
     * block A: the policy has already said he may look, and this method
     * decides at what. That pairing is the whole of the horizontal boundary on
     * this route, which §4.6.1 calls the natural place for a leak, and it is
     * why the building list is computed from the caller's grants rather than
     * taken off the announcement.
     *
     * @return array{
     *     announcement: Announcement,
     *     building_ids: list<int>,
     *     audience_size: int,
     *     acknowledged_count: int,
     *     acknowledged_share: float,
     *     acknowledged: list<array{user_id: int, full_name: string, acknowledged_at: string}>,
     *     not_acknowledged: list<array{user_id: int, full_name: string}>
     * }
     */
    public function readers(User $viewer, Announcement $announcement, ?string $ipAddress = null): array
    {
        $buildingIds = $this->readerScopeFor($viewer, $announcement);

        $audience = $this->residentsOfBuildings($buildingIds);

        /** @var \Illuminate\Support\Collection<int, AnnouncementAck> $acks */
        $acks = AnnouncementAck::query()
            ->where('announcement_id', $announcement->getKey())
            ->whereIn('user_id', $audience->modelKeys())
            ->get()
            ->keyBy('user_id');

        $acknowledged = [];
        $notAcknowledged = [];

        foreach ($audience as $person) {
            $ack = $acks->get($person->getKey());

            if ($ack !== null) {
                $acknowledged[] = [
                    'user_id' => (int) $person->getKey(),
                    'full_name' => (string) $person->full_name,
                    'acknowledged_at' => $ack->acknowledged_at->toIso8601String(),
                ];

                continue;
            }

            $notAcknowledged[] = [
                'user_id' => (int) $person->getKey(),
                'full_name' => (string) $person->full_name,
            ];
        }

        $size = $audience->count();

        $this->audit->record(
            action: AuditAction::AnnouncementReadersViewed,
            actor: $viewer,
            subject: $announcement,
            payload: [
                'building_ids' => $buildingIds,
                'audience_size' => $size,
                'acknowledged_count' => count($acknowledged),
            ],
            ipAddress: $ipAddress,
        );

        return [
            'announcement' => $announcement,
            'building_ids' => $buildingIds ?? [],
            'audience_size' => $size,
            'acknowledged_count' => count($acknowledged),
            // An announcement whose audience is empty has read itself in full
            // rather than not at all: a share of zero over zero people would
            // put a red figure in front of a warden with nobody to chase.
            'acknowledged_share' => $size === 0 ? 1.0 : round(count($acknowledged) / $size, 4),
            'acknowledged' => $acknowledged,
            'not_acknowledged' => $notAcknowledged,
        ];
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
     * The buildings whose residents this caller may be shown for this
     * announcement.
     *
     * @return list<int>|null
     */
    private function readerScopeFor(User $viewer, Announcement $announcement): ?array
    {
        if (! $announcement->addressesEveryBuilding()) {
            // The policy has already established that the caller holds the
            // capability in this dormitory.
            return [(int) $announcement->building_id];
        }

        if ($viewer->isAdministrator()) {
            return null;
        }

        return array_values(array_filter(
            $viewer->scopedBuildingIds(),
            fn (int $buildingId): bool => $viewer->hasPermissionInBuilding(
                Permission::PublishAnnouncements,
                $buildingId,
            ),
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

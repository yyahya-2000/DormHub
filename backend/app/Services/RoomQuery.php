<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\BedStatus;
use App\Models\Building;
use App\Models\Room;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * `RoomQuery` of the route table in §3.3.6: the read behind
 * `GET /api/v1/buildings/{id}/rooms` and the floor summary beside it.
 *
 * §3.3.5 admits repositories «selectively, only where a query is complex and
 * reused», and names «free beds in a building» as one of the three. This is
 * that query. It is a separate class from `RoomRegistry` because a read has
 * neither a transaction nor a state change to coordinate, and mixing the two
 * would put a `DB::transaction` around a `SELECT`.
 *
 * Beds are eager-loaded, so the free-places arithmetic on a page of rooms
 * costs two queries rather than one per room.
 */
final readonly class RoomQuery
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * FR-02: the register of one dormitory, a page at a time.
     *
     * `$search` matches part of a room number, `$onlyFree` keeps the rooms
     * holding a place somebody could move into, and `$floor` keeps one storey.
     * All three are asked of the database rather than of the page, so a
     * filtered answer is the whole of what matches and not the part of it that
     * happened to fall on page one.
     *
     * @return LengthAwarePaginator<int, Room>
     */
    public function ofBuilding(
        User $viewer,
        Building $building,
        ?string $search = null,
        bool $onlyFree = false,
        ?int $floor = null,
        ?int $perPage = null,
        ?string $ipAddress = null,
    ): LengthAwarePaginator {
        $query = $building->rooms()
            ->with(['beds' => fn ($query) => $query->orderBy('label')]);

        if ($search !== null && trim($search) !== '') {
            $needle = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($search)).'%';

            $query->where('number', 'like', $needle);
        }

        if ($onlyFree) {
            $query->whereHas(
                'beds',
                fn (Builder $beds) => $beds->where('status', BedStatus::Free->value)
            );
        }

        if ($floor !== null) {
            // `(building_id, floor)` is the index the create migration put
            // there for exactly this question.
            $query->where('floor', $floor);
        }

        /** @var LengthAwarePaginator<int, Room> $rooms */
        $rooms = $query
            ->orderBy('floor')
            ->orderBy('number')
            ->paginate($perPage ?? (int) config('dormitory.housing.page_size'));

        $this->audit->record(
            action: AuditAction::BuildingRoomsViewed,
            actor: $viewer,
            subject: $building,
            payload: [
                'rooms' => $rooms->total(),
                'page' => $rooms->currentPage(),
                'search' => $search,
                'only_free' => $onlyFree,
                'floor' => $floor,
            ],
            ipAddress: $ipAddress,
        );

        return $rooms;
    }

    /**
     * The floors of one dormitory with the occupancy of each: how many rooms
     * stand on it, how many of them still hold a free place, how many places
     * are registered and how many of those nobody holds.
     *
     * One aggregate over `rooms LEFT JOIN beds`, grouped by floor. Walking the
     * rooms in PHP would be the same numbers at the cost of a query per room,
     * and the figures a floor card shows are exactly what `GROUP BY` produces.
     * `FILTER (WHERE …)` is PostgreSQL's, which §3.8.3 already names as a
     * reason for the choice of database.
     *
     * No audit record: a floor holds no personal data, and §3.9.6 records the
     * reading of what does.
     *
     * @return list<array{floor: int, rooms_count: int, rooms_with_free_beds: int, beds_count: int, free_beds: int}>
     */
    public function floorsOf(Building $building): array
    {
        $free = BedStatus::Free->value;

        $rows = DB::table('rooms')
            ->leftJoin('beds', 'beds.room_id', '=', 'rooms.id')
            ->where('rooms.building_id', $building->getKey())
            ->groupBy('rooms.floor')
            ->orderBy('rooms.floor')
            ->selectRaw('rooms.floor as floor')
            ->selectRaw('count(distinct rooms.id) as rooms_count')
            ->selectRaw('count(distinct rooms.id) filter (where beds.status = ?) as rooms_with_free_beds', [$free])
            ->selectRaw('count(beds.id) as beds_count')
            ->selectRaw('count(beds.id) filter (where beds.status = ?) as free_beds', [$free])
            ->get();

        return $rows->map(static fn (object $row): array => [
            'floor' => (int) $row->floor,
            'rooms_count' => (int) $row->rooms_count,
            'rooms_with_free_beds' => (int) $row->rooms_with_free_beds,
            'beds_count' => (int) $row->beds_count,
            'free_beds' => (int) $row->free_beds,
        ])->all();
    }

    /**
     * The card of one room with its places. No audit record: a room holds no
     * personal data, and §3.9.6 records the reading of cards that do.
     */
    public function card(Room $room): Room
    {
        return $room->load(['beds' => fn ($query) => $query->orderBy('label'), 'building']);
    }
}

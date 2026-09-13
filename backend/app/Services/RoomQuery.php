<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\Building;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * `RoomQuery` of the route table in §3.3.6: the read behind
 * `GET /api/v1/buildings/{id}/rooms`.
 *
 * §3.3.5 admits repositories «selectively, only where a query is complex and
 * reused», and names «free beds in a building» as one of the three. This is
 * that query. It is a separate class from `RoomRegistry` because a read has
 * neither a transaction nor a state change to coordinate, and mixing the two
 * would put a `DB::transaction` around a `SELECT`.
 *
 * Beds are eager-loaded, so the free-places arithmetic on forty rooms costs
 * two queries rather than forty-one.
 */
final readonly class RoomQuery
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * @return Collection<int, Room>
     */
    public function ofBuilding(User $viewer, Building $building, ?string $ipAddress = null): Collection
    {
        /** @var Collection<int, Room> $rooms */
        $rooms = $building->rooms()
            ->with(['beds' => fn ($query) => $query->orderBy('label')])
            ->orderBy('floor')
            ->orderBy('number')
            ->get();

        $this->audit->record(
            action: AuditAction::BuildingRoomsViewed,
            actor: $viewer,
            subject: $building,
            payload: [
                'rooms' => $rooms->count(),
                'free_places' => $rooms->sum(fn (Room $room): int => $room->freePlaces()),
                'vacant_beds' => $rooms->sum(fn (Room $room): int => $room->vacantBeds()),
            ],
            ipAddress: $ipAddress,
        );

        return $rooms;
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

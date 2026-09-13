<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Room;
use App\Models\User;

/**
 * FR-02 over one room. Every decision is delegated to the policy of the
 * building the room stands in, which is what keeps the horizontal boundary of
 * FR-07 in one place: a room inherits its scope from its building, and a
 * second, independent notion of «whose room is this» could drift from the
 * first.
 */
final readonly class RoomPolicy
{
    public function __construct(private BuildingPolicy $buildings) {}

    public function view(User $user, Room $room): bool
    {
        $building = $room->building()->first();

        return $building !== null && $this->buildings->viewRooms($user, $building);
    }

    public function update(User $user, Room $room): bool
    {
        $building = $room->building()->first();

        return $building !== null && $this->buildings->manageRooms($user, $building);
    }

    /**
     * Registering a place in this room (FR-02).
     */
    public function addBed(User $user, Room $room): bool
    {
        return $this->update($user, $room);
    }

    /**
     * Placing somebody in a bed of this room (FR-03).
     */
    public function assignResidency(User $user, Room $room): bool
    {
        $building = $room->building()->first();

        return $building !== null && $this->buildings->manageResidencies($user, $building);
    }
}

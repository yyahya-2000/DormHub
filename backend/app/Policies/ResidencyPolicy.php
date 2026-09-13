<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Residency;
use App\Models\User;

/**
 * FR-03 and FR-05 over one residency.
 *
 * The scope travels BUILDING → ROOM → BED → RESIDENCY, so the question «may
 * this warden evict this person» is answered by «is this the warden of the
 * building the bed stands in». A warden of another dormitory is refused here,
 * before the service is reached.
 */
final readonly class ResidencyPolicy
{
    public function __construct(private BuildingPolicy $buildings) {}

    public function view(User $user, Residency $residency): bool
    {
        // The person concerned always sees their own residency; that is the
        // same rule FR-06 states for the card.
        if ($user->getKey() === $residency->user_id) {
            return true;
        }

        return $this->decideOnBuilding($user, $residency, view: true);
    }

    /**
     * FR-05: recording the end of a residency.
     */
    public function terminate(User $user, Residency $residency): bool
    {
        return $this->decideOnBuilding($user, $residency, view: false);
    }

    private function decideOnBuilding(User $user, Residency $residency, bool $view): bool
    {
        $building = $residency->bed()->with('room.building')->first()?->room?->building;

        if ($building === null) {
            return false;
        }

        return $view
            ? $this->buildings->viewRooms($user, $building)
            : $this->buildings->manageResidencies($user, $building);
    }
}

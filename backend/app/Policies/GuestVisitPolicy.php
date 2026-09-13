<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Building;
use App\Models\GuestVisit;
use App\Models\User;

/**
 * FR-19 and FR-21 over one visit.
 *
 * **Recording the exit and correcting the record are different rights.** The
 * post records the exit: that is the officer's own work at the desk and
 * `checkOut` asks for the checkpoint capability. A correction is not work at
 * the desk — it is the register saying that something it already asserts is
 * wrong — so it asks for the register capability, which §3.9.6 gives to the
 * administrator and the warden of the building. An officer who mistyped an
 * exit time cannot quietly put it right; they report it, and the correcting
 * entry carries the name of whoever wrote it.
 */
final class GuestVisitPolicy
{
    public function __construct(private readonly BuildingPolicy $buildings) {}

    public function checkOut(User $user, GuestVisit $visit): bool
    {
        $building = $this->buildingOf($visit);

        return $building !== null && $this->buildings->operateCheckpoint($user, $building);
    }

    /**
     * FR-21, second criterion: the correcting entry.
     */
    public function correct(User $user, GuestVisit $visit): bool
    {
        $building = $this->buildingOf($visit);

        return $building !== null && $this->buildings->viewVisitRegister($user, $building);
    }

    private function buildingOf(GuestVisit $visit): ?Building
    {
        $request = $visit->relationLoaded('request')
            ? $visit->request
            : $visit->request()->with('building')->first();

        return $request?->building ?? $request?->building()->first();
    }
}

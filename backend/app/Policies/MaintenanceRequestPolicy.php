<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Building;
use App\Models\MaintenanceRequest;
use App\Models\User;

/**
 * FR-36 … FR-40 over one request.
 *
 * Every method reaches the building the request names and asks
 * `BuildingPolicy` about it, so the horizontal boundary of FR-07 is enforced
 * in the one place that has ever enforced it — the `building_id` of a role
 * grant. The policy names no role anywhere (§3.3.3): it asks for a capability,
 * `RoleCode::permissions()` says which roles carry it, and the agreement that
 * the warden and the manager triage while the administrator, who reads every
 * queue of every dormitory, does not is stated once, in the capability map.
 *
 * **`confirm` is the method the module exists for and it names no capability
 * at all.** §3.5.2: «the request is closed by the person who reported it, not
 * by the person who fixed it». A capability would be the wrong instrument —
 * this is not a right some role holds over a class of objects, it is the fact
 * that one particular person filed one particular request. So the test is an
 * identity comparison, and it is the same test `cancel` makes in
 * `GuestRequestPolicy` for the same reason.
 */
final class MaintenanceRequestPolicy
{
    public function __construct(private readonly BuildingPolicy $buildings) {}

    /**
     * FR-36: who may file one, and about which dormitory.
     *
     * Reached as `$user->can('create', [MaintenanceRequest::class, $building])`.
     * The building is an argument rather than something read off the request,
     * because at the moment of this question there is no request yet — which
     * is also why it cannot be answered by the methods below.
     */
    public function create(User $user, Building $building): bool
    {
        return $this->buildings->submitMaintenanceRequest($user, $building);
    }

    /**
     * The resident who filed it always sees it; the staff of the building see
     * it if their capability covers the queue.
     */
    public function view(User $user, MaintenanceRequest $request): bool
    {
        if ($user->getKey() === $request->reporter_id) {
            return true;
        }

        $building = $this->buildingOf($request);

        return $building !== null && $this->buildings->viewMaintenanceRequests($user, $building);
    }

    /**
     * FR-37, FR-38: accept, reject, start, complete. The warden or the manager
     * of **this** building.
     */
    public function triage(User $user, MaintenanceRequest $request): bool
    {
        $building = $this->buildingOf($request);

        return $building !== null && $this->buildings->triageMaintenanceRequests($user, $building);
    }

    /**
     * FR-39: confirming the work, or saying it was not done.
     *
     * The reporter, and nobody else — not the warden who accepted it, not the
     * manager who marked it complete, not the administrator. A warden who
     * believes a resident is unreasonable does not close the request over
     * their head; the confirmation window runs out and the request closes
     * marked as automatically closed, which is a different and honest record.
     */
    public function confirm(User $user, MaintenanceRequest $request): bool
    {
        return $user->getKey() === $request->reporter_id;
    }

    private function buildingOf(MaintenanceRequest $request): ?Building
    {
        return $request->relationLoaded('building')
            ? $request->building
            : $request->building()->first();
    }
}

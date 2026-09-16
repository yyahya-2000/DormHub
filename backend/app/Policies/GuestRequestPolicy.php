<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Building;
use App\Models\GuestRequest;
use App\Models\User;

/**
 * FR-16 and FR-17 over one request.
 *
 * Every method here reaches the building the request names and asks
 * `BuildingPolicy` about it, so the horizontal boundary of FR-07 is enforced
 * in the one place that has ever enforced it — the `building_id` of a role
 * grant. A duty officer of block 1 with the identifier of a request in block 2
 * is refused here, before any service is reached, and the refusal is written
 * to the audit log as `access.denied` by the handler in bootstrap/app.php.
 *
 * The policy names no role anywhere (§3.3.3). It asks `BuildingPolicy`, which
 * asks for a capability, which `RoleCode::permissions()` answers — so who
 * decides on a request is stated once, in the capability map, and not repeated
 * in four methods that could drift apart. Revision 3 of the guest module
 * (16.09.2026) widened that set from the duty officer to the duty officer, the
 * warden and the manager, and this class was not edited for it, which is the
 * argument for writing it this way.
 */
final class GuestRequestPolicy
{
    public function __construct(private readonly BuildingPolicy $buildings) {}

    /**
     * FR-16: who may submit one, and for which dormitory.
     *
     * Reached as `$user->can('create', [GuestRequest::class, $building])`. The
     * building is an argument rather than something read off the request,
     * because at the moment of this question there is no request yet — which
     * is also why it cannot be answered by the methods below.
     */
    public function create(User $user, Building $building): bool
    {
        return $this->buildings->submitGuestRequest($user, $building);
    }

    /**
     * The resident who submitted it always sees it; the staff of the building
     * see it if their capability covers the queue.
     */
    public function view(User $user, GuestRequest $request): bool
    {
        if ($user->getKey() === $request->student_id) {
            return true;
        }

        $building = $this->buildingOf($request);

        return $building !== null && $this->buildings->viewGuestRequests($user, $building);
    }

    /**
     * FR-17: approve or reject, inside **this** building and no other.
     *
     * Three roles carry the capability since revision 3 of the guest module —
     * the duty officer, the warden and the manager — and the building of the
     * grant is what separates them from the same three roles next door.
     */
    public function decide(User $user, GuestRequest $request): bool
    {
        $building = $this->buildingOf($request);

        return $building !== null && $this->buildings->decideGuestRequests($user, $building);
    }

    /**
     * The author withdraws their own request, and nobody else does.
     *
     * A warden who wants a visit stopped refuses it, which is a decision with
     * a reason attached and an author on the row. Letting staff cancel would
     * produce a closed request that says the resident changed their mind.
     */
    public function cancel(User $user, GuestRequest $request): bool
    {
        return $user->getKey() === $request->student_id;
    }

    private function buildingOf(GuestRequest $request): ?Building
    {
        return $request->relationLoaded('building')
            ? $request->building
            : $request->building()->first();
    }
}

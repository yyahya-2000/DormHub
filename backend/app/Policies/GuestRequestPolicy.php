<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Building;
use App\Models\GuestRequest;
use App\Models\User;

/**
 * FR-16, FR-17 and FR-23 over one request.
 *
 * Every method here reaches the building the request names and asks
 * `BuildingPolicy` about it, so the horizontal boundary of FR-07 is enforced
 * in the one place that has ever enforced it — the `building_id` of a role
 * grant. A duty officer of block 1 with the identifier of a request in block 2
 * is refused here, before any service is reached, and the refusal is written
 * to the audit log as `access.denied` by the handler in bootstrap/app.php.
 *
 * The policy names no role anywhere (§3.3.3). It asks `BuildingPolicy`, which
 * asks for a capability, which `RoleCode::permissions()` answers — so the
 * agreement that the duty officer decides and the warden does not is stated
 * once, in the capability map, and not repeated in four methods that could
 * drift apart.
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
     * FR-17: approve or reject. The duty officer of **this** building.
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

    /**
     * NFR-06: unmasking the document number.
     *
     * Narrower than reading the request itself, and narrower than the post's
     * own access. At the desk the document is in the officer's hand and the
     * last four characters are what a comparison needs; asking the system for
     * the number in full is a different act with a different purpose, and it
     * leaves a row in the log.
     */
    public function viewDocumentNumber(User $user, GuestRequest $request): bool
    {
        $building = $this->buildingOf($request);

        return $building !== null
            && $user->hasPermissionInBuilding(Permission::ViewGuestDocument, $building);
    }

    private function buildingOf(GuestRequest $request): ?Building
    {
        return $request->relationLoaded('building')
            ? $request->building
            : $request->building()->first();
    }
}

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\RoleCode;
use App\Models\Building;
use App\Models\User;

/**
 * FR-07, the object half of it.
 *
 * Every method here receives the building and decides on it. None of them asks
 * «is this user a warden»; each asks «may this user do this **in this
 * building**», which is the distinction §3.3.3 draws and the reason §3.4.1
 * puts `building_id` on the grant. A method that answered the first question
 * would pass its own tests and still let the warden of block 1 read block 2.
 *
 * The capability rather than the role is what the methods name, and that is
 * the second half of the same warning. Revision 2 of the role model added the
 * building manager beneath the warden with the whole of the warden's operative
 * work and none of his appointments; phrased as a list of roles, that addition
 * would have meant editing every method below and hoping none was missed.
 * `RoleCode::permissions()` carries it instead, and this class did not have to
 * learn the new role's name.
 */
final class BuildingPolicy
{
    /**
     * The card itself. Anyone attached to the building may read it, along with
     * the administrator, whose grant names no building and therefore covers
     * all of them.
     */
    public function view(User $user, Building $building): bool
    {
        if ($user->hasPermissionInBuilding(Permission::ViewBuilding, $building)) {
            return true;
        }

        /*
         * A resident reaches the card of the dormitory they live in, and FR-05
         * adds the word «live». Once the register records that the person has
         * moved out, the building-bound functions close on the stated date and
         * not later, and the card is one of them. A resident the register has
         * never heard of keeps the access their grant gives them: absence of a
         * residency row is not eviction, it is silence.
         *
         * This is the one access in the system that a capability cannot state,
         * because it is not a property of the role at all — it is a question
         * put to the residency register, and it is asked here.
         */
        if ($user->hasRoleInBuilding(RoleCode::Resident, $building)) {
            return ! $user->hasMovedOutOf($building);
        }

        return false;
    }

    /**
     * The register of dormitories, listed. Everyone sees their own scope; the
     * administrator's grant names no building and so covers all of them. The
     * filtering itself is the service's business (`BuildingRegistry::visibleTo`),
     * and this method only says that an authenticated account may ask.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * FR-01, first criterion: create, edit and archive belong to the
     * administrator and to nobody else. Three methods rather than one, because
     * the gate is asked by name and a single `manage` would blur which of the
     * three a route actually needs.
     *
     * These four stay stated as the role and not as a capability on purpose.
     * The register of dormitories is not work done **inside** a building, so
     * there is no building to scope the question to; the administrator is the
     * answer, and the manager beneath the warden must never become one.
     */
    public function create(User $user): bool
    {
        return $user->isAdministrator();
    }

    public function update(User $user, Building $building): bool
    {
        return $user->isAdministrator();
    }

    public function archive(User $user, Building $building): bool
    {
        return $user->isAdministrator();
    }

    public function delete(User $user, Building $building): bool
    {
        return $user->isAdministrator();
    }

    /**
     * FR-02: the register of rooms and places of this building. The warden and
     * the manager keep it, the duty officer reads it to know which room a
     * guest is bound for, and the administrator sees every building. A
     * resident does not: the occupancy of the whole dormitory is not theirs to
     * read.
     */
    public function viewRooms(User $user, Building $building): bool
    {
        return $user->hasPermissionInBuilding(Permission::ViewRooms, $building);
    }

    /**
     * Keeping the register — rooms and places — is the operative work of the
     * building (FR-02), inside that building. Revision 2 of the role model
     * puts it with the manager and leaves it with the warden as well.
     */
    public function manageRooms(User $user, Building $building): bool
    {
        return $user->hasPermissionInBuilding(Permission::ManageRooms, $building);
    }

    /**
     * FR-03 and FR-05: moving people in and out. The same circle as the
     * register above, and for the same reason — a residency is an entry in it.
     */
    public function manageResidencies(User $user, Building $building): bool
    {
        return $user->hasPermissionInBuilding(Permission::ManageResidencies, $building);
    }

    /**
     * The people attached to the building. This is personal data, so the
     * circle is narrower than for the card: the administrator, and the warden,
     * manager or duty officer of this building. A resident of the building
     * sees the card and not the roll.
     */
    public function viewPeople(User $user, Building $building): bool
    {
        return $user->hasPermissionInBuilding(Permission::ViewPeople, $building);
    }

    /**
     * FR-42: creating an account for an incoming resident of this building.
     */
    public function issueResidentAccount(User $user, Building $building): bool
    {
        return $user->hasPermissionInBuilding(Permission::IssueResidentAccount, $building);
    }

    /**
     * FR-41: granting or revoking one staff role in this building.
     *
     * The role being handed out is part of the question, not a detail checked
     * afterwards. «May this account appoint here» and «may it appoint *this*»
     * have different answers for the same warden — manager yes, warden no —
     * and splitting them into two checks is how one of the two gets forgotten.
     * `User::mayGrantInBuilding()` answers both at once.
     */
    public function appointStaff(User $user, Building $building, RoleCode $role): bool
    {
        return $user->mayGrantInBuilding($role, $building);
    }

    /**
     * FR-16, FR-17: the queue of guest requests of this dormitory.
     *
     * Reading the queue and deciding on it are two capabilities and two
     * methods. The manager reads it because a request names a room and the
     * rooms are his; only the duty officer decides.
     */
    public function viewGuestRequests(User $user, Building $building): bool
    {
        return $user->hasPermissionInBuilding(Permission::ViewGuestRequests, $building);
    }

    /**
     * FR-17: approving and refusing.
     *
     * The check §4.7.2 singles out runs through this one line: a resident
     * holds no such capability at all, and a duty officer of another building
     * holds it in that building and not in this one. Both are 403, and both
     * are recorded as `access.denied` by the handler.
     */
    public function decideGuestRequests(User $user, Building $building): bool
    {
        return $user->hasPermissionInBuilding(Permission::DecideGuestRequests, $building);
    }

    /**
     * FR-18, FR-19: working the security post of this dormitory.
     */
    public function operateCheckpoint(User $user, Building $building): bool
    {
        return $user->hasPermissionInBuilding(Permission::OperateCheckpoint, $building);
    }

    /**
     * FR-21 and §3.9.6: the visitor register, read and exported. «Available to
     * the administrator and to the warden of the building concerned».
     */
    public function viewVisitRegister(User $user, Building $building): bool
    {
        return $user->hasPermissionInBuilding(Permission::ViewVisitRegister, $building);
    }

    /**
     * FR-16: submitting a guest request for this dormitory.
     *
     * Decided against the residency register and not against a capability, for
     * the same reason `view()` above is: living somewhere is not a role. The
     * word that matters is «today» — FR-05 closes the building-bound functions
     * on the stated departure date, and inviting a guest into a dormitory one
     * no longer lives in is exactly such a function.
     */
    public function submitGuestRequest(User $user, Building $building): bool
    {
        return $user->residesIn($building);
    }
}

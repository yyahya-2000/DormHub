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
     * FR-01, first criterion: create, edit and delete belong to the
     * administrator and to nobody else. Three methods rather than one, because
     * the gate is asked by name and a single `manage` would blur which of the
     * three a route actually needs.
     *
     * These three stay stated as the role and not as a capability on purpose.
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

    public function delete(User $user, Building $building): bool
    {
        return $user->isAdministrator();
    }

    /**
     * FR-02: the register of rooms and places of this building. The warden and
     * the manager keep it and therefore read it, and the administrator sees
     * every building. A resident does not: the occupancy of the whole
     * dormitory is not theirs to read.
     *
     * Reading stays a capability of its own although every holder of it also
     * holds `ManageRooms` since revision 4 of the role model. The two name two
     * different pieces of work, and the method below asks for the narrower.
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
     * circle is narrower than for the card: the administrator, and the warden
     * or the manager of this building. A resident of the building sees the
     * card and not the roll.
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
     * FR-09: publishing an announcement into this dormitory.
     *
     * The warden, the manager beneath him and the administrator — the same
     * circle as the register work, because §1.1.4's revision 2 puts
     * announcements with it. An announcement addressed to *every* dormitory is
     * not decided here at all: it names no building for this method to receive,
     * and `AnnouncementPolicy::publish` keeps it to the administrator.
     */
    public function publishAnnouncements(User $user, Building $building): bool
    {
        return $user->hasPermissionInBuilding(Permission::PublishAnnouncements, $building);
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
     * methods. The administrator reads the queue of every dormitory and
     * decides in none of them, which is the one place the two questions still
     * come apart.
     */
    public function viewGuestRequests(User $user, Building $building): bool
    {
        return $user->hasPermissionInBuilding(Permission::ViewGuestRequests, $building);
    }

    /**
     * FR-17: approving and refusing.
     *
     * The check §4.7.2 singles out runs through this one line: a resident
     * holds no such capability at all, and a manager of another building holds
     * it in that building and not in this one. Both are 403, and both are
     * recorded as `access.denied` by the handler.
     *
     * The same line carried revision 3 of the guest module, which gave the
     * capability to the warden and the manager beside the duty officer, and
     * revision 4 of the role model, which removed the duty officer again.
     * Neither edited this method. It is the building of the grant that keeps
     * FR-07 shut, and moving roles in and out of the set does not loosen it: a
     * manager of block A asking about a request of block B holds the
     * capability in block A and is refused here.
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
     * FR-40: the maintenance queue of this dormitory.
     *
     * Read and triage are two methods for two capabilities, and the pair is
     * the one the horizontal-access matrix is checked on in
     * `MaintenanceQueueTest`: a warden of block 1 holds both in block 1 and
     * neither in block 2, so a request of block 2 is invisible to him at the
     * API and not merely in the interface.
     */
    public function viewMaintenanceRequests(User $user, Building $building): bool
    {
        return $user->hasPermissionInBuilding(Permission::ViewMaintenanceRequests, $building);
    }

    /**
     * FR-37, FR-38: accepting, refusing and working a request of this
     * dormitory. The warden and the manager beneath him; not the
     * administrator, who reads the queue without triaging it, and never the
     * resident who filed it.
     */
    public function triageMaintenanceRequests(User $user, Building $building): bool
    {
        return $user->hasPermissionInBuilding(Permission::TriageMaintenanceRequests, $building);
    }

    /**
     * FR-36: filing a maintenance request about this dormitory.
     *
     * Decided against the residency register and not against a capability, for
     * the same reason `submitGuestRequest()` below is: living somewhere is not
     * a role. FR-36's acceptance criterion — «a resident without an active
     * residency record cannot submit» — is this one line, and it is a line in
     * a policy rather than a rule in a form because it is a question about the
     * register and not about the input.
     */
    public function submitMaintenanceRequest(User $user, Building $building): bool
    {
        return $user->residesIn($building);
    }

    /**
     * FR-24, FR-26: taking a found object into the administration's
     * safekeeping in this dormitory, and answering the claims made against an
     * entry it holds.
     *
     * The security officer, the warden and the manager. It is the narrower of
     * the module's two staff capabilities and the only one the post holds: an
     * object picked up in a corridor is handed in at the desk, and handing it
     * back is the same job.
     *
     * Nothing here touches the ordinary path of the module. A find that stays
     * with the resident who found it is published, claimed and settled without
     * this method being reached at all (§2.5.4).
     */
    public function holdLostFoundItems(User $user, Building $building): bool
    {
        return $user->hasPermissionInBuilding(Permission::HoldLostFoundItems, $building);
    }

    /**
     * FR-26: deciding a claim the finder and the claimant could not settle, in
     * this dormitory.
     *
     * The warden and the manager beneath him. Not the security officer, who
     * keeps objects and settles nothing, and not the administrator, for the
     * reason they are outside `decideGuestRequests()`: a disagreement between
     * two residents about one umbrella is settled by somebody who can see both
     * of them.
     */
    public function decideLostFoundDisputes(User $user, Building $building): bool
    {
        return $user->hasPermissionInBuilding(Permission::DecideLostFoundDisputes, $building);
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

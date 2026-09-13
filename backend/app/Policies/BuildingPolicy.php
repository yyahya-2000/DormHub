<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\Building;
use App\Models\User;

/**
 * FR-07, the object half of it.
 *
 * Every method here receives the building and decides on it. None of them asks
 * «is this user a warden»; each asks «is this user the warden **of this
 * building**», which is the distinction §3.3.3 draws and the reason §3.4.1
 * puts `building_id` on the grant. A method that answered the first question
 * would pass its own tests and still let the warden of block 1 read block 2.
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
        if ($user->isAdministrator()) {
            return true;
        }

        foreach ([RoleCode::Warden, RoleCode::DutyOfficer, RoleCode::SecurityOfficer] as $role) {
            if ($user->hasRoleInBuilding($role, $building)) {
                return true;
            }
        }

        /*
         * A resident reaches the card of the dormitory they live in, and FR-05
         * adds the word «live». Once the register records that the person has
         * moved out, the building-bound functions close on the stated date and
         * not later, and the card is one of them. A resident the register has
         * never heard of keeps the access their grant gives them: absence of a
         * residency row is not eviction, it is silence.
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
     * FR-02: the register of rooms and places of this building. The warden
     * keeps it, the duty officer reads it to know which room a guest is bound
     * for, and the administrator sees every building. A resident does not: the
     * occupancy of the whole dormitory is not theirs to read.
     */
    public function viewRooms(User $user, Building $building): bool
    {
        return $user->isAdministrator()
            || $user->hasRoleInBuilding(RoleCode::Warden, $building)
            || $user->hasRoleInBuilding(RoleCode::DutyOfficer, $building);
    }

    /**
     * Keeping the register — rooms and places — is the warden's own work
     * (FR-02), inside the warden's own building.
     */
    public function manageRooms(User $user, Building $building): bool
    {
        return $user->isAdministrator()
            || $user->hasRoleInBuilding(RoleCode::Warden, $building);
    }

    /**
     * FR-03 and FR-05: moving people in and out. The same circle as the
     * register above, and for the same reason — a residency is an entry in it.
     */
    public function manageResidencies(User $user, Building $building): bool
    {
        return $this->manageRooms($user, $building);
    }

    /**
     * The people attached to the building. This is personal data, so the
     * circle is narrower than for the card: the administrator, and the warden
     * or duty officer of this building. A resident of the building sees the
     * card and not the roll.
     */
    public function viewPeople(User $user, Building $building): bool
    {
        return $user->isAdministrator()
            || $user->hasRoleInBuilding(RoleCode::Warden, $building)
            || $user->hasRoleInBuilding(RoleCode::DutyOfficer, $building);
    }
}

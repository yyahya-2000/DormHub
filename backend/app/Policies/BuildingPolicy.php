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

        foreach ([RoleCode::Warden, RoleCode::DutyOfficer, RoleCode::SecurityOfficer, RoleCode::Resident] as $role) {
            if ($user->hasRoleInBuilding($role, $building)) {
                return true;
            }
        }

        return false;
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

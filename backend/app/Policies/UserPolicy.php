<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Services\ResidentDirectory;

/**
 * FR-06, «Resident card», and with it the continuation of FR-07 onto personal
 * data.
 *
 * The criterion reads: the card is visible to the warden **of that building**
 * and to the administrator, and a resident sees only their own. Revision 2 of
 * the role model adds the manager of that building to the first group, on the
 * same footing and by the same argument: the card is the register work FR-42
 * hands him. The middle phrase is the whole difficulty. A method that asked
 * «is this user a warden»
 * would pass a test written around one building and let the warden of block 1
 * read the card of a resident of block 2 — the exact violation §3.3.3 warns
 * about and §4.7.2 devotes a test to.
 *
 * So the question is asked over the object: which buildings is this card
 * attached to, and does the viewer hold a role in one of them. The set comes
 * from `ResidentDirectory`, which reads it from the residency register and the
 * role grants rather than from anything the request carries.
 */
final readonly class UserPolicy
{
    public function __construct(private ResidentDirectory $residents) {}

    public function viewCard(User $user, User $resident): bool
    {
        // «A resident sees only their own card» — the own half of it.
        if ($user->is($resident)) {
            return true;
        }

        if ($user->isAdministrator()) {
            return true;
        }

        foreach ($this->residents->buildingIdsOf($resident) as $buildingId) {
            if ($this->staffOf($user, $buildingId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The warden and the manager of the building keep the register and
     * therefore read the card. The duty officer decides guest requests naming
     * the resident and their room, so the card is within their work as well;
     * the security officer's is the entrance, not the register, and stops at
     * the building card of FR-07.
     *
     * Which of them that is, is not written here. The method asks for the
     * capability and `RoleCode::permissions()` answers, so the arrival of the
     * building manager cost this class nothing.
     */
    private function staffOf(User $user, int $buildingId): bool
    {
        return $user->hasPermissionInBuilding(Permission::ViewResidentCard, $buildingId);
    }
}

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
 * hands him. Nobody beyond that circle, and the circle has been drawn wrong
 * once: acceptance found the duty officer inside it, which the criterion does
 * not allow and which the contract for this route does not describe, and the
 * capability was struck from that role. Revision 4 of the role model
 * (21.09.2026) then merged the duty officer into the manager, so the accounts
 * that held the one grant are inside the circle again — as managers this time,
 * which the criterion does allow. The middle phrase is the whole difficulty. A
 * method that asked «is this user a warden»
 * would pass a test written around one building and let the warden of block 1
 * read the card of a resident of block 2 — the exact violation §3.3.3 warns
 * about and §4.7.2 devotes a test to.
 *
 * So the question is asked over the object: which buildings is this card
 * attached to, and does the viewer hold a role in one of them. The set comes
 * from `ResidentDirectory`, which reads it from the residency register and the
 * resident grant rather than from anything the request carries.
 *
 * **Not from a staff grant** — see the docblock of `ResidentDirectory`. Until
 * the acceptance of 14.09.2026 every grant counted, and a warden could
 * manufacture the attachment he was then judged against by appointing the
 * person he wanted to read. The set this method walks is now the buildings the
 * person *lives* in, and nobody can write into it from the staff route.
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
     * therefore read the card. Nobody else attached to the building does: the
     * security officer's work is the entrance, and the entrance needs nobody's
     * citizenship or telephone.
     *
     * Which of them that is, is not written here. The method asks for the
     * capability and `RoleCode::permissions()` answers, so the arrival of the
     * building manager cost this class nothing — and neither did the
     * withdrawal of the duty officer's access, nor the removal of that role
     * altogether. All three were edits to the capability map, made without
     * touching a policy.
     */
    private function staffOf(User $user, int $buildingId): bool
    {
        return $user->hasPermissionInBuilding(Permission::ViewResidentCard, $buildingId);
    }
}

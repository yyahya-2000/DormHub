<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LostFoundClaim;
use App\Models\LostFoundItem;
use App\Models\User;

/**
 * FR-26 over one claim.
 *
 * Every method reaches the entry the claim is made against and asks
 * `LostFoundItemPolicy` about it, because every question here is really a
 * question about the object: who is holding it decides the claim, and the
 * dormitory it belongs to decides which warden may be asked to review the
 * refusal. Duplicating either answer would give the module two ideas of whose
 * umbrella it is.
 *
 * **`refer` names no capability and no role, and that is the whole of
 * §2.4.4's «the claimant is offered the option».** The referral is the
 * claimant's own act; it is not a right anybody holds over a class of objects,
 * it is the fact that one particular person filed one particular claim. So the
 * test is an identity comparison, the same one
 * `MaintenanceRequestPolicy::confirm` makes for FR-39.
 */
final class LostFoundClaimPolicy
{
    public function __construct(private readonly LostFoundItemPolicy $items) {}

    /**
     * Who reads a claim: the person who filed it, the person holding the
     * object, and the warden who may be asked to decide it.
     *
     * The list of claims on an entry is a different question and is asked of
     * the entry — a resident scrolling the feed sees how many claims a find
     * carries and never what any of them says, because the identifying marks
     * are the one thing that makes a claim checkable and a published list of
     * them would tell the next claimant exactly what to write.
     */
    public function view(User $user, LostFoundClaim $claim): bool
    {
        if ((int) $claim->claimant_id === (int) $user->getKey()) {
            return true;
        }

        $item = $this->itemOf($claim);

        if ($item === null) {
            return false;
        }

        return $this->items->decideClaims($user, $item)
            || $this->items->judge($user, $item);
    }

    /**
     * FR-26: accepting or declining. The person holding the object, and nobody
     * else — §2.5.4's default path, where no member of staff is involved at
     * all.
     */
    public function decide(User $user, LostFoundClaim $claim): bool
    {
        $item = $this->itemOf($claim);

        return $item !== null && $this->items->decideClaims($user, $item);
    }

    /**
     * FR-26: «a claim the two sides cannot settle is referred to the warden».
     *
     * The claimant's own act. Whether *this* claim can be referred at all — it
     * was declined, and it has not been referred before — is a fact about the
     * row rather than about the account, so it is asserted in
     * `LostFoundService::refer()` and comes back as a 409 naming both ends,
     * not as a 403. A person asking to refer their own refused claim is asking
     * a question they are entitled to ask; the answer may still be that there
     * is nothing left to refer.
     */
    public function refer(User $user, LostFoundClaim $claim): bool
    {
        return (int) $claim->claimant_id === (int) $user->getKey();
    }

    /**
     * FR-26, first criterion: «a warden's decision on a referred claim».
     *
     * The warden or the manager of the dormitory the entry belongs to, and
     * never the claimant.
     *
     * **The exclusion of the claimant used to be asserted here and enforced
     * nowhere** (acceptance of 15.09.2026). The docblock said they were
     * outside the circle «by construction» and the contract for
     * `POST /lost-found/claims/{id}/decision` says «not the claimant» in so
     * many words; the method asked `LostFoundItemPolicy::judge`, which asks
     * only whether the account holds the capability in that dormitory. So a
     * manager could claim a find, wait for the finder to refuse, refer his own
     * refusal to himself and uphold it — `claimant_id == decided_by` on the
     * row, and every step of it recorded as ordinary work.
     *
     * A dispute is settled by somebody who is not a party to it; that is the
     * whole of what makes the warden's decision worth anything. The refusal is
     * a 403 and not a 409, because what is wrong is the account and not the
     * state of the claim: the same claim is perfectly decidable — by any other
     * warden or manager of that building.
     */
    public function judge(User $user, LostFoundClaim $claim): bool
    {
        if ((int) $claim->claimant_id === (int) $user->getKey()) {
            return false;
        }

        $item = $this->itemOf($claim);

        return $item !== null && $this->items->judge($user, $item);
    }

    private function itemOf(LostFoundClaim $claim): ?LostFoundItem
    {
        return $claim->relationLoaded('item')
            ? $claim->item
            : $claim->item()->first();
    }
}

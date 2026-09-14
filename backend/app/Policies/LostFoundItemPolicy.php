<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\LostFoundItemStatus;
use App\Models\Building;
use App\Models\LostFoundItem;
use App\Models\User;
use App\Services\LostFoundFeed;

/**
 * FR-24, FR-25 and FR-26 over one entry.
 *
 * **The module is peer-to-peer and this class is the shortest statement of
 * what that means** (§2.5.4). Publishing is open to anybody who lives in the
 * dormitory — no capability, because living somewhere is not a role — and
 * answering a claim belongs to whoever is holding the object, which is the
 * publisher on the ordinary path. A capability appears in exactly two methods,
 * and they are §2.5.4's two exceptions: an object deposited with the
 * administration, and a claim referred as disputed.
 *
 * The policy names no role anywhere (§3.3.3): it asks for a capability,
 * `RoleCode::permissions()` says which roles carry it, and the agreement that
 * the security post takes objects in while the warden settles disagreements is
 * stated once, in the capability map.
 *
 * **`view` and the feed ask one question through one object.** `LostFoundFeed`
 * computes which dormitories an account reads, and this class asks it rather
 * than rebuilding the answer — a second definition of «my dormitory» would be
 * the one that let a moved-out resident keep reading the feed of a building
 * they had left.
 */
final class LostFoundItemPolicy
{
    public function __construct(
        private readonly BuildingPolicy $buildings,
        private readonly LostFoundFeed $feed,
    ) {}

    /**
     * FR-24: who may publish, and about which dormitory.
     *
     * Reached as `$user->can('create', [LostFoundItem::class, $building])`.
     * Two answers, because FR-24 names two kinds of publisher: «the resident
     * who found the item publishes it» and «staff — security officer or warden
     * — publish on the same form for items handed in at the post».
     *
     * The resident half is decided against the residency register and not
     * against a capability, for the same reason `submitMaintenanceRequest()`
     * is: living somewhere is not a role, and FR-05 closes the building-bound
     * functions on the stated departure date.
     */
    public function create(User $user, Building $building): bool
    {
        return $user->residesIn($building)
            || $this->buildings->holdLostFoundItems($user, $building);
    }

    /**
     * FR-24, the deposited path: publishing an entry that says the object is
     * with the administration.
     *
     * A separate question from `create`, and the separation is the point. A
     * resident may publish; a resident may not publish an entry asserting that
     * the administration is holding something, because the assertion decides
     * who answers every claim that follows (§2.5.4) and because the record is
     * the university's statement about an object in its keeping — Civil Code
     * art. 227 cl. 1 para. 2 — rather than the finder's about one in theirs.
     */
    public function deposit(User $user, Building $building): bool
    {
        return $this->buildings->holdLostFoundItems($user, $building);
    }

    /**
     * FR-25: the card.
     *
     * Anybody attached to the dormitory reads it, which is what the feed
     * already shows them. The person who published it reads it in any state —
     * including after closure, when it has left the feed — because it is
     * theirs. A claimant reads it for as long as their claim is on it, because
     * a claim they cannot see the object of is a claim they cannot follow.
     *
     * What none of them reads is who published it: that is
     * `LostFoundItemResource`'s doing, and it is a separate mechanism on
     * purpose (§4.6.2). This method decides *whether* the card is returned and
     * the resource decides *what is in it*, so widening one cannot widen the
     * other.
     */
    public function view(User $user, LostFoundItem $item): bool
    {
        if ((int) $item->reporter_id === (int) $user->getKey()) {
            return true;
        }

        if ($item->claims()->where('claimant_id', $user->getKey())->exists()) {
            return true;
        }

        return $this->feed->reaches($user, $item);
    }

    /**
     * FR-26: filing a claim.
     *
     * Anybody the feed reaches, except the person whose entry it is — that
     * refusal is a 422 from `StoreLostFoundClaimRequest` rather than a 403,
     * because what is wrong is the object the request names and not the
     * account that named it, and the client needs to be told which.
     *
     * A closed entry is refused here too rather than left to the state
     * machine. The machine would catch it — `resolved → claimed` is not a move
     * — but it would catch it after the claim row had been drafted, and «you
     * cannot claim something that has gone home» is a fact about the entry
     * that the client can be told before it composes a message.
     */
    public function claim(User $user, LostFoundItem $item): bool
    {
        if ($item->status === LostFoundItemStatus::Resolved) {
            return false;
        }

        return $this->feed->reaches($user, $item);
    }

    /**
     * FR-26: reading the claims made against this entry.
     *
     * The person who has to answer them, and the warden who may be asked to
     * review one. Not the rest of the dormitory: the card carries how many
     * claims there are, and the identifying marks on each are what makes a
     * claim checkable — a list of them readable by everybody would tell the
     * next claimant exactly what to write.
     */
    public function viewClaims(User $user, LostFoundItem $item): bool
    {
        return $this->decideClaims($user, $item) || $this->judge($user, $item);
    }

    /**
     * FR-26: answering a claim on this entry — accepting it or declining it.
     *
     * **§2.5.4 in one method.** On the ordinary path the object is with the
     * person who published the entry and the answer is theirs; no member of
     * staff is involved and no capability is consulted. On the deposited path
     * the object is in the administration's keeping and the answer belongs to
     * the staff holding it, as a circle rather than as the one officer who
     * happened to be on the desk.
     *
     * The warden is **not** here. A claim the two sides cannot settle reaches
     * a warden through a referral and through nothing else, which is
     * `judge()`; a warden who could answer any claim would be the moderation
     * step the module was built to do without.
     */
    public function decideClaims(User $user, LostFoundItem $item): bool
    {
        if ($item->isDecidedBy($user)) {
            return true;
        }

        if ($item->custody?->restsWithTheAdministration() !== true) {
            return false;
        }

        $building = $this->buildingOf($item);

        return $building !== null && $this->buildings->holdLostFoundItems($user, $building);
    }

    /**
     * FR-26: «marks the item returned».
     *
     * The same circle as the answer above, and deliberately no wider. The
     * closure records that an object changed hands, and the only person who
     * can know that it did is the person who handed it over. A warden who
     * upheld a referred claim has made that closure *admissible* — FR-26's
     * first criterion — and has not performed it.
     */
    public function resolve(User $user, LostFoundItem $item): bool
    {
        return $this->decideClaims($user, $item);
    }

    /**
     * FR-26, the first of §2.5.4's two exceptions: deciding a claim the finder
     * and the claimant could not settle.
     *
     * The warden or the manager of **this** dormitory. Not the administrator,
     * not the security officer, and not the person holding the object — whose
     * refusal is what is being reviewed.
     */
    public function judge(User $user, LostFoundItem $item): bool
    {
        $building = $this->buildingOf($item);

        return $building !== null && $this->buildings->decideLostFoundDisputes($user, $building);
    }

    private function buildingOf(LostFoundItem $item): ?Building
    {
        return $item->relationLoaded('building')
            ? $item->building
            : $item->building()->first();
    }
}

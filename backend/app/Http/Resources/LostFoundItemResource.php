<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LostFoundItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One find as the dormitory reads it (FR-24, FR-25, FR-26).
 *
 * **There is no `reporter_id` here, no `reporter_name`, no telephone and no
 * e-mail, and the omission is FR-25's second acceptance criterion**: «the find
 * card displays neither the name nor the contacts of the registering user».
 *
 * **The omission lives in this class and not in the query, which is the whole
 * of §4.6.2's argument.** `LostFoundService` needs `reporter_id` to answer
 * «whose decision is this», and `LostFoundItemPolicy` needs it to let the
 * publisher read their own closed entry — so a query that did not select it
 * would break the module, and a query that selected it conditionally would
 * break the first time somebody added an eager load. A Resource that never
 * serialises the column cannot leak it through a later change to the query,
 * and the test named in §4.6.2 asserts the field's absence from the body.
 *
 * The reason is not privacy in the abstract. §3.5.3: the exchange runs through
 * claims inside the system, «otherwise the feed degenerates into an open list
 * of residents' phone numbers» — a dormitory's feed is read by several hundred
 * people, and a name beside every entry is a directory of who found what and
 * lives where.
 *
 * **`can_claim` is a fact and not a permission.** It says whether *this
 * account* is in a position to claim this entry at all — it is not their own,
 * it is a find rather than a loss, and it has not gone home — so the client
 * draws the button from the same three facts the form request would refuse it
 * on, rather than offering one that answers 422. The authorisation half is
 * still the policy's, and the client is not told the answer to it.
 *
 * @mixin LostFoundItem
 */
final class LostFoundItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->id,

            'building_id' => $this->building_id,
            'building_name' => $this->whenLoaded('building', fn () => $this->building?->name),

            'kind' => $this->kind?->value,
            'kind_label' => $this->kind?->label(),

            /*
             * Which of §2.5.4's two paths this entry took. A reader has to
             * know whether to knock on a door or go to the desk, and the
             * client draws the difference from this rather than from a name it
             * is not given.
             */
            'custody' => $this->custody?->value,
            'custody_label' => $this->custody?->label(),

            'title' => $this->title,
            'description' => $this->description,
            'place' => $this->place,

            // FR-24: the day of the finding, which is not the day of the
            // entry — `created_at` below is the second of those.
            'happened_on' => $this->happened_on?->toDateString(),

            /*
             * The day the find was declared to the police or to a local
             * self-government body (Civil Code art. 227 cl. 2), null while
             * nothing has been declared. The six-month period of art. 228
             * cl. 1 runs from it and never from the registration — FR-27
             * counts those days outside the MVP, and the date is carried here
             * so that a warden can see whether there is anything to count
             * from at all.
             */
            'declared_on' => $this->declared_on?->toDateString(),

            // A path in the object store. Meaningless without its credentials,
            // which is what makes it safe to serialise; the client asks for a
            // link when it is about to show the picture.
            'photo_path' => $this->photo_path,
            'has_photograph' => $this->photo_path !== null,

            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),

            // FR-26: «the status becomes resolved with the time».
            'resolved_at' => $this->resolved_at?->toIso8601String(),

            /*
             * How many claims are on the entry, and never what any of them
             * says. The count tells a resident whether somebody is already
             * ahead of them; the identifying marks are the one thing that
             * makes a claim checkable, and a published list of them would tell
             * the next claimant exactly what to write.
             */
            'claim_count' => $this->whenCounted('claims'),

            'can_claim' => $viewer === null ? null : $this->claimableBy($viewer),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The three facts `StoreLostFoundClaimRequest` would refuse a claim on,
     * asked before the client draws the button.
     */
    private function claimableBy(object $viewer): bool
    {
        return $this->kind?->admitsClaims() === true
            && $this->status?->isFinal() !== true
            && (int) $this->reporter_id !== (int) $viewer->getKey();
    }
}

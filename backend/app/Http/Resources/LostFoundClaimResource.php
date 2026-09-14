<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LostFoundClaim;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One claim as the three people party to it read it (FR-26).
 *
 * **The claimant's name is here and the entry's author's is not, and the
 * asymmetry is deliberate** (§3.5.3). A claim is read by the claimant, by
 * whoever is holding the object and by the warden on a referral — three people
 * arranging a handover — and the person handing an umbrella over has to know
 * who to hand it to. The find card is read by the whole dormitory, which is
 * why it carries nobody at all (FR-25).
 *
 * **No contacts, in either direction.** Not a telephone, not an e-mail, not a
 * room number. The exchange runs through the system and meets at the handover
 * point, which is the one piece of location this module ever publishes and is
 * a place the person holding the object chose.
 *
 * **`may_be_referred` is a fact about the row.** §2.4.4: «the claimant is
 * offered the option of referring the decision to the warden» — so the offer
 * is a flag rather than something a client infers from a status, and the
 * button disappears once the referral has been made. Whether *this* reader may
 * press it is the policy's question and a different one.
 *
 * @mixin LostFoundClaim
 */
final class LostFoundClaimResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lost_found_item_id' => $this->lost_found_item_id,

            'claimant_id' => $this->claimant_id,
            'claimant_name' => $this->whenLoaded('claimant', fn () => $this->claimant?->full_name),

            // FR-26: «describing identifying features». The substance of the
            // claim, and the only thing the holder judges it on.
            'message' => $this->message,

            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),

            // §2.4.4: where the object changes hands. Null until somebody
            // accepts the claim, and required from the moment they do.
            'handover_point' => $this->handover_point,

            /*
             * FR-26's first criterion turns on this pair: a claim the holder
             * accepted and a claim the warden decided both end at `accepted`,
             * and only the identifier of whoever decided tells them apart.
             */
            'decided_by' => $this->decided_by,
            'decided_by_name' => $this->whenLoaded('decider', fn () => $this->decider?->full_name),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_note' => $this->decision_note,

            'referred_at' => $this->referred_at?->toIso8601String(),
            'may_be_referred' => $this->mayBeReferred(),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

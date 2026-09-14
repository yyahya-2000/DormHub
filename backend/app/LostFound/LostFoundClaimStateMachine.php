<?php

declare(strict_types=1);

namespace App\LostFound;

use App\Enums\LostFoundClaimStatus;
use App\Exceptions\IllegalTransitionException;

/**
 * The claim's own graph, beside the entry's (§3.5.5 draws the entry and leaves
 * the claim to FR-26's sentences).
 *
 * **Two tables rather than one, because the two objects move independently.**
 * A claim being declined does not always move the entry — §3.5.5's note says
 * the entry returns to `Published` only «if none of them is confirmed», and
 * with two claims outstanding the first refusal changes nothing about the
 * entry at all. A single table covering both would have had to carry that
 * condition, and a condition inside a transition table is the thing a
 * transition table exists to keep out.
 *
 * **`Declined → Referred` is FR-26's «a claim the two sides cannot settle is
 * referred to the warden».** It is an edge and not a new claim, because the
 * warden has to see that somebody has already said no; a fresh claim would
 * arrive looking like a first one.
 *
 * **The second referral is refused by this same exception, and the message is
 * the right one.** A claim the warden has already declined carries
 * `referred_at`, and `LostFoundService::refer()` raises
 * `IllegalTransitionException(Declined, Referred)` for it — «a claim that is
 * “Declined” cannot become “Referred to the warden”», which is exactly what
 * has happened. The clock-like condition sits in the service for the reason
 * the confirmation window of FR-39 does: a table that knew about it would be a
 * table with an `if` in it.
 *
 * **`Accepted` is final and there is no edge out of it.** FR-26 closes the
 * entry on an accepted claim, and a claim that could be un-accepted would mean
 * a claimant who was told where to collect an object and then told nothing.
 * The holder who accepted the wrong claim declines nothing — they hand the
 * object to the right person and the record says who was told what, which is
 * the honest version of the same correction.
 */
final class LostFoundClaimStateMachine
{
    /**
     * From → the states reachable from it.
     *
     * @var array<string, list<LostFoundClaimStatus>>
     */
    private const TRANSITIONS = [
        LostFoundClaimStatus::New->value => [
            // FR-26: the holder of the object answers.
            LostFoundClaimStatus::Accepted,
            LostFoundClaimStatus::Declined,
        ],
        LostFoundClaimStatus::Declined->value => [
            // FR-26, §2.4.4: «the claimant is offered the option of referring
            // the decision to the warden».
            LostFoundClaimStatus::Referred,
        ],
        LostFoundClaimStatus::Referred->value => [
            // FR-26, first criterion: «a warden's decision on a referred
            // claim».
            LostFoundClaimStatus::Accepted,
            LostFoundClaimStatus::Declined,
        ],
        LostFoundClaimStatus::Accepted->value => [],
    ];

    public function allows(LostFoundClaimStatus $from, LostFoundClaimStatus $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from->value] ?? [], true);
    }

    /**
     * @throws IllegalTransitionException
     */
    public function assert(LostFoundClaimStatus $from, LostFoundClaimStatus $to): void
    {
        if (! $this->allows($from, $to)) {
            throw new IllegalTransitionException($from, $to);
        }
    }

    /**
     * @return list<LostFoundClaimStatus>
     */
    public function reachableFrom(LostFoundClaimStatus $from): array
    {
        return self::TRANSITIONS[$from->value] ?? [];
    }
}

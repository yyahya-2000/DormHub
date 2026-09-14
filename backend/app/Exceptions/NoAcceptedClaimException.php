<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * FR-26, first criterion, from the other side: an entry offered for closure
 * while no claim on it has been accepted.
 *
 * The criterion reads «a find is closed as returned **only** on a claim the
 * finder accepted or on a warden's decision on a referred claim», and the
 * «only» is a restriction on the closure rather than a description of two
 * separate endings. Both grounds arrive at the same place — a claim in status
 * `accepted` — so the check is one question asked of the entry, and this is
 * what it raises when the answer is no.
 *
 * **The state machine cannot catch this one and it is worth saying why.** The
 * entry is `claimed` and `claimed → resolved` is a move the table admits;
 * correctly, because that is the move FR-26 is about. What is missing is not a
 * state but an accepted claim underneath it — the entry may carry three claims
 * and have answered none of them. A transition table that knew about the rows
 * of another table would be a transition table with a query in it, which is
 * the same argument `ConfirmationWindowClosedException` rests on.
 *
 * 409 rather than 422: the body is well formed and the caller is the person
 * holding the object; what stands in the way is the state of the world, and
 * the same call after an acceptance would succeed.
 */
final class NoAcceptedClaimException extends RuntimeException
{
    public function __construct(
        public readonly int $itemId,
        public readonly int $outstandingClaims = 0,
    ) {
        parent::__construct(sprintf(
            'Find #%d cannot be closed as returned: no claim on it has been accepted%s.',
            $itemId,
            $outstandingClaims === 0
                ? ''
                : sprintf(', and %d is still waiting for an answer', $outstandingClaims),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'lost_found_item_id' => $this->itemId,
            'outstanding_claims' => $this->outstandingClaims,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Guests;

use App\Enums\GuestRequestStatus;
use App\Exceptions\IllegalTransitionException;

/**
 * §3.5.4: «Transitions are declared in a table inside `GuestRequestStateMachine`
 * rather than scattered as conditionals in controllers.»
 *
 * The table below is that table, and the value of keeping it in one place is
 * the negative space: a move that is not written here cannot happen anywhere,
 * so «can a refused request be approved by somebody who still had the screen
 * open» has one answer, in one file, instead of one answer per method that
 * writes a status.
 *
 * **Two rows of §3.5.4 are deliberately absent.**
 *
 * `Draft → PendingReview` is absent because no route writes a draft: the form
 * lives in the browser until it is sent, so `PendingReview` is the first state
 * a row has ever had. See `GuestRequestStatus`.
 *
 * `Overdue → InProgress`, an extension of the visit window granted by whoever
 * decided the request, is absent because no route offers the extension in this
 * increment.
 * Carrying the transition without the route would make the table describe a
 * system that does not exist, and the table is the one artefact that must not.
 *
 * **`PendingReview → Rejected` has two very different causes** and the machine
 * does not distinguish them, on purpose. FR-17's fourth criterion — «a request
 * not processed by the start of the visit is treated as rejected» — is a
 * rejection with no human behind it, and the difference between that and a
 * refusal by an officer belongs in `decided_by`, which is null for one and set
 * for the other, and in the audit action. It does not belong in a state: a
 * ninth status would have to be handled by every reader of the queue, and
 * would tell a resident nothing their `decision_comment` does not.
 */
final class GuestRequestStateMachine
{
    /**
     * From → the states reachable from it.
     *
     * @var array<string, list<GuestRequestStatus>>
     */
    private const TRANSITIONS = [
        GuestRequestStatus::PendingReview->value => [
            GuestRequestStatus::Approved,
            GuestRequestStatus::Rejected,
            GuestRequestStatus::Cancelled,
        ],
        GuestRequestStatus::Approved->value => [
            GuestRequestStatus::InProgress,
            GuestRequestStatus::Cancelled,
            GuestRequestStatus::Expired,
        ],
        GuestRequestStatus::InProgress->value => [
            GuestRequestStatus::Overdue,
            GuestRequestStatus::Completed,
        ],
        GuestRequestStatus::Overdue->value => [
            GuestRequestStatus::Completed,
        ],
        GuestRequestStatus::Rejected->value => [],
        GuestRequestStatus::Cancelled->value => [],
        GuestRequestStatus::Expired->value => [],
        GuestRequestStatus::Completed->value => [],
    ];

    public function allows(GuestRequestStatus $from, GuestRequestStatus $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from->value] ?? [], true);
    }

    /**
     * The form every caller uses: assert, then write.
     *
     * @throws IllegalTransitionException
     */
    public function assert(GuestRequestStatus $from, GuestRequestStatus $to): void
    {
        if (! $this->allows($from, $to)) {
            throw new IllegalTransitionException($from, $to);
        }
    }

    /**
     * What a client may offer on the screen, so that the interface and the
     * machine cannot drift apart — the buttons are drawn from the same table
     * that would refuse them.
     *
     * @return list<GuestRequestStatus>
     */
    public function reachableFrom(GuestRequestStatus $from): array
    {
        return self::TRANSITIONS[$from->value] ?? [];
    }
}

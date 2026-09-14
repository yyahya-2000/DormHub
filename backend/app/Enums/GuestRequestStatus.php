<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\TransitionableStatus;

/**
 * The states of a guest request, as §3.5.4 draws them.
 *
 * **Draft is absent, and that is a decision rather than an omission.** The
 * diagram of §3.5.4 opens on `Draft` and moves to `PendingReview` on «submit
 * by the student». Nothing in this increment writes a draft row: the form the
 * resident is filling in lives in the browser until they press send, so the
 * first state a request has ever had in the database is `PendingReview`. A
 * case that no route can produce would be a state the transition table has to
 * carry, the policies have to decide about and the tests cannot reach, which
 * is three costs for a screen the client already owns. When a saved draft is
 * wanted it arrives as one case here and one transition in
 * `GuestRequestStateMachine`.
 *
 * **`InProgress` rather than `InBuilding`.** The diagram labels the state
 * «Guest in the building»; the sequence diagram of §3.5.1 and FR-19's
 * acceptance check both name the stored value `in_progress`. The value is what
 * a client reads, so the value wins and the label is kept in `label()`.
 */
enum GuestRequestStatus: string implements TransitionableStatus
{
    /** Submitted, waiting for the duty officer. The only state in the queue. */
    case PendingReview = 'pending_review';

    /** Approved; the access code exists and the guest is expected at the post. */
    case Approved = 'approved';

    /** The guest is in the building — an entry is recorded and no exit is. */
    case InProgress = 'in_progress';

    /** The control time of the building passed with no exit recorded (FR-20). */
    case Overdue = 'overdue';

    /** Refused by the duty officer, with a reason, or never decided in time. */
    case Rejected = 'rejected';

    /** Withdrawn by the resident who submitted it. */
    case Cancelled = 'cancelled';

    /** Approved, and the visit day ended with the guest never arriving. */
    case Expired = 'expired';

    /** The exit is recorded; the visit is closed. */
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pending review',
            self::Approved => 'Approved, guest expected at the post',
            self::InProgress => 'Guest in the building',
            self::Overdue => 'Visit window exceeded',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled by the student',
            self::Expired => 'Expired, guest never arrived',
            self::Completed => 'Completed',
        };
    }

    /**
     * Whether the request has run its course. A final state is reached once
     * and never left, which is what lets the nightly sweeps skip whole swathes
     * of the table with one condition.
     */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Rejected, self::Cancelled, self::Expired, self::Completed => true,
            default => false,
        };
    }

    /**
     * Whether a guest is recorded inside the building on this request. Both
     * states count: `Overdue` is `InProgress` with the control time passed,
     * and the guest has not left in either.
     */
    public function isInsideTheBuilding(): bool
    {
        return $this === self::InProgress || $this === self::Overdue;
    }

    /**
     * The states that let a code be presented at the post at all. The code is
     * issued on approval (§3.5.1), so before the decision there is nothing to
     * present, and after the entry the request is no longer waiting for one.
     */
    public function admitsAnEntry(): bool
    {
        return $this === self::Approved;
    }

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $status): bool => ! $status->isFinal(),
        ));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}

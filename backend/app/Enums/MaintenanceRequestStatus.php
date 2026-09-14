<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\TransitionableStatus;

/**
 * The six states FR-38 names: «submitted → accepted → in progress →
 * completed → closed; and submitted → rejected».
 *
 * **The values are FR-38's words and not §3.5.2's.** The sequence diagram of
 * §3.5.2 was drawn before the requirement was written down in this form and
 * labels the same states `new`, `assigned` and `done`. FR-38 is the acceptance
 * criterion the module is checked against and a client reads the stored value,
 * so the requirement wins and the diagram's labels are recorded here rather
 * than in the column. The same choice was made for `GuestRequestStatus`, where
 * §3.5.4's «Guest in the building» is stored as `in_progress`.
 *
 * **`Closed` carries two different endings and does not split them.** A
 * request the reporter confirmed and a request that ran out its confirmation
 * window are both closed; which of the two it was is the difference between
 * `confirmed_at` being set and `auto_closed` being true, and FR-39 asks for
 * exactly that distinction to be visible. A seventh state would have to be
 * handled by every reader of the queue and would tell the reporter nothing the
 * two columns do not.
 */
enum MaintenanceRequestStatus: string implements TransitionableStatus
{
    /** Filed by the resident and waiting for the warden to look at it. */
    case Submitted = 'submitted';

    /** Taken into work, with a planned completion date on the row (FR-37). */
    case Accepted = 'accepted';

    /** Somebody is at it now. */
    case InProgress = 'in_progress';

    /** The work is reported done and the reporter has yet to say so (FR-39). */
    case Completed = 'completed';

    /** Confirmed by the reporter, or closed by the window running out. */
    case Closed = 'closed';

    /** Refused, with a reason, which FR-37 makes impossible to omit. */
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::Accepted => 'Accepted with a planned date',
            self::InProgress => 'In progress',
            self::Completed => 'Completed, awaiting confirmation',
            self::Closed => 'Closed',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * Whether the request has run its course. A final state is reached once
     * and never left, which is what lets the queue of FR-40 and the nightly
     * passes skip whole swathes of the table with one condition.
     */
    public function isFinal(): bool
    {
        return $this === self::Closed || $this === self::Rejected;
    }

    /**
     * Whether the request is still somebody's work. FR-40's queue is this
     * question asked of a building.
     */
    public function isOpen(): bool
    {
        return ! $this->isFinal();
    }

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $status): bool => $status->isOpen(),
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

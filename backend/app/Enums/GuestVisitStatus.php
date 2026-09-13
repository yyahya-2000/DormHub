<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The four values `GUEST_VISIT.status` carries in the ER model of §3.4.3.
 *
 * The visit is a separate entity from the request (§3.4.1, decision 4) and so
 * its states are separate too. They look parallel and are not: the request
 * records what was *decided*, the visit records what *happened*, and the
 * difference shows in `ClosedLate` — a visit that ended, after the control
 * time, which is a fact about the evening and not about anybody's decision.
 */
enum GuestVisitStatus: string
{
    /** An entry is recorded and no exit is. */
    case InBuilding = 'in_building';

    /** The guest left before the control time of the building. */
    case Closed = 'closed';

    /** The control time passed with the guest still recorded inside (FR-20). */
    case Overdue = 'overdue';

    /** The guest left, after the control time. */
    case ClosedLate = 'closed_late';

    public function label(): string
    {
        return match ($this) {
            self::InBuilding => 'In the building',
            self::Closed => 'Closed',
            self::Overdue => 'Overdue',
            self::ClosedLate => 'Closed after the control time',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::InBuilding || $this === self::Overdue;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}

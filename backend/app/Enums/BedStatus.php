<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * `BED.status` of the ER model (§3.4.3), in the three values the diagram
 * names: free, occupied, blocked.
 *
 * The column is a **projection**, not the arbiter. Whether a bed is taken is
 * decided by `residencies_active_bed_uniq`, the partial unique index of
 * §3.4.1, decision 3; this field is maintained beside it inside the same
 * transaction so that the free-places report can be produced without joining
 * the residency history on every read. `Blocked` is the one value the index
 * knows nothing about: it is an administrative decision — a broken bed, a
 * place held back for a commission — and it is the reason the column exists
 * at all rather than being computed.
 */
enum BedStatus: string
{
    case Free = 'free';
    case Occupied = 'occupied';
    case Blocked = 'blocked';

    /**
     * Whether the bed may receive a residency. A blocked bed may not, even
     * though no residency holds it.
     */
    public function isAssignable(): bool
    {
        return $this === self::Free;
    }

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Free',
            self::Occupied => 'Occupied',
            self::Blocked => 'Blocked',
        };
    }
}

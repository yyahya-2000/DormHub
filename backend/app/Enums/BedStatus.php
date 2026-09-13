<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * `BED.status` of the ER model (§3.4.3), in the three values the diagram
 * names: free, occupied, blocked.
 *
 * The column is a **projection**, not the arbiter. Whether a bed is taken is
 * decided by `residencies_bed_no_overlap`, the exclusion constraint of §3.4.1,
 * decision 3; this field is maintained beside it so that the free-places
 * report can be produced without joining the residency history on every read.
 * `Blocked` is the one value the constraint knows nothing about: it is an
 * administrative decision — a broken bed, a place held back for a commission —
 * and it is the reason the column exists at all rather than being computed.
 *
 * A projection can lag, and this one lags by at most a night: a residency that
 * ends at midnight leaves `Occupied` standing until
 * `housing:settle-residencies` runs. It cannot lag the other way. Nothing
 * writes `Free` while somebody still holds the bed, and were something to try,
 * the exclusion constraint would refuse the residency that followed — which is
 * the whole point of putting the rule in the constraint and the convenience in
 * the column.
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

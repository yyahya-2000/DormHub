<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * `RESIDENCY.status` of the ER model (§3.4.3).
 *
 * Two values are enough because the third state one might expect — «notice
 * given, not yet gone» — is already expressible: a residency whose
 * `moved_out_at` lies in the future is `Ended` for the purpose of the bed,
 * which the register frees at once (FR-05), and still current for the purpose
 * of access, which ends on the stated date and not before it. Splitting that
 * into a status value would put the same fact in two places and let them
 * disagree.
 */
enum ResidencyStatus: string
{
    case Active = 'active';
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Ended => 'Ended',
        };
    }
}

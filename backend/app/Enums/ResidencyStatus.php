<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * `RESIDENCY.status` of the ER model (§3.4.3).
 *
 * Two values, and they mean what the calendar means. A residency is `Active`
 * while the person is resident and `Ended` once the stated departure date has
 * arrived — never before it. «Notice given, not yet gone» is an `Active`
 * record that happens to carry a `moved_out_at`, and that is not a third state
 * but the same one seen with more information.
 *
 * It used to be argued here that a future-dated termination is `Ended` «for
 * the purpose of the bed». That reading is what a client filtering on `active`
 * ran into: a person living in the dormitory until December did not appear in
 * the list of current residents in September. The status is a projection of
 * the period and nothing else, and the day it turns over is moved by
 * `housing:settle-residencies`.
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

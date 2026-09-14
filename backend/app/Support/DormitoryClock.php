<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * What day it is in the dormitory.
 *
 * **The acceptance finding of 15.09.2026.** The application ran in UTC and the
 * dormitory stands in Moscow, three hours ahead of it. Every «today» in the
 * system was therefore the server's today: after 21:00 local time a find
 * picked up that evening was refused with «a find cannot have happened later
 * than today», and a guest approved for that evening was turned away at the
 * post with «the request is for another day». The form put the resident's date
 * in and the server compared it with a date that was still yesterday.
 *
 * `config/app.php` now carries the dormitory's zone and carries it as a
 * setting, which fixes the deployment. This class fixes the *check*, and the
 * two are not the same thing. Laravel's `before_or_equal:today` resolves the
 * word through `strtotime()`, which reads the real clock and knows nothing of
 * `Carbon::setTestNow()` — so a rule phrased that way cannot be tested at a
 * day boundary at all, and a test that cannot be written is the reason a
 * defect like this one survives. Every «today» the system decides by is
 * computed here instead, from Carbon, in the dormitory's zone.
 *
 * The timezone is read from `app.timezone` rather than held here: one setting,
 * and the clock the application stamps rows with is the clock it judges dates
 * by. A campus spread across two zones would make this a property of the
 * building, as NFR-09 already makes the visiting hours — the seam for that is
 * this class, and nothing else would have to move.
 */
final class DormitoryClock
{
    /**
     * Now, as a clock in the dormitory's hallway would show it.
     *
     * The timezone is applied explicitly rather than assumed from the default,
     * so that the answer is the dormitory's day even when the moment arrives
     * from somewhere that keeps its own zone — a test pinning an instant in
     * UTC, an import, a queued job.
     */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now()->setTimezone(self::timezone());
    }

    /**
     * Midnight of the current day in the dormitory.
     */
    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }

    /**
     * The current date as `Y-m-d`, which is the shape a validation rule takes.
     */
    public static function todayAsDate(): string
    {
        return self::now()->toDateString();
    }

    private static function timezone(): string
    {
        $timezone = config('app.timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }
}

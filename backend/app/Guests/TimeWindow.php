<?php

declare(strict_types=1);

namespace App\Guests;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Two wall-clock times pinned to a calendar day, which is the only honest way
 * to compare a visiting regime with a visit.
 *
 * `BUILDING.visiting_from`, `BUILDING.visiting_to` and the request's
 * `planned_from`, `planned_to` are all stored as times of day, and a time of
 * day cannot be compared with another until somebody says which day. Doing
 * that comparison as strings — `'23:00' > '14:00'` — works right up to the
 * moment a dormitory admits guests past midnight, and then it silently
 * inverts: the window 08:00–02:00 looks empty and every interval falls outside
 * it. NFR-09 makes the regime a per-building setting precisely so that such a
 * dormitory can exist, so the arithmetic has to survive it.
 *
 * The rule is one line and it is the whole class: **a closing time at or
 * before the opening time belongs to the next day.** A window from 08:00 to
 * 08:00 is therefore twenty-four hours and not nothing, which is the reading
 * that lets a dormitory with no curfew be configured without a flag of its
 * own.
 */
final readonly class TimeWindow
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    /**
     * The window as it falls on one day.
     *
     * @param  string  $from  a time of day, `H:i` or `H:i:s`
     * @param  string  $to  a time of day; at or before `$from` it means the next day
     */
    public static function on(CarbonInterface $date, string $from, string $to): self
    {
        $day = CarbonImmutable::parse($date->toDateString(), $date->getTimezone());

        $opens = self::at($day, $from);
        $closes = self::at($day, $to);

        return new self(
            from: $opens,
            to: $closes->lessThanOrEqualTo($opens) ? $closes->addDay() : $closes,
        );
    }

    /**
     * One moment of a given day, for a control time that is a single instant
     * rather than a span.
     */
    public static function at(CarbonImmutable $day, string $time): CarbonImmutable
    {
        [$hour, $minute, $second] = array_pad(array_map('intval', explode(':', $time)), 3, 0);

        return $day->startOfDay()->setTime($hour, $minute, $second);
    }

    /**
     * Whether the window runs past midnight into the following day.
     */
    public function crossesMidnight(): bool
    {
        return $this->from->toDateString() !== $this->to->toDateString();
    }

    /**
     * Both ends included. The boundary belongs to the window: a guest who
     * arrives on the stroke of the opening hour is inside it, and one who
     * leaves on the stroke of the closing hour has left in time. Clause 2.2
     * reads «from 08:00 to 23:00», and a stricter reading would refuse an
     * entry at exactly 08:00 for no reason anybody could explain at the desk.
     */
    public function contains(CarbonInterface $moment): bool
    {
        return $moment->greaterThanOrEqualTo($this->from)
            && $moment->lessThanOrEqualTo($this->to);
    }

    /**
     * Whether this window holds the whole of another — the question FR-16's
     * second criterion asks: does the interval the resident wants fit inside
     * the regime of the building.
     */
    public function covers(self $inner): bool
    {
        return $inner->from->greaterThanOrEqualTo($this->from)
            && $inner->to->lessThanOrEqualTo($this->to);
    }

    public function format(string $shape = 'H:i'): string
    {
        return $this->from->format($shape).'–'.$this->to->format($shape);
    }
}

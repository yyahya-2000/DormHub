<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * FR-36's urgency, in the three values the ER diagram of §3.4.3 gives
 * `MAINTENANCE_REQUEST.priority`: routine, urgent, emergency.
 *
 * **The resident sets it and the warden may change it on triage, and that is
 * a decision rather than an oversight.** A scale nobody can move is a scale
 * everybody maximises: if «emergency» were the resident's word and final, the
 * queue would be all emergencies within a term. The resident's choice is what
 * they know — water on the floor now, a wobbly chair whenever — and the
 * warden's is what the queue is ordered by. Both are recorded: the change is a
 * row in `maintenance_work_logs` like every other, so «who called this
 * routine» has an answer.
 *
 * The urgency is **not** the overdue threshold of FR-40. That threshold is
 * configuration and measured in days since submission; this is what a person
 * said about the defect.
 */
enum MaintenanceUrgency: string
{
    /** It can wait for the ordinary round. */
    case Routine = 'routine';

    /** It is spoiling the room's use and should be seen this week. */
    case Urgent = 'urgent';

    /** Water, gas, electricity or a locked-out door: today. */
    case Emergency = 'emergency';

    public function label(): string
    {
        return match ($this) {
            self::Routine => 'Routine',
            self::Urgent => 'Urgent',
            self::Emergency => 'Emergency',
        };
    }

    /**
     * The order the queue of FR-40 is sorted by, most pressing first. An
     * integer rather than the enum's position, so that the sort survives a
     * value being inserted into the middle of the list.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Emergency => 3,
            self::Urgent => 2,
            self::Routine => 1,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $urgency): string => $urgency->value, self::cases());
    }
}

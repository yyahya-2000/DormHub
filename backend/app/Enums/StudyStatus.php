<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The study status FR-06 requires on the resident card.
 *
 * It matters beyond display: art. 105 cl. 2 of the Housing Code ties the term
 * of an accommodation contract to the term of study, so the ground on which a
 * residency ends (FR-05) is usually a change of this field.
 */
enum StudyStatus: string
{
    case Enrolled = 'enrolled';
    case AcademicLeave = 'academic_leave';
    case Graduated = 'graduated';
    case Expelled = 'expelled';

    public function label(): string
    {
        return match ($this) {
            self::Enrolled => 'Enrolled',
            self::AcademicLeave => 'On academic leave',
            self::Graduated => 'Graduated',
            self::Expelled => 'Expelled',
        };
    }
}

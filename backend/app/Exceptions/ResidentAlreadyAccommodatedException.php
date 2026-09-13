<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Residency;
use RuntimeException;

/**
 * The other half of §3.4.4's sentence: «a user likewise occupies one bed at a
 * time». Enforced by `residencies_active_user_uniq` and surfaced here with the
 * residency that stands in the way, so that the warden is told which bed the
 * person is to be moved out of rather than merely that something went wrong.
 */
final class ResidentAlreadyAccommodatedException extends RuntimeException
{
    public function __construct(
        public readonly int $userId,
        public readonly ?Residency $conflicting,
    ) {
        parent::__construct(
            $conflicting === null
                ? 'This resident already holds an open residency.'
                : sprintf(
                    'This resident already holds bed %d since %s; terminate that residency first.',
                    $conflicting->bed_id,
                    $conflicting->moved_in_at?->toDateString() ?? 'an unrecorded date',
                )
        );
    }
}

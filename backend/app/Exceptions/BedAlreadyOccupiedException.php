<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Residency;
use RuntimeException;

/**
 * FR-03: two residents cannot hold the same bed over overlapping periods, and
 * «on such an attempt the system displays the conflicting record».
 *
 * The exception carries that record. It is raised only after the database has
 * refused the insert on `residencies_active_bed_uniq` — the service does not
 * look before it leaps, because a check preceding an insert can be overtaken
 * between the two statements and a unique index cannot. The conflicting row is
 * then read to be shown, which is a read whose only purpose is the message.
 */
final class BedAlreadyOccupiedException extends RuntimeException
{
    public function __construct(
        public readonly int $bedId,
        public readonly ?Residency $conflicting,
    ) {
        parent::__construct(
            $conflicting === null
                ? 'This bed is already held by an open residency.'
                : sprintf(
                    'This bed is held by an open residency since %s under contract %s.',
                    $conflicting->moved_in_at?->toDateString() ?? 'an unrecorded date',
                    $conflicting->contract_number,
                )
        );
    }
}

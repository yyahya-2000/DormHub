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
 * refused the insert on `residencies_bed_no_overlap` — the service does not
 * look before it leaps, because a check preceding an insert can be overtaken
 * between the two statements and an exclusion constraint cannot. The
 * conflicting row is then read to be shown, which is a read whose only purpose
 * is the message.
 *
 * The message names the end of the conflicting period when there is one. That
 * is the sentence a warden acts on: «held until the 31st of December» tells
 * them when the place comes free, where «already held» tells them only to try
 * something else.
 */
final class BedAlreadyOccupiedException extends RuntimeException
{
    public function __construct(
        public readonly int $bedId,
        public readonly ?Residency $conflicting,
    ) {
        parent::__construct(
            $conflicting === null
                ? 'This bed is held over a period that overlaps the one requested.'
                : sprintf(
                    'This bed is held under contract %s from %s %s, which overlaps the period requested.',
                    $conflicting->contract_number,
                    $conflicting->moved_in_at?->toDateString() ?? 'an unrecorded date',
                    $conflicting->moved_out_at === null
                        ? 'with no end recorded'
                        : 'until '.$conflicting->moved_out_at->toDateString(),
                )
        );
    }
}

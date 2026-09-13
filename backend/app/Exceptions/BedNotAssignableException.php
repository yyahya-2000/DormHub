<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Bed;
use RuntimeException;

/**
 * A bed that no residency holds and that still cannot receive one: a bed
 * withdrawn by the warden, or a bed in a room under repair or out of the
 * housing stock.
 *
 * This is the case the partial unique index cannot cover, and the reason
 * `BED.status` is stored rather than derived (see `App\Enums\BedStatus`). The
 * index answers «is somebody in it»; only the status answers «may anybody be
 * put in it».
 */
final class BedNotAssignableException extends RuntimeException
{
    public function __construct(
        public readonly int $bedId,
        string $reason,
    ) {
        parent::__construct($reason);
    }

    public static function bedBlocked(Bed $bed): self
    {
        return new self(
            bedId: (int) $bed->getKey(),
            reason: sprintf('Bed %s is blocked and accepts no residency.', $bed->label),
        );
    }

    public static function roomOutOfService(Bed $bed): self
    {
        return new self(
            bedId: (int) $bed->getKey(),
            reason: sprintf(
                'Room %s is %s and accepts no residency.',
                $bed->room?->number ?? '?',
                $bed->room?->status->label() ?? 'out of service',
            ),
        );
    }
}

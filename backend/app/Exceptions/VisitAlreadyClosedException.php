<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * FR-21, second criterion, at the point where it bites: an exit was offered
 * for a visit that already carries one.
 *
 * The register is immutable and a correction is a correcting entry, so the
 * answer to «the officer recorded the wrong exit time» is not an edit. It is a
 * refusal here, and then a correcting row in the audit log through
 * `POST /guest-visits/{id}/correction`, which leaves both the original fact
 * and the correction readable side by side. NFR-14 is the reason: a register
 * whose rows can be rewritten proves nothing about the evening it describes.
 *
 * 409. The caller's role covers the visit, the body is well formed, and what
 * stands in the way is that the thing has already happened.
 */
final class VisitAlreadyClosedException extends RuntimeException
{
    public function __construct(
        public readonly int $visitId,
        public readonly string $closedAt,
    ) {
        parent::__construct(sprintf(
            'The exit on this visit was recorded at %s. The register is append-only: '
            .'a mistake is put right by a correcting entry, not by overwriting the row.',
            $closedAt,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'guest_visit_id' => $this->visitId,
            'checked_out_at' => $this->closedAt,
        ];
    }
}

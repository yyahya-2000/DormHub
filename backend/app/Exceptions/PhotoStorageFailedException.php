<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A photograph the client sent could not be written to the object store.
 *
 * **The exception exists because its absence cost a submission its
 * attachments** (acceptance of 15.09.2026). `Storage::putFile()` answers
 * `false` on a refused write when the disk is configured not to throw, and
 * `PhotoStore` used to drop such a path on the floor: the deployment's bucket
 * did not exist, every write was refused, and `POST /maintenance-requests`
 * answered 201 with `photo_count: 0`. The resident saw a filed request and no
 * photographs, and nothing anywhere said why.
 *
 * A photograph is evidence — FR-36 puts it in the submission and §2.4.3 has
 * the warden triage on it — so a submission that loses one is not a
 * submission that succeeded. The request is refused instead, and the client
 * is told to try again rather than left to discover the loss later.
 *
 * 503 and not 500: nothing about the request is wrong and nothing in the
 * application is broken. What is unavailable is the object store, and the
 * same call made once it is back succeeds unchanged. The mapping lives in
 * bootstrap/app.php, because the application layer knows no status codes
 * (§3.3.1).
 */
final class PhotoStorageFailedException extends RuntimeException
{
    public function __construct(
        public readonly string $disk,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            'The photograph could not be stored, so the request was not accepted. Please try again.',
            previous: $previous,
        );
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return ['disk' => $this->disk];
    }
}

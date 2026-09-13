<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The login is blocked because the configured number of failed attempts was
 * reached (FR-08). Carries the seconds remaining, which the interface layer
 * turns into a Retry-After header.
 */
final class LoginLockedException extends RuntimeException
{
    public function __construct(
        public readonly int $secondsRemaining,
    ) {
        parent::__construct(sprintf(
            'Too many failed attempts. Try again in %d minutes.',
            (int) ceil($secondsRemaining / 60),
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ThrottleReason;
use RuntimeException;

/**
 * The sign-in is blocked because a configured limit was reached (FR-08).
 * Carries the seconds remaining, which the interface layer turns into a
 * Retry-After header, and the reason, which tells the account block apart from
 * the address ceiling.
 */
final class LoginLockedException extends RuntimeException
{
    public function __construct(
        public readonly int $secondsRemaining,
        public readonly ThrottleReason $reason = ThrottleReason::LoginLocked,
    ) {
        $minutes = (int) ceil($secondsRemaining / 60);

        parent::__construct(match ($reason) {
            ThrottleReason::AddressLocked => sprintf(
                'Too many failed attempts from this address. Try again in %d minutes.',
                $minutes,
            ),
            default => sprintf(
                'Too many failed attempts. Try again in %d minutes.',
                $minutes,
            ),
        });
    }
}

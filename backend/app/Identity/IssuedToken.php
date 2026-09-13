<?php

declare(strict_types=1);

namespace App\Identity;

use App\Models\User;
use DateTimeImmutable;

/**
 * What a successful sign-in yields. The plain-text token exists only in this
 * object and in the reply it produces; the database keeps its hash.
 */
final readonly class IssuedToken
{
    public function __construct(
        public User $user,
        public string $plainTextToken,
        public ?DateTimeImmutable $expiresAt,
        public string $provider,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Identity;

use App\Contracts\IdentityProvider;
use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * The default provider: the password hash kept in `users`, compared with
 * bcrypt or argon2 through the framework hasher (§3.9.7).
 */
final readonly class DatabaseIdentityProvider implements IdentityProvider
{
    public function __construct(private Hasher $hasher) {}

    public function name(): string
    {
        return 'database';
    }

    public function authenticate(Credentials $credentials): ?User
    {
        $user = User::query()
            ->where('email', $credentials->login)
            ->first();

        // The hash is computed even when no such account exists, so that the
        // time the answer takes does not reveal which logins are registered.
        $hash = $user?->password_hash ?? '$2y$12$'.str_repeat('.', 53);

        if (! $this->hasher->check($credentials->secret, $hash)) {
            return null;
        }

        return $user;
    }
}

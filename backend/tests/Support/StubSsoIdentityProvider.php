<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\IdentityProvider;
use App\Identity\Credentials;
use App\Models\User;

/**
 * The second implementation of the identity-provider interface.
 *
 * Constraint C-04 keeps a real integration with the university SSO outside
 * this iteration, and §4.7.3 says plainly what is tested instead: not the
 * integration, but the extension point. This class stands in for an external
 * directory — it verifies a ticket rather than a password and matches the
 * account by its external identifier — and the only thing the application has
 * to do to use it is name it in configuration.
 */
final class StubSsoIdentityProvider implements IdentityProvider
{
    /**
     * @param  array<string, string>  $tickets  ticket => external identifier
     */
    public function __construct(private readonly array $tickets) {}

    public function name(): string
    {
        return 'stub-sso';
    }

    public function authenticate(Credentials $credentials): ?User
    {
        $externalId = $this->tickets[$credentials->secret] ?? null;

        if ($externalId === null) {
            return null;
        }

        return User::query()
            ->where('external_id', $externalId)
            ->where('email', $credentials->login)
            ->first();
    }
}

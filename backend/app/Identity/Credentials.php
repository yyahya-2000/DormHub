<?php

declare(strict_types=1);

namespace App\Identity;

use SensitiveParameter;

/**
 * The pair an identity provider is asked to verify. A value object rather than
 * two loose strings, so that the provider interface does not grow a parameter
 * every time a provider needs one more field.
 */
final readonly class Credentials
{
    public function __construct(
        public string $login,
        #[SensitiveParameter]
        public string $secret,
    ) {}

    public function __debugInfo(): array
    {
        return ['login' => $this->login, 'secret' => '[redacted]'];
    }
}

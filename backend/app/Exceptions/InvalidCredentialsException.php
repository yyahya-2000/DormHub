<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The credential did not resolve to an account that may sign in — either the
 * pair is wrong or the account is blocked or archived. The two cases are not
 * distinguished outwards, so that the reply does not say which logins exist.
 *
 * The exception carries no HTTP status: the mapping to 401 happens where the
 * application meets the protocol, in bootstrap/app.php (§3.3.1).
 */
final class InvalidCredentialsException extends RuntimeException
{
    public function __construct(
        public readonly int $attemptsLeft,
    ) {
        parent::__construct('The login or the password is wrong.');
    }
}

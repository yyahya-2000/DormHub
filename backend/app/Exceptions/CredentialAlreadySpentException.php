<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * FR-42: a second one-time code was asked for on an account that already has a
 * password of its own.
 *
 * The refusal is the security of the re-issue route rather than a detail of it.
 * A member of staff who could send a fresh code to an account in use would hold
 * a password reset over every resident of their building, and nothing in FR-42
 * grants that. The route serves the case it was added for — a code that expired
 * before the person got to it — and stops at the moment the account changed
 * hands.
 *
 * 409 and not 403: the caller's role covers the object, and what stands in the
 * way is the state of the account.
 */
final class CredentialAlreadySpentException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'This account already has a password of its own. A one-time code is issued only to an account that has never set one.'
        );
    }
}

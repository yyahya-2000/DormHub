<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * FR-42: the one-time credential did not resolve.
 *
 * One exception for four causes — no such address, a token that never existed,
 * a token already spent, a token past its hour — and deliberately one. Telling
 * them apart in the answer would let an unauthenticated caller learn which
 * addresses hold an account, which is the same reason the sign-in route of
 * FR-08 never says whether a login exists.
 */
final class InvalidPasswordTokenException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'This one-time code is not valid any more. Ask the building office to issue a new one.'
        );
    }
}

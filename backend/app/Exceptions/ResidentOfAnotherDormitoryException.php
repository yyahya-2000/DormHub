<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * FR-42 and FR-07: the account named is not a resident of the dormitory the
 * route named.
 *
 * 404 rather than 403, and the wording says nothing about whether the account
 * exists: the caller is allowed to work in this building, and inside this
 * building there is no such resident.
 */
final class ResidentOfAnotherDormitoryException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No such resident in this dormitory.');
    }
}

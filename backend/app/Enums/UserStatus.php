<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Account status. A backed enumeration rather than a lookup table (§4.4.2):
 * a new value would mean nothing until the code that reacts to it exists.
 */
enum UserStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';
    case Archived = 'archived';

    /**
     * Only an active account may hold a session.
     */
    public function canSignIn(): bool
    {
        return $this === self::Active;
    }
}

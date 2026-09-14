<?php

declare(strict_types=1);

namespace App\Identity;

use App\Models\Building;
use App\Models\User;

/**
 * FR-42: the account that has just been created, and the password that was
 * generated for it — the only moment that password exists in readable form.
 *
 * It is a value object and not a field on the model for one reason: a model is
 * saved, serialised and logged by half the framework, and a plain password on
 * one would eventually be written somewhere by code that has no idea it is
 * carrying a secret. This object is built by the issuer, read once by the
 * controller that answers the request, and then goes out of scope.
 */
final readonly class IssuedAccount
{
    public function __construct(
        public User $user,
        public Building $building,
        public string $password,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a request was answered with 429.
 *
 * The three cases are genuinely different events and the contract describes
 * them as one schema with this field as the discriminator, so that a generated
 * client can tell them apart instead of guessing from the wording of
 * `message`. Two of them come from the attempt counter of FR-08, the third
 * from the per-address request limit the sign-in route carries.
 */
enum ThrottleReason: string
{
    // The account reached the configured number of failed attempts and is
    // blocked for the configured span. This is FR-08's first criterion.
    case LoginLocked = 'login_locked';

    // The address reached its own ceiling, counted across every login tried
    // from it. A dictionary run never fills a single account counter; it fills
    // this one.
    case AddressLocked = 'address_locked';

    // The address sent more requests to the sign-in route than the route
    // admits per minute. Nothing is said about credentials: a caller with a
    // correct password and no failed attempt at all can meet this one.
    case RateLimited = 'rate_limited';
}

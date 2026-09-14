<?php

declare(strict_types=1);

namespace App\Contracts;

use BackedEnum;

/**
 * A status a state machine moves an entity between.
 *
 * The interface exists for one reason: `App\Exceptions\IllegalTransitionException`
 * was written against `GuestRequestStatus` when the guest module was the only
 * module with a state machine, and §4.6.3 asks the maintenance module to raise
 * **the same** exception — «an attempt at any other transition raises the same
 * domain exception mapped to 409». Two exception classes with identical bodies
 * would also mean two entries in `bootstrap/app.php` that could drift apart,
 * and the client would have to learn which module it was talking to before it
 * could read a 409.
 *
 * `label()` is the whole contract, and it is what makes the shared message
 * readable: «a request that is “Awaiting triage” cannot become “Closed”» says
 * something to the person who pressed the button, and the stored values in
 * `context()` say the same thing to the client.
 */
interface TransitionableStatus extends BackedEnum
{
    public function label(): string;
}

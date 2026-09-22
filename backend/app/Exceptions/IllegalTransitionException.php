<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Contracts\TransitionableStatus;
use RuntimeException;

/**
 * A state machine of §3.5 was asked for a move it does not have.
 *
 * §3.5.4 maps this to HTTP 409 and the mapping happens in bootstrap/app.php,
 * because the application layer knows no status codes (§3.3.1). 409 rather
 * than 422: the request body is perfectly well formed and the caller's role
 * covers the object — what stands in the way is the state the request is
 * already in, usually because somebody else moved it first.
 *
 * The message names both ends of the move. A manager who presses Approve on a
 * request the warden refused a moment earlier is told that it is already
 * refused, which is the only useful thing to tell him.
 *
 * **The type is the interface and not one enum, which is §4.6.3's doing.** The
 * class was written for `GuestRequestStatus` when the guest module held the
 * only transition table; the maintenance module of increment 3 has a table of
 * its own and §4.6.3 asks for «the same domain exception mapped to 409». So
 * both enumerations implement `TransitionableStatus` and this class names that
 * instead. A second exception class would have meant a second `render()` entry
 * beside the first, and a client that had to know which module answered before
 * it could read the body.
 */
final class IllegalTransitionException extends RuntimeException
{
    public function __construct(
        public readonly TransitionableStatus $from,
        public readonly TransitionableStatus $to,
    ) {
        parent::__construct(sprintf(
            'A request that is «%s» cannot become «%s».',
            $from->label(),
            $to->label(),
        ));
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return [
            'status' => (string) $this->from->value,
            'attempted_status' => (string) $this->to->value,
        ];
    }
}

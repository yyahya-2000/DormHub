<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\GuestRequestStatus;
use RuntimeException;

/**
 * The state machine of §3.5.4 was asked for a move it does not have.
 *
 * §3.5.4 maps this to HTTP 409 and the mapping happens in bootstrap/app.php,
 * because the application layer knows no status codes (§3.3.1). 409 rather
 * than 422: the request body is perfectly well formed and the caller's role
 * covers the object — what stands in the way is the state the request is
 * already in, usually because somebody else moved it first.
 *
 * The message names both ends of the move. A duty officer who presses Approve
 * on a request a second duty officer refused a moment earlier is told that it
 * is already refused, which is the only useful thing to tell them.
 */
final class IllegalTransitionException extends RuntimeException
{
    public function __construct(
        public readonly GuestRequestStatus $from,
        public readonly GuestRequestStatus $to,
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
            'status' => $this->from->value,
            'attempted_status' => $this->to->value,
        ];
    }
}

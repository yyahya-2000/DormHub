<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * FR-39, from the other side: a reopening offered after the confirmation
 * window has run out.
 *
 * The state machine cannot catch this one and it is worth saying why. Once
 * `AutoCloseConfirmedWork` has passed, the request is `closed` and the machine
 * refuses `closed → accepted` on its own. Between the window closing and the
 * next run of the job the request is still `completed`, and `completed →
 * accepted` is a move the table admits — correctly, because it is the move
 * FR-39 is about. What has run out is not the state but the clock, and a
 * clock is not something a transition table can hold.
 *
 * 409 rather than 422: the body is well formed and the caller is the right
 * person; what stands in the way is the state of the world, and the same call
 * a day earlier would have succeeded. The context carries both dates so the
 * client can say «the window closed on the 14th» rather than «something went
 * wrong».
 */
final class ConfirmationWindowClosedException extends RuntimeException
{
    public function __construct(
        public readonly int $requestId,
        public readonly int $windowDays,
        public readonly ?string $closedOn = null,
    ) {
        parent::__construct(sprintf(
            'The confirmation window of %d day(s) on request #%d has closed%s, '
            .'so the work can no longer be disputed here.',
            $windowDays,
            $requestId,
            $closedOn === null ? '' : ' on '.$closedOn,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'maintenance_request_id' => $this->requestId,
            'confirmation_window_days' => $this->windowDays,
            'window_closed_on' => $this->closedOn,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationCategory;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * FR-34, first of the four occasions: the duty officer has decided a guest
 * request (FR-17).
 *
 * **Why the class is here before the guest requests are.** FR-34 belongs to
 * increment 0 and its occasions belong to increments 1 and 3. The requirement
 * is met by the mechanism — a category, a queued message, a switch — and the
 * mechanism cannot be demonstrated against an occasion that does not exist. So
 * the message exists and the thing that raises it does not yet: the
 * constructor takes the decision as plain values rather than a
 * `GuestRequest`, which is what lets it be written and tested now and called
 * from `GuestRequestService::approve()` the day that service is written, with
 * no change here.
 *
 * The category is optional (see `NotificationCategory`): a resident who would
 * rather look at the screen may switch it off, and it stops when the consent
 * it rests on is withdrawn.
 */
final class GuestRequestDecided extends EventNotification
{
    public function __construct(
        public readonly int $requestId,
        public readonly string $guestName,
        public readonly bool $approved,
        public readonly ?string $comment = null,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::RequestDecision;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->approved
                ? 'Your guest request has been approved'
                : 'Your guest request has been refused')
            ->line(sprintf(
                'Request #%d, for %s, has been %s.',
                $this->requestId,
                $this->guestName,
                $this->approved ? 'approved' : 'refused',
            ));

        if ($this->comment !== null && $this->comment !== '') {
            $message->line('The duty officer wrote: '.$this->comment);
        }

        return $this->approved
            ? $message->line('The guest is admitted between 08:00 and 23:00, and only while you are there.')
            : $message;
    }

    /**
     * The in-app half of the same message. It carries identifiers rather than
     * a sentence, so the personal account can link to the request itself and
     * so the text can be changed without rewriting the rows already stored.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => $this->category()->value,
            'guest_request_id' => $this->requestId,
            'guest_name' => $this->guestName,
            'approved' => $this->approved,
            'comment' => $this->comment,
        ];
    }
}

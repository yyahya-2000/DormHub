<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationCategory;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * FR-34, third occasion: a maintenance request has moved from one state to
 * another (FR-38).
 *
 * The states are named as plain strings rather than as a
 * `MaintenanceRequestStatus` enum, because that enum belongs to increment 3
 * and this message belongs to increment 0. When the state machine of §3.3.5 is
 * written, its enum's `->value` is what arrives here, and the class does not
 * change.
 *
 * Optional: the reporter sees the same movement on the request itself, so a
 * person who would rather not be told each time may switch it off.
 */
final class MaintenanceRequestStatusChanged extends EventNotification
{
    public function __construct(
        public readonly int $requestId,
        public readonly string $fromStatus,
        public readonly string $toStatus,
        public readonly ?string $comment = null,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::MaintenanceStatus;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(sprintf('Maintenance request #%d is now «%s»', $this->requestId, $this->toStatus))
            ->line(sprintf(
                'Request #%d has moved from «%s» to «%s».',
                $this->requestId,
                $this->fromStatus,
                $this->toStatus,
            ));

        return $this->comment === null || $this->comment === ''
            ? $message
            : $message->line($this->comment);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => $this->category()->value,
            'maintenance_request_id' => $this->requestId,
            'from_status' => $this->fromStatus,
            'to_status' => $this->toStatus,
            'comment' => $this->comment,
        ];
    }
}

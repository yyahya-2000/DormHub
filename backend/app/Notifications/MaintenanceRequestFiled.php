<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationCategory;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * FR-36, last line of the Gherkin: «the warden of that building receives a
 * notification».
 *
 * **A class of its own beside `MaintenanceRequestStatusChanged`, and the
 * reason is honesty rather than taste.** That class says «request #12 has
 * moved from X to Y», and a submission is not a movement between two states —
 * it is the state coming into existence. Reusing it would have meant inventing
 * a `from` that does not exist and writing it into the stored payload of every
 * such message, where a client would read it back as though it meant
 * something. The work log makes the same distinction with a null `from_status`
 * for the same reason.
 *
 * The category is the same one (`maintenance_status`), because the category is
 * the unit the personal account labels a message by and FR-34 has one occasion
 * here, not two: «a change in the state of a maintenance request».
 *
 * The recipient is the staff who triage, not the resident who filed it — a
 * message telling somebody what they have just done is an echo, not a
 * notification.
 */
final class MaintenanceRequestFiled extends EventNotification
{
    public function __construct(
        public readonly int $requestId,
        public readonly string $buildingName,
        public readonly string $category,
        public readonly string $urgency,
        public readonly string $place,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::MaintenanceStatus;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(sprintf('New maintenance request #%d in %s', $this->requestId, $this->buildingName))
            ->line(sprintf(
                'A %s request was filed for %s: %s.',
                $this->urgency,
                $this->place,
                $this->category,
            ))
            ->line('It is waiting in the queue of the dormitory.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => $this->category()->value,
            'maintenance_request_id' => $this->requestId,
            'building' => $this->buildingName,
            'maintenance_category' => $this->category,
            'urgency' => $this->urgency,
            'place' => $this->place,
        ];
    }
}

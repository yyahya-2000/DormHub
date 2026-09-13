<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationCategory;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * FR-34, second occasion: a guest is still recorded inside the dormitory after
 * the hour by which they were to have left (FR-20).
 *
 * **Mandatory, and the ground is not politeness.** Clause 2.2 of the rules of
 * internal order admits a guest between 08:00 and 23:00 and only in the
 * presence of the resident who invited them; the resident is the person
 * answerable for the departure, and the security post is the place that can
 * act on it. A notice of that kind is not a convenience the recipient may
 * decline — it is the dormitory discharging an obligation it has under its own
 * rules, so `NotificationCategory::VisitOverdue` carries no switch.
 *
 * Raised by the scheduled sweep of §3.3.4 rather than by a request, which is
 * the other half of why it is queued: a cron entry has no user waiting on it,
 * and it must not be the thing that blocks when a mail server is slow.
 */
final class GuestVisitOverdue extends EventNotification
{
    public function __construct(
        public readonly int $visitId,
        public readonly string $guestName,
        public readonly string $buildingName,
        public readonly CarbonInterface $dueAt,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::VisitOverdue;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A guest has not left the dormitory')
            ->line(sprintf(
                '%s was to have left %s by %s and is still recorded inside.',
                $this->guestName,
                $this->buildingName,
                $this->dueAt->format('H:i'),
            ))
            ->line('Clause 2.2 of the rules of internal order admits a guest only between 08:00 and 23:00, and only while the resident who invited them is present.')
            ->line('Accompany the guest to the security post so the exit can be recorded.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => $this->category()->value,
            'guest_visit_id' => $this->visitId,
            'guest_name' => $this->guestName,
            'building_name' => $this->buildingName,
            'due_at' => $this->dueAt->toIso8601String(),
        ];
    }
}

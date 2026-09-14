<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationCategory;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * FR-40 and §3.5.2's nightly pass: «digest of overdue requests» to the warden.
 *
 * **One message per dormitory per night, and that is the whole design of the
 * class.** A backlog of forty requests notified one by one is forty messages
 * to the same warden on the same night, which is how a person learns to ignore
 * a channel — and a channel nobody reads is worse than none, because the
 * dormitory then believes the warden was told. So the digest carries a count,
 * the threshold it was measured against and the oldest request, and the queue
 * of FR-40 is one click away for the detail.
 *
 * **It is not a `MaintenanceRequestStatusChanged`, although it was tempting.**
 * Nothing has moved: an overdue request is still `accepted` or `in_progress`,
 * and the change is in the calendar. Sending a movement message with the same
 * status at both ends would have written a lie into the stored payload of
 * every such notification, where a client reads it back as though it meant
 * something.
 *
 * The category is `maintenance_status`, the same one, because FR-34 names one
 * occasion for this module — «a change in the state of a maintenance request»
 * — and the category is the unit a delivery rule is written against. A warden
 * who has switched it off has switched off the whole module's traffic, which
 * is what they meant.
 */
final class MaintenanceOverdueDigest extends EventNotification
{
    public function __construct(
        public readonly string $buildingName,
        public readonly int $overdueCount,
        public readonly int $thresholdDays,
        public readonly int $oldestRequestId,
        public readonly int $oldestAgeDays,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::MaintenanceStatus;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(sprintf(
                '%d overdue maintenance request(s) in %s',
                $this->overdueCount,
                $this->buildingName,
            ))
            ->line(sprintf(
                '%d request(s) are past the %d-day threshold or past their planned completion date.',
                $this->overdueCount,
                $this->thresholdDays,
            ))
            ->line(sprintf(
                'The oldest is #%d, %d day(s) old.',
                $this->oldestRequestId,
                $this->oldestAgeDays,
            ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => $this->category()->value,
            'building' => $this->buildingName,
            'overdue_count' => $this->overdueCount,
            'threshold_days' => $this->thresholdDays,
            'oldest_maintenance_request_id' => $this->oldestRequestId,
            'oldest_age_days' => $this->oldestAgeDays,
        ];
    }
}

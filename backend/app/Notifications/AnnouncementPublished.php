<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationCategory;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * FR-09 reaching the resident: the warden has published an announcement.
 *
 * **The category is chosen per message, and this is the one notification in
 * the system that does that.** `NotificationCategory` is a closed set of
 * delivery rules, and the rule for an announcement genuinely differs by the
 * announcement: an instruction the resident must acknowledge rests on clause
 * 4.2.7 of the rules of internal order and cannot be switched off, while a
 * notice about a film evening rests on nothing but convenience and can. Both
 * kinds are the same object with the same fields, so one class returns one of
 * two categories rather than two classes duplicating a body.
 *
 * Nothing in the dispatch had to learn about this. `User::notify()` asks the
 * message what category it is and answers from the enumeration, which is what
 * the extension point was for.
 *
 * The message carries identifiers and a title rather than the body. The body
 * of an announcement can be long, the feed is one tap away, and a copy of it
 * sitting in the notifications table would be a second version of a text the
 * warden may yet correct.
 */
final class AnnouncementPublished extends EventNotification
{
    public function __construct(
        public readonly int $announcementId,
        public readonly string $title,
        public readonly string $category,
        public readonly bool $mandatory,
        public readonly ?string $buildingName = null,
    ) {}

    public function category(): NotificationCategory
    {
        return $this->mandatory
            ? NotificationCategory::MandatoryAnnouncement
            : NotificationCategory::Announcement;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->mandatory
                ? 'Announcement requiring acknowledgement: '.$this->title
                : 'Announcement: '.$this->title)
            ->line($this->buildingName !== null
                ? sprintf('A new announcement for %s: %s.', $this->buildingName, $this->title)
                : sprintf('A new announcement for every dormitory: %s.', $this->title));

        return $this->mandatory
            ? $message->line('Open the announcement in the application and confirm that you have read it.')
            : $message->line('You can read it in the announcements feed.');
    }

    /**
     * The in-app half. The personal account links to the announcement itself,
     * so the row holds its identifier and enough to draw a line of a list.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => $this->category()->value,
            'announcement_id' => $this->announcementId,
            'title' => $this->title,
            'announcement_category' => $this->category,
            'is_mandatory' => $this->mandatory,
            'building_name' => $this->buildingName,
        ];
    }
}

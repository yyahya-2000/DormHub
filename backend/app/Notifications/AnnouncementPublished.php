<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationCategory;

/**
 * FR-09 reaching the resident: the warden has published an announcement.
 *
 * **One category for every announcement.** There used to be a second, for the
 * announcements a resident was required to acknowledge; the acknowledgement is
 * gone from the MVP and the category went with it, because a label nothing can
 * be classified under is a label for nothing.
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
        public readonly ?string $buildingName = null,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Announcement;
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
            'building_name' => $this->buildingName,
        ];
    }
}

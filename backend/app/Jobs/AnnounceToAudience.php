<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Announcement;
use App\Notifications\AnnouncementPublished;
use App\Services\AnnouncementQuery;
use App\Services\Notifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * FR-09's fan-out, off the request thread (§4.6.1).
 *
 * **Why the fan-out is a job and the notification alone is not enough.** Every
 * `EventNotification` is already `ShouldQueue`, so each resident's message
 * lands on the queue rather than on a mail server. What is *not* queued by
 * that alone is the work of deciding who the residents are: an announcement to
 * a dormitory of several hundred places means a residency query and several
 * hundred passes through the notification gate, each one reading that person's
 * settings and their consent. Done inside the request, the warden waits on all
 * of it, and NFR-02's budget — five seconds between the event and the delivery
 * being *queued* — would be spent on arithmetic rather than on delivery.
 *
 * So the publishing request dispatches this, and this resolves the audience
 * and hands it to `Notifier`. The gate is untouched: the fan-out still goes
 * through `User::notify()`, one person at a time, which is what keeps a
 * resident who has switched the category off from receiving it (FR-34) and
 * what the facade's own `Notification::send()` would have stepped over.
 *
 * **The payload is one identifier.** `SerializesModels` writes the
 * announcement as a class name and a primary key, so a worker that picks the
 * job up reads the row as it stands — including a title the warden corrected
 * in the meantime — rather than a copy taken at the moment of publication.
 *
 * The job is dispatched after the transaction has committed, never inside it:
 * a worker that reached the row before the commit would find no such
 * announcement.
 */
final class AnnounceToAudience implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Announcement $announcement) {}

    public function handle(AnnouncementQuery $audience, Notifier $notifier): void
    {
        $announcement = $this->announcement->loadMissing('building');

        $notifier->sendOnce(
            $audience->recipientsOf($announcement),
            new AnnouncementPublished(
                announcementId: (int) $announcement->getKey(),
                title: (string) $announcement->title,
                category: $announcement->category->value,
                mandatory: (bool) $announcement->is_mandatory,
                buildingName: $announcement->building?->name,
            ),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * The one way a notification of FR-34 leaves the application to more than one
 * person.
 *
 * It is thin because there is nothing to decide: it walks the recipients and
 * calls `notify()` on each. It exists for the shape most callers of the later
 * increments have — «tell the duty officers on shift», «tell the warden and
 * the security post» — and for `sendOnce()` below, which is the part a caller
 * would otherwise get wrong: one event must not become two messages because
 * the warden of a dormitory is also its duty officer.
 */
final readonly class Notifier
{
    /**
     * @param  iterable<User>|User  $recipients
     */
    public function send(iterable|User $recipients, Notification $notification): void
    {
        $people = $recipients instanceof User ? [$recipients] : $recipients;

        foreach ($people as $person) {
            $person->notify($notification);
        }
    }

    /**
     * The same, for a query result that may hold the same person twice — the
     * warden of a building who is also its duty officer, say. One event, one
     * message.
     *
     * @param  Collection<int, User>  $recipients
     */
    public function sendOnce(Collection $recipients, Notification $notification): void
    {
        $this->send($recipients->unique(fn (User $person) => $person->getKey()), $notification);
    }
}

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
 * It looks thin because the rule it enforces lives one level down, on the
 * model: `App\Models\User::notify()` is the gate that consults the person's
 * settings and the consent behind them. This class exists for the shape most
 * callers of the later increments have — «tell the duty officers on shift»,
 * «tell the warden and the security post» — and it exists instead of
 * `Notification::send($recipients, $notification)`, which is the facade's
 * fan-out and goes **around** the model's `notify()` straight to the channel
 * dispatcher. A recipient reached that way would receive a category they had
 * switched off, and the criterion would be false for exactly the routes nobody
 * remembered to check.
 *
 * So: one road in, and it is this one.
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

<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Contracts\CategorisedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * What every notification of FR-34 has in common, so that a new one is a
 * payload and a category and nothing else.
 *
 * **One channel, and no integration behind it.** The message is a row in the
 * framework's notifications table (§3.4.1, decision 7) which the personal
 * account reads, and it goes nowhere else: constraint C-03 keeps mail,
 * messengers and SMS gateways out of the MVP. A `mail` entry in this list
 * would be an integration the work does not have — switched on by an
 * environment variable, with nobody having decided to send anything.
 * Restoring it is one more string here and no change anywhere else, which is
 * why the dispatch goes through the framework's channels rather than calling a
 * mailer.
 *
 * **Queued, always.** NFR-02 gives five seconds between the event and the
 * delivery being *queued* — not delivered — and the distinction is the design.
 * A status change that waited on the writing of the row would fail when the
 * database was slow, and the decision on a guest request is not the worker's
 * business. What the application promises, and what the tests measure, is that
 * the message reached the queue.
 *
 * **The subclass says its category and nothing about delivery.** The category
 * is the label the personal account draws the message under; nothing in the
 * MVP turns it into a decision about whether the message is sent, and a
 * notification that reasoned about delivery for itself would be the one place
 * a later rule was forgotten.
 */
abstract class EventNotification extends Notification implements CategorisedNotification, ShouldQueue
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }
}

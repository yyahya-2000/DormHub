<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Contracts\CategorisedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * What every notification of FR-34 has in common, so that a new one is a
 * subject line and a body and nothing else.
 *
 * **Two channels, and the reason there are exactly two.** FR-34 asks for an
 * in-app notification and an external one. The in-app half is the `database`
 * channel writing into the framework's own table (§3.4.1, decision 7), which
 * the personal account reads. The external half is `mail`, and it is the only
 * external channel there is: constraint C-03 keeps messengers and SMS
 * gateways out of the MVP. Lifting C-03 is one more string in this list and no
 * change anywhere else, which is the whole point of routing through the
 * framework's channel dispatch instead of calling a mailer.
 *
 * **Queued, always.** NFR-02 gives five seconds between the event and the
 * delivery being *queued* — not delivered — and the distinction is the design.
 * A status change that waited on a mail server would fail when the mail server
 * did, and the duty officer's decision is not the mail server's business. The
 * queue worker of the compose file does the sending; what the application
 * promises, and what the tests measure, is that the message reached the queue.
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
        return ['database', 'mail'];
    }
}

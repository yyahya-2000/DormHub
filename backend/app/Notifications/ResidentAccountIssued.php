<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Contracts\CategorisedNotification;
use App\Enums\NotificationCategory;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * FR-42, third criterion: the one-time credential, carried to the address
 * recorded on the resident's account instead of onto the warden's screen.
 *
 * **Why it is a notification and not a line in the response.** The criterion
 * separates the person who creates the account from the person who receives
 * the credential, and a secret returned in the HTTP response cannot keep that
 * separation: it is on the creator's screen by the time it exists. The
 * credential therefore leaves the application through a channel addressed to
 * the resident, and the response the warden reads holds no secret at all.
 *
 * **Why the channel is mail.** Constraint C-03 rules out external channels —
 * no messenger, no SMS gateway — so the one contact the MVP has is the address
 * in `users.email`. The `via()` list is where a second channel would be added
 * if that constraint were lifted; nothing else would change.
 *
 * That address is typed by the person creating the account and is not
 * confirmed before the first message goes to it; what confirms it is this
 * message being acted on — `users.email_confirmed_at` is stamped when a code
 * sent here is spent. FR-42's criterion was narrowed to say that on
 * 14.09.2026, because the wording it had before said more than the system did.
 *
 * **Why this class is not queued, and must not become so (acceptance of
 * 14.09.2026).** It was `ShouldQueue` and carried the code as a public
 * property, which meant the queue serialised the code in plain text into its
 * own store — and into `failed_jobs`, permanently, whenever a delivery failed.
 * Queueing is still what the requirement needs: creating an account must not
 * wait on a mail server. What is queued is `App\Jobs\DeliverResidentCredential`
 * instead, which carries two model identifiers and mints the code inside the
 * worker. This object is then built there and sent at once, so it is never
 * handed to a serialiser at all.
 *
 * Adding `ShouldQueue` back to this class would undo that in one word. The
 * token exists here for the length of one render, in one process, and that is
 * the whole of its life outside `password_reset_tokens`, where it is a hash.
 *
 * **Its place in FR-34 (added with that requirement).** The message is
 * categorised like every other, so that there is one dispatch and not two, and
 * its category is mandatory: an account whose credential can be switched off
 * is an account nobody can use. It does **not** extend
 * `App\Notifications\EventNotification`, and the difference is the single line
 * `via()` below. Every other notification of FR-34 is written into the
 * framework's `notifications` table as well as sent, because the personal
 * account shows it; this one carries a one-time secret, and a secret written
 * into a table is a secret that outlives its use and can be read by anything
 * that can read the table. It goes by mail and leaves no copy.
 */
final class ResidentAccountIssued extends Notification implements CategorisedNotification
{
    public function __construct(
        public readonly string $token,
        public readonly string $buildingName,
        public readonly int $expiresInMinutes,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::AccountIssued;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your dormitory account')
            ->greeting('Hello.')
            ->line(sprintf(
                'An account has been created for you at %s.',
                $this->buildingName,
            ))
            ->line('Sign-in name: '.$notifiable->email)
            ->line('One-time code: '.$this->token)
            ->line(sprintf(
                'Use the code once to set a password of your own. It stops working in %d minutes.',
                $this->expiresInMinutes,
            ))
            ->line('If it stops working before you get to it, ask the building office to send you a new one. The old code stops working the moment a new one is issued, and every code goes to this address and to nowhere else.')
            ->line('Nobody at the building office knows your password, and nobody there can read it.');
    }
}

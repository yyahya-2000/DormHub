<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * FR-42, third criterion: the one-time credential, carried to the resident's
 * confirmed contact instead of onto the warden's screen.
 *
 * **Why it is a notification and not a line in the response.** The criterion
 * separates the person who creates the account from the person who receives
 * the credential, and a secret returned in the HTTP response cannot keep that
 * separation: it is on the creator's screen by the time it exists. The
 * credential therefore leaves the application through a channel addressed to
 * the resident, and the response the warden reads holds no secret at all.
 *
 * **Why the channel is mail.** Constraint C-03 rules out external channels —
 * no messenger, no SMS gateway — so the confirmed contact of the MVP is the
 * address in `users.email`. The `via()` list is where a second channel would
 * be added if that constraint were lifted; nothing else would change.
 *
 * **Why it is queued.** A sign-in route must not wait on a mail server, and an
 * appointment must not fail because one is down. `ShouldQueue` puts the
 * delivery on the queue the compose file already runs a worker for, and the
 * test asserts the notification reached the queue rather than that a message
 * reached a mailbox, which is the only fact the application controls.
 *
 * The token itself is not stored on this object beyond the send: it is a
 * one-time value held in `password_reset_tokens` as a hash, and this message
 * is the only place it exists in plain text.
 */
final class ResidentAccountIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $token,
        public readonly string $buildingName,
        public readonly int $expiresInMinutes,
    ) {}

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
                'Use the code once to set a password of your own. It stops working in %d minutes; ask the building office for a new one if it does.',
                $this->expiresInMinutes,
            ))
            ->line('Nobody at the building office knows your password, and nobody there can read it.');
    }
}

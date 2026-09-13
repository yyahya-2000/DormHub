<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationCategory;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * FR-34, fourth occasion: a document is waiting for the person to sign it.
 *
 * Mandatory. A document awaiting signature is a legal act with a consequence
 * for the person who does not perform it, and a deadline that passes while the
 * notice was switched off is the operator's problem and not the resident's.
 * The same reasoning makes the consent text of FR-35 one of the documents this
 * message can name — see `App\Services\ConsentRegistry`, which is where the
 * first caller will sit.
 *
 * `documentCode` and `revision` are the pair that identifies *which text*, and
 * they are carried rather than the text itself: the message is stored, the
 * revision is immutable, and a stored copy of a long document would be a
 * second place the wording lives.
 */
final class DocumentAwaitingSignature extends EventNotification
{
    public function __construct(
        public readonly string $documentCode,
        public readonly string $revision,
        public readonly string $title,
        public readonly ?CarbonInterface $dueAt = null,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::DocumentSignature;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('A document is waiting for your signature')
            ->line(sprintf('«%s» (revision %s) is waiting for you.', $this->title, $this->revision));

        return $this->dueAt === null
            ? $message->line('Sign it in your personal account.')
            : $message->line(sprintf(
                'Sign it in your personal account by %s.',
                $this->dueAt->format('d.m.Y'),
            ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => $this->category()->value,
            'document_code' => $this->documentCode,
            'document_revision' => $this->revision,
            'title' => $this->title,
            'due_at' => $this->dueAt?->toIso8601String(),
        ];
    }
}

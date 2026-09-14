<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\LostFoundClaimStatus;
use App\Enums\NotificationCategory;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * FR-26, the answer half: a claim has been accepted or declined, and the
 * person who filed it is being told.
 *
 * **The Gherkin of §2.4.4 asks for two things by name and this class carries
 * both.** «The claim moves to accepted and the claimant is notified with the
 * handover point» — so an acceptance carries the place. «The claimant is
 * offered the option of referring the decision to the warden» — so a refusal
 * carries whether that offer stands, and the client draws a button from the
 * flag rather than inferring one from a status.
 *
 * `referralOffered` is false on the refusal that came from the warden, which
 * is the whole of §2.5.4's exception: the referral exists to reach a member of
 * staff, and a claim a member of staff has already refused has nowhere further
 * to go inside the module. What the claimant does then is outside the system,
 * and a button that led nowhere would be the interface promising otherwise.
 */
final class LostFoundClaimDecided extends EventNotification
{
    public function __construct(
        public readonly int $itemId,
        public readonly int $claimId,
        public readonly string $itemTitle,
        public readonly LostFoundClaimStatus $status,
        public readonly ?string $handoverPoint = null,
        public readonly ?string $note = null,
        public readonly bool $referralOffered = false,
        public readonly bool $decidedByStaff = false,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::LostFoundClaim;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(sprintf(
                'Your claim on «%s» was %s',
                $this->itemTitle,
                $this->status === LostFoundClaimStatus::Accepted ? 'accepted' : 'declined',
            ));

        if ($this->status === LostFoundClaimStatus::Accepted) {
            $message->line($this->decidedByStaff
                ? 'The warden has decided the claim in your favour.'
                : 'The person who found it says it is yours.');

            if ($this->handoverPoint !== null) {
                $message->line(sprintf('Collect it here: %s', $this->handoverPoint));
            }
        } else {
            $message->line($this->decidedByStaff
                ? 'The warden did not uphold your claim.'
                : 'The person holding it says the marks you gave do not match.');

            if ($this->referralOffered) {
                $message->line('If you disagree, you can ask the warden of your dormitory to decide.');
            }
        }

        return $this->note === null || $this->note === ''
            ? $message
            : $message->line($this->note);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => $this->category()->value,
            'lost_found_item_id' => $this->itemId,
            'lost_found_claim_id' => $this->claimId,
            'item_title' => $this->itemTitle,
            'status' => $this->status->value,
            'handover_point' => $this->handoverPoint,
            'note' => $this->note,
            'referral_offered' => $this->referralOffered,
            'decided_by_staff' => $this->decidedByStaff,
        ];
    }
}

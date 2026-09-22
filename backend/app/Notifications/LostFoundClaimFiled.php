<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationCategory;

/**
 * FR-26: somebody has claimed an entry, and the person who has to answer is
 * being told.
 *
 * **One class for the claim and for the referral, distinguished by a flag.**
 * They are the same message with a different addressee — «this is now yours to
 * decide» — and the difference is whose decision it is: the holder of the
 * object on a new claim (§2.5.4's default path), the warden and the manager of
 * the dormitory on a referral (§2.5.4's first exception). A second class would
 * have been the same four lines with one word changed.
 *
 * **The claimant's marks travel and the claimant's contacts do not.** The
 * whole point of the module routing the exchange through the system is that a
 * resident's telephone number is never published (FR-25, §3.5.3), and the
 * identifying features are what the holder has to judge the claim on.
 */
final class LostFoundClaimFiled extends EventNotification
{
    public function __construct(
        public readonly int $itemId,
        public readonly int $claimId,
        public readonly string $itemTitle,
        public readonly string $marks,
        public readonly bool $referred = false,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::LostFoundClaim;
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
            'marks' => $this->marks,
            'referred' => $this->referred,
        ];
    }
}

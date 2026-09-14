<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\TransitionableStatus;

/**
 * `LOST_FOUND_CLAIM.status` of the ER model (§3.4.3), which reads «new
 * accepted declined», plus the one state FR-26 adds to it.
 *
 * **`Referred` is the fourth, and it is the requirement's word rather than a
 * convenience.** FR-26: «a claim the two sides cannot settle is referred to
 * the warden, who decides», and the Gherkin of §2.4.4 puts the offer in the
 * claimant's hands — «the claimant is offered the option of referring the
 * decision to the warden». A referral is neither an acceptance nor a refusal
 * and neither is it a fresh claim: the finder has already answered, and what
 * is outstanding is a disagreement rather than a request. Folding it back into
 * `new` would have lost exactly the fact the warden needs, that somebody has
 * already said no.
 *
 * The ending of a referred claim is one of the two the diagram already has:
 * the warden upholds it, and the claim is `accepted`; or the warden does not,
 * and it is `declined`. A fifth state for «declined by the warden» would tell
 * the claimant nothing that `decided_by` does not, and would have to be
 * handled by every reader of the list.
 */
enum LostFoundClaimStatus: string implements TransitionableStatus
{
    /** Filed and waiting on the person holding the object (FR-26). */
    case New = 'new';

    /** The holder — or the warden on a referral — says the object is theirs. */
    case Accepted = 'accepted';

    /** The stated marks do not match, or the warden did not uphold it. */
    case Declined = 'declined';

    /** Declined, and the claimant has put the disagreement to the warden. */
    case Referred = 'referred';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Awaiting the holder',
            self::Accepted => 'Accepted',
            self::Declined => 'Declined',
            self::Referred => 'Referred to the warden',
        };
    }

    /**
     * Whether the claim is still waiting on somebody's decision.
     *
     * The question the entry's own status is computed from: FR-26's «a
     * declined claim returns the find to the published list» holds when no
     * claim is outstanding any more, and a referred claim is outstanding —
     * the disagreement is undecided, and an entry put back into the feed while
     * the warden is still looking at it would be offered to somebody else.
     */
    public function isOutstanding(): bool
    {
        return $this === self::New || $this === self::Referred;
    }

    /**
     * @return list<self>
     */
    public static function outstanding(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $status): bool => $status->isOutstanding(),
        ));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}

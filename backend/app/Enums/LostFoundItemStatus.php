<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\TransitionableStatus;

/**
 * The states of `LostFoundItem` the MVP actually reaches, out of the seven
 * §3.5.5 draws.
 *
 * **Three of the seven, and the omissions are a decision rather than an
 * oversight.** FR-24, FR-25 and FR-26 are the whole of the module inside the
 * MVP, and between them they name exactly three states: the entry is
 * published, a claim arrives on it, and the object goes back to its owner.
 * `Draft` belongs to a publication flow the module deliberately does not have
 * — §2.5.4's «publication passes through no staff approval step» means there
 * is nothing for an entry to wait in. `Archived` is the ninety-day pass of
 * §3.5.5, which is NFR-09's housekeeping threshold and has no route and no
 * scheduled command in this increment. `Hidden` is the warden's removal of an
 * improper entry, which no requirement of the MVP asks for.
 *
 * A state carried here without a route that reaches it would make the
 * enumeration describe a system that does not exist, which is the one artefact
 * that must not — the same argument `MaintenanceRequestStateMachine` makes
 * against a transition nothing can perform.
 *
 * **`Published` is the public list and `Claimed` is not.** FR-26's second
 * criterion — «a declined claim returns the find to the published list» — only
 * says something if the two are distinguishable, so the feed of FR-25 shows
 * `published` by default and a claimed entry is read by asking for it. And
 * FR-26's third criterion — «after closure the record disappears from the
 * public list» — is `Resolved` being outside the feed altogether, on any
 * filter.
 */
enum LostFoundItemStatus: string implements TransitionableStatus
{
    /** In the feed and available: nobody has claimed it (FR-24, FR-25). */
    case Published = 'published';

    /**
     * At least one claim is outstanding on the entry (FR-26).
     *
     * §3.5.5's note: «Several claims may coexist. The entry returns to
     * Published if none of them is confirmed.»
     */
    case Claimed = 'claimed';

    /** Handed back to its owner, with the moment on the row (FR-26). */
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Published => 'Published',
            self::Claimed => 'Claim received',
            self::Resolved => 'Returned to the owner',
        };
    }

    /**
     * Whether the entry has run its course. FR-26's third criterion is this
     * question asked of the feed.
     */
    public function isFinal(): bool
    {
        return $this === self::Resolved;
    }

    /**
     * Whether an entry in this state is shown in the feed of FR-25 at all.
     */
    public function isVisibleInTheFeed(): bool
    {
        return ! $this->isFinal();
    }

    /**
     * @return list<self>
     */
    public static function visibleInTheFeed(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $status): bool => $status->isVisibleInTheFeed(),
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

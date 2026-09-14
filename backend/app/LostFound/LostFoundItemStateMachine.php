<?php

declare(strict_types=1);

namespace App\LostFound;

use App\Enums\LostFoundItemStatus;
use App\Exceptions\IllegalTransitionException;

/**
 * §3.5.5's diagram, in a table, for the reason §3.5.4 gives for the guest
 * module's: «transitions are declared in a table rather than scattered as
 * conditionals in controllers».
 *
 * The value is the negative space, exactly as it is in
 * `MaintenanceRequestStateMachine`. A move that is not written here cannot
 * happen anywhere, so «can a returned object be claimed again by somebody who
 * still had the page open» has one answer, in one file, instead of one answer
 * per method that writes a status.
 *
 * **Three states out of §3.5.5's seven, and the four omissions are the MVP
 * boundary rather than an oversight.** `Draft` belongs to a publication flow
 * §2.5.4 refuses — «publication passes through no staff approval step» — so
 * there is nothing for an entry to wait in and no route that would put it
 * there. `Archived` is the ninety-day and thirty-day passes of §3.5.5, whose
 * intervals that section itself calls configuration «subject to agreement with
 * the campus directorate» and which no requirement inside the MVP asks for.
 * `Withdrawn` and `Hidden` are the author's retraction and the warden's
 * removal of an improper entry, and neither appears in FR-24, FR-25 or FR-26.
 *
 * Carrying any of the four here without the route that reaches it would make
 * the table describe a system that does not exist, which is the one artefact
 * that must not.
 *
 * **`Claimed → Published` is FR-26's second criterion and it is a real edge,
 * not a rollback.** «A declined claim returns the find to the published list»
 * — and §3.5.5's note says when: «Several claims may coexist. The entry
 * returns to Published if none of them is confirmed.» The condition is the
 * service's (`LostFoundService::decline` asks whether anything is still
 * outstanding); the move being legal at all is this table's.
 *
 * **There is no `Published → Resolved`.** An entry cannot be closed as
 * returned while nothing has been claimed on it, which is the «only» of
 * FR-26's first criterion: closure is admitted on a claim the finder accepted
 * or on a warden's decision on a referred claim, and both of those leave the
 * entry in `Claimed` on the way past. A holder who wants to close an entry
 * nobody claimed is describing a different act — a withdrawal — and the module
 * does not have one in this increment.
 */
final class LostFoundItemStateMachine
{
    /**
     * From → the states reachable from it.
     *
     * @var array<string, list<LostFoundItemStatus>>
     */
    private const TRANSITIONS = [
        LostFoundItemStatus::Published->value => [
            // §3.5.5: «a claim arrives».
            LostFoundItemStatus::Claimed,
        ],
        LostFoundItemStatus::Claimed->value => [
            // FR-26, second criterion: every claim declined.
            LostFoundItemStatus::Published,
            // FR-26, first criterion: the object went back to its owner.
            LostFoundItemStatus::Resolved,
        ],
        // FR-26, third criterion: closure is the end of the entry's life in
        // the module, and nothing follows it.
        LostFoundItemStatus::Resolved->value => [],
    ];

    public function allows(LostFoundItemStatus $from, LostFoundItemStatus $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from->value] ?? [], true);
    }

    /**
     * The form every caller uses: assert, then write.
     *
     * @throws IllegalTransitionException
     */
    public function assert(LostFoundItemStatus $from, LostFoundItemStatus $to): void
    {
        if (! $this->allows($from, $to)) {
            throw new IllegalTransitionException($from, $to);
        }
    }

    /**
     * What a client may offer on the screen, so that the interface and the
     * machine cannot drift apart — the buttons are drawn from the same table
     * that would refuse them.
     *
     * @return list<LostFoundItemStatus>
     */
    public function reachableFrom(LostFoundItemStatus $from): array
    {
        return self::TRANSITIONS[$from->value] ?? [];
    }
}

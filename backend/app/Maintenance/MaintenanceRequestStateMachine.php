<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Enums\MaintenanceRequestStatus;
use App\Exceptions\IllegalTransitionException;

/**
 * FR-38's graph, in a table, for the reason §3.5.4 gives for the guest
 * module's: «transitions are declared in a table rather than scattered as
 * conditionals in controllers».
 *
 * The value is the negative space. A move that is not written here cannot
 * happen anywhere, so «can a completed request be rejected by a warden who
 * still had the screen open» has one answer, in one file, instead of one
 * answer per method that writes a status.
 *
 * **The table is FR-38's chain and FR-39's one edge back, and nothing else.**
 * FR-38 reads «submitted → accepted → in progress → completed → closed; and
 * submitted → rejected», and the chain is kept strict: a warden cannot report
 * work complete on a request nobody ever started, because `accepted →
 * completed` is not here. The temptation to add it is real — the repair of a
 * dripping tap is over before anybody would press a second button — and it is
 * refused because FR-38's first criterion is «only the transitions of the
 * defined graph are permitted» and the graph is the requirement's, not the
 * convenient one. A warden who wants one press presses Start and Complete;
 * the work log then holds two rows and «when was it begun» has an answer.
 *
 * **`Completed → Accepted` is FR-39** and is the one edge FR-38's sentence does
 * not draw: «reopening within the window returns the request to accepted». It
 * belongs in this table rather than in a service condition for exactly the
 * reason the rest of the table does — a reopening is a transition, and a
 * transition the table does not know is a transition nothing can refuse.
 *
 * **Two moves are deliberately absent.** There is no `accepted → rejected`: a
 * request taken into work and then found to be somebody else's business is
 * refused before it is accepted, and FR-37 puts the reason on the refusal, not
 * on a withdrawal afterwards. And there is no cancellation by the reporter —
 * no route offers one in this increment, and carrying the transition without
 * the route would make the table describe a system that does not exist, which
 * is the one artefact that must not.
 */
final class MaintenanceRequestStateMachine
{
    /**
     * From → the states reachable from it.
     *
     * @var array<string, list<MaintenanceRequestStatus>>
     */
    private const TRANSITIONS = [
        MaintenanceRequestStatus::Submitted->value => [
            MaintenanceRequestStatus::Accepted,
            MaintenanceRequestStatus::Rejected,
        ],
        MaintenanceRequestStatus::Accepted->value => [
            MaintenanceRequestStatus::InProgress,
        ],
        MaintenanceRequestStatus::InProgress->value => [
            MaintenanceRequestStatus::Completed,
        ],
        MaintenanceRequestStatus::Completed->value => [
            // Confirmed by the reporter, or closed by the window running out.
            MaintenanceRequestStatus::Closed,
            // FR-39: «not fixed», inside the window.
            MaintenanceRequestStatus::Accepted,
        ],
        MaintenanceRequestStatus::Closed->value => [],
        MaintenanceRequestStatus::Rejected->value => [],
    ];

    public function allows(MaintenanceRequestStatus $from, MaintenanceRequestStatus $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from->value] ?? [], true);
    }

    /**
     * The form every caller uses: assert, then write.
     *
     * @throws IllegalTransitionException
     */
    public function assert(MaintenanceRequestStatus $from, MaintenanceRequestStatus $to): void
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
     * @return list<MaintenanceRequestStatus>
     */
    public function reachableFrom(MaintenanceRequestStatus $from): array
    {
        return self::TRANSITIONS[$from->value] ?? [];
    }
}

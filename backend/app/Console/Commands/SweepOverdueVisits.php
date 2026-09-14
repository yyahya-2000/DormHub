<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\RoleCode;
use App\Models\Building;
use App\Models\GuestVisit;
use App\Models\User;
use App\Notifications\GuestVisitOverdue;
use App\Services\CheckpointService;
use App\Services\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * FR-20, «Departure-deadline control».
 *
 * **Quarter-hourly, and not a cron entry at 23:00.** The obvious schedule is
 * one nightly run at the control time, and it is wrong for a reason NFR-09
 * makes concrete: 23:00 is *this* university's hour. Clause 2.2 of the HSE
 * rules sets it, Table 1.1 shows the sector spread around it, and the column
 * `BUILDING.visiting_to` exists so that a dormitory closing at 22:00 needs no
 * code change. A single cron entry would put this deployment's hour into the
 * deployment itself — the setting would still be editable and would no longer
 * do anything, which is the worst of the two failures because it looks like
 * it works.
 *
 * So the schedule is dumb and frequent, and the selection is what carries the
 * regime: every fifteen minutes, take the open visits whose own deadline has
 * passed. A building at 22:00 is swept from 22:00 and one at 01:00 from 01:00,
 * and the schedule knows neither number.
 *
 * **The deadline is the visit's and not the dormitory's, and this is the whole
 * of the selection.** `GUEST_VISIT.due_at` is frozen at the moment of entry as
 * the earlier of the end of the approved interval and the hour the dormitory
 * stops admitting guests (`GuestRequest::dueAt()`), so the closing hour is
 * already inside the column the query reads — and so is the other half of it.
 * An earlier version of this command took the buildings past their closing
 * hour first and looked at `due_at` only within them, which quietly made that
 * hour the *only* deadline: a guest approved until 14:00 in a dormitory
 * closing at 23:00 was overdue at 14:00 and nobody was told until 23:00. Nine hours in which the resident answerable for
 * the departure under clause 2.2 was not asked about it, on a fact the
 * `check-out` route already knew — it closes such a visit `closed_late`. One
 * condition, `due_at <= now`, is what makes the two agree.
 *
 * **Everything past its deadline, not «since the last run».** The narrower
 * window is tempting and loses visits: a run that fails, a container restarted,
 * a queue backed up for twenty minutes, and the quarter-hour in which the
 * deadline fell is gone — nobody is ever told. Idempotence is bought instead
 * with `overdue_notified_at`, which is written once and which the database
 * refuses to overwrite, so a repeat run finds nothing left to do.
 *
 * **What the command does not do.** It does not restrict anybody's movement
 * and does not claim to. FR-20 is phrased so as not to promise what software
 * cannot deliver (§2.4.2): the ground for removing a guest is the university's
 * local act and the action of the security service. The command records a fact
 * and signals a breach to the two people who can act on it.
 */
final class SweepOverdueVisits extends Command
{
    protected $signature = 'guests:sweep-overdue-visits';

    protected $description = 'Mark guest visits past their departure deadline as overdue and notify';

    public function handle(CheckpointService $checkpoint, Notifier $notifier): int
    {
        $now = CarbonImmutable::now();
        $reported = 0;

        GuestVisit::query()
            ->open()
            ->whereNull('overdue_notified_at')
            ->where('due_at', '<=', $now)
            ->with(['request.student', 'request.building'])
            ->orderBy('id')
            ->chunkById(100, function (Collection $due) use ($checkpoint, $notifier, $now, &$reported): void {
                foreach ($due as $visit) {
                    if ($checkpoint->markOverdue($visit, $now)) {
                        $this->announce($notifier, $visit);
                        $reported++;
                    }
                }
            });

        $this->info(sprintf('Overdue visits reported: %d.', $reported));

        return self::SUCCESS;
    }

    /**
     * FR-20, second criterion: «an overdue visit creates one event and two
     * notifications, sent to the inviting resident and to the security post».
     *
     * One event is the `guest_visit.overdue` row `markOverdue()` wrote. The two
     * notifications are here, and the second of them is a set rather than a
     * person: a post is manned by whoever is on shift, so it is addressed to
     * the security officers of that dormitory. `Notifier::sendOnce` is what
     * keeps an officer who is also on another grant from being told twice.
     *
     * Clause 2.2 makes the resident answerable for the departure, so this is a
     * notice the dormitory owes rather than one it offers.
     */
    private function announce(Notifier $notifier, GuestVisit $visit): void
    {
        $request = $visit->request;
        $building = $request?->building;

        if ($request === null || $building === null || $visit->due_at === null) {
            return;
        }

        $message = new GuestVisitOverdue(
            visitId: (int) $visit->getKey(),
            guestName: (string) $request->guest_full_name,
            buildingName: (string) $building->name,
            dueAt: $visit->due_at,
        );

        $student = $request->student;

        if ($student !== null) {
            $notifier->send($student, $message);
        }

        $notifier->sendOnce($this->securityPostOf($building), $message);
    }

    /**
     * @return Collection<int, User>
     */
    private function securityPostOf(Building $building): Collection
    {
        return User::query()
            ->whereHas('roleGrants', fn (Builder $grant) => $grant
                ->where('building_id', $building->getKey())
                ->whereHas('role', fn (Builder $role) => $role->where('code', RoleCode::SecurityOfficer->value)))
            ->get();
    }
}

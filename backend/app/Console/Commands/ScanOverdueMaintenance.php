<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Building;
use App\Models\MaintenanceRequest;
use App\Notifications\MaintenanceOverdueDigest;
use App\Services\MaintenanceQueue;
use App\Services\MaintenanceService;
use App\Services\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * FR-40, fourth criterion: «requests older than the configured threshold are
 * flagged overdue», and §3.5.2's nightly `ScanOverdueMaintenance` — «digest of
 * overdue requests» to the warden, with a row in the audit log.
 *
 * **The threshold is configuration and this command does not read it.** FR-40
 * states the rule as a property of the deployment — «the overdue threshold is
 * configuration, not code» — and the way to keep a rule of that shape is to
 * have exactly one object read the setting. `MaintenanceQueue` is that object;
 * the command asks it for the overdue requests and for the number, so a test
 * that moves the setting moves the queue, the digest and the card on the
 * resident's screen together, with no code edit anywhere.
 *
 * **Two ways of being late, and both are the queue's business rather than this
 * command's.** A request past its planned completion date has broken a promise
 * made to a named resident; a request older than the threshold with no date at
 * all has never been triaged, which is the backlog the flag exists to surface.
 * `MaintenanceQueue::overdueEverywhere()` is where the disjunction lives, so
 * the digest and the screen cannot disagree about which requests are late.
 *
 * **Nothing here is a transition.** An overdue request is still `accepted` or
 * `in_progress`; what changed is the date. So the pass writes to `audit_logs`
 * and never to `maintenance_work_logs`, which records movements — a digest
 * that wrote a work-log row every night would bury the history of the repair
 * under a history of the calendar.
 *
 * Daily rather than quarter-hourly, unlike the guest module's sweeps: nothing
 * here is measured against an hour of the day, and a threshold of several days
 * is not made more accurate by being checked ninety-six times as often.
 */
final class ScanOverdueMaintenance extends Command
{
    protected $signature = 'maintenance:scan-overdue';

    protected $description = 'Flag maintenance requests past the configured threshold and send the warden the digest';

    public function handle(MaintenanceQueue $queue, MaintenanceService $maintenance, Notifier $notifier): int
    {
        $now = CarbonImmutable::now();
        $threshold = $queue->overdueAfterDays();

        /** @var array<int, list<MaintenanceRequest>> $byBuilding */
        $byBuilding = [];
        $flagged = 0;

        $queue->overdueEverywhere($now)
            ->orderBy('id')
            ->chunkById(100, function (Collection $late) use ($maintenance, $threshold, &$byBuilding, &$flagged): void {
                foreach ($late as $request) {
                    $maintenance->recordOverdue($request, $threshold);

                    $byBuilding[(int) $request->building_id][] = $request;
                    $flagged++;
                }
            });

        foreach ($byBuilding as $buildingId => $requests) {
            $this->announce($maintenance, $notifier, $buildingId, $requests, $threshold, $now);
        }

        $this->info(sprintf(
            'Maintenance requests flagged overdue past %d day(s): %d, across %d dormitory(ies).',
            $threshold,
            $flagged,
            count($byBuilding),
        ));

        return self::SUCCESS;
    }

    /**
     * One message per dormitory and not one per request — see
     * `MaintenanceOverdueDigest` for why that is the design and not an economy.
     *
     * `Notifier::sendOnce` is what keeps a warden who is also the manager of
     * the same building from being told twice about one night.
     *
     * @param  list<MaintenanceRequest>  $requests
     */
    private function announce(
        MaintenanceService $maintenance,
        Notifier $notifier,
        int $buildingId,
        array $requests,
        int $threshold,
        CarbonImmutable $now,
    ): void {
        $building = Building::query()->find($buildingId);

        if ($building === null || $requests === []) {
            return;
        }

        $oldest = $requests[0];

        foreach ($requests as $request) {
            if ($request->ageInDaysAt($now) > $oldest->ageInDaysAt($now)) {
                $oldest = $request;
            }
        }

        $notifier->sendOnce(
            $maintenance->triageStaffOf($building),
            new MaintenanceOverdueDigest(
                buildingName: (string) $building->name,
                overdueCount: count($requests),
                thresholdDays: $threshold,
                oldestRequestId: (int) $oldest->getKey(),
                oldestAgeDays: $oldest->ageInDaysAt($now),
            ),
        );
    }
}

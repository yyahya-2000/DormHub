<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MaintenanceRequestStatus;
use App\Models\MaintenanceRequest;
use App\Services\MaintenanceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * FR-39, second criterion: «a request not confirmed within the window closes
 * automatically».
 *
 * **Why the requirement exists at all.** The module's central decision is that
 * the reporter closes the request and the person who did the work does not
 * (§3.5.2). Taken alone, that decision has an obvious failure mode: a resident
 * who has moved out, stopped reading the application or simply lost interest
 * leaves a completed request open for ever, and a queue that fills with
 * requests nobody is waiting on is a queue nobody reads. This pass is the
 * answer, and it is careful not to undo the decision it protects — the request
 * closes **marked as automatically closed and not as confirmed**, so a report
 * counting confirmed repairs counts repairs a resident actually agreed were
 * done.
 *
 * **The window is configuration, not a constant.** FR-39 says «configurable»
 * and §3.5.2 draws seven days in the same breath as calling the interval
 * configuration. `MaintenanceService` holds the figure, taken from
 * `config/dormitory.php`, and this command asks the service rather than the
 * configuration so that the number the resident was shown on the card and the
 * number the pass acts on cannot be two different numbers.
 *
 * **Daily and not hourly.** Unlike the guest module's quarter-hourly sweeps,
 * nothing here is measured against an hour of the day: a window of several
 * days is not made more accurate by being checked every fifteen minutes, and
 * the cost of a run is a full pass over the completed requests of every
 * dormitory. Twenty past midnight, behind the two nightly jobs already
 * scheduled, so that they do not contend for the same connection.
 */
final class AutoCloseConfirmedWork extends Command
{
    protected $signature = 'maintenance:auto-close-confirmed-work';

    protected $description = 'Close maintenance requests whose confirmation window passed with no answer from the reporter';

    public function handle(MaintenanceService $maintenance): int
    {
        $now = CarbonImmutable::now();
        $window = $maintenance->confirmationWindowDays();
        $deadline = $now->subDays($window);
        $closed = 0;

        MaintenanceRequest::query()
            ->withStatus(MaintenanceRequestStatus::Completed)
            ->whereNotNull('completed_at')
            // The comparison is «completed at or before now minus the window»,
            // which is the same arithmetic `confirmationWindowIsOpenAt()` does
            // on a single row — written as SQL here so that the pass reads the
            // rows it will act on rather than the whole table.
            ->where('completed_at', '<=', $deadline)
            ->orderBy('id')
            ->chunkById(100, function (Collection $stale) use ($maintenance, $now, &$closed): void {
                foreach ($stale as $request) {
                    $maintenance->closeUnconfirmed($request, $now);
                    $closed++;
                }
            });

        $this->info(sprintf(
            'Requests closed with no confirmation after %d day(s): %d.',
            $window,
            $closed,
        ));

        return self::SUCCESS;
    }
}

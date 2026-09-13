<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\GuestRequestStatus;
use App\Models\GuestRequest;
use App\Services\GuestRequestService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * `ExpireStaleRequests` of §3.3.4, and the two rows of §3.5.4 that no person
 * initiates.
 *
 * **Undecided by the start of the visit → rejected.** FR-17's fourth
 * criterion, and it is stated as «treated as rejected» rather than «expired»
 * on purpose: what the resident needs to know is that no guest is coming, and
 * that is the same news whoever produced it. `decided_by` stays null, so the
 * row never claims an officer looked at it.
 *
 * **Approved, and the visit day ended with no entry → expired.** A different
 * outcome for a different fact. Nothing went wrong, nobody failed to act, the
 * guest simply did not come; the request is closed so that its code stops
 * working and so that the day's quota is not held by a visit that never
 * happened.
 *
 * Quarter-hourly rather than nightly, and the reason is in the first rule:
 * «by the start of the visit» is a moment during the day. A nightly pass would
 * leave a request pending through the whole of the afternoon it was meant to
 * cover, and the resident would learn at midnight that nobody had decided at
 * two.
 */
final class ExpireStaleGuestRequests extends Command
{
    protected $signature = 'guests:expire-stale-requests';

    protected $description = 'Reject guest requests nobody decided in time and expire approved visits that never happened';

    public function handle(GuestRequestService $requests): int
    {
        $now = CarbonImmutable::now();

        $rejected = $this->rejectUndecided($requests, $now);
        $expired = $this->expireUnused($requests, $now);

        $this->info(sprintf(
            'Undecided requests treated as rejected: %d. Approved requests that went unused: %d.',
            $rejected,
            $expired,
        ));

        return self::SUCCESS;
    }

    private function rejectUndecided(GuestRequestService $requests, CarbonImmutable $now): int
    {
        $count = 0;

        $this->pending(GuestRequestStatus::PendingReview, $now)
            ->chunkById(100, function (Collection $stale) use ($requests, $now, &$count): void {
                foreach ($stale as $request) {
                    if ($request->plannedWindow()->from->greaterThan($now)) {
                        continue;
                    }

                    $requests->expireUndecided($request, $now);
                    $count++;
                }
            });

        return $count;
    }

    private function expireUnused(GuestRequestService $requests, CarbonImmutable $now): int
    {
        $count = 0;

        $this->pending(GuestRequestStatus::Approved, $now)
            ->chunkById(100, function (Collection $stale) use ($requests, $now, &$count): void {
                foreach ($stale as $request) {
                    // The end of the interval, not the end of the calendar
                    // day: an approved visit whose window has closed with no
                    // entry is over, and holding it open until midnight would
                    // keep a live access code for hours after it could
                    // lawfully admit anybody.
                    if ($request->plannedWindow()->to->greaterThan($now)) {
                        continue;
                    }

                    $requests->expireUnused($request);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * The candidates, narrowed in SQL as far as a date can narrow them; the
     * exact moment is settled in PHP against `TimeWindow`, because the window
     * may run past midnight and a comparison on the date column alone cannot
     * know that.
     *
     * @return Builder<GuestRequest>
     */
    private function pending(GuestRequestStatus $status, CarbonImmutable $now)
    {
        return GuestRequest::query()
            ->withStatus($status)
            ->whereDate('visit_date', '<=', $now->toDateString())
            ->orderBy('id');
    }
}

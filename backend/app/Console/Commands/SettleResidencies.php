<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Residency;
use App\Services\ResidencyService;
use Illuminate\Console\Command;

/**
 * Moves the register on to today.
 *
 * FR-05 lets a termination be recorded with a date in the future, and the two
 * projections that date governs — `RESIDENCY.status` and `BED.status` — have
 * to turn over on the day itself. No request is necessarily being served that
 * morning, so something has to do it unprompted; this is that something.
 *
 * It is deliberately idempotent and deliberately dull. Every night it takes
 * the residencies whose stated date has passed while their status still says
 * `active`, marks them ended and frees their beds, and a second run the same
 * night changes nothing. Missing a night costs a stale reading and no more:
 * the rule that matters, «two people never hold one bed over overlapping
 * periods», lives in the exclusion constraint and is not waiting on this
 * command for anything.
 */
final class SettleResidencies extends Command
{
    protected $signature = 'housing:settle-residencies';

    protected $description = 'Mark residencies whose stated departure date has arrived as ended and free their beds';

    public function handle(ResidencyService $residencies): int
    {
        $settled = 0;

        Residency::query()
            ->whereNotNull('moved_out_at')
            ->whereDate('moved_out_at', '<=', now())
            ->orderBy('id')
            ->chunkById(200, function ($due) use ($residencies, &$settled): void {
                foreach ($due as $residency) {
                    if ($residencies->settle($residency)) {
                        $settled++;
                    }
                }
            });

        $this->info(sprintf('Residencies brought up to date: %d.', $settled));

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Building;
use App\Models\MaintenanceRequest;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * FR-40, «Building maintenance queue»: the warden's one screen.
 *
 * **Two lists and no filters (MVP decision of 14.09.2026).** The screen used
 * to take a status, a category, a minimum age, an overdue flag, a period and a
 * format, and the warden used none of them: what he asks of the queue is «what
 * is still on me», and once a term «what did we do about that one in March».
 * So the queue answers open requests, the archive answers finished ones, and
 * that is the whole of the parameter list beside the page. The CSV export went
 * with the period it was read by.
 *
 * **The overdue threshold is read here and nowhere else.** FR-40's third
 * criterion — «the overdue threshold is configuration, not code» — is kept by
 * this class taking the number in its constructor from `config/dormitory.php`;
 * the model's `isOverdueAt()` takes it as an argument and holds no default of
 * its own, so there is no second value to disagree with the first.
 * `ScanOverdueMaintenance` asks this object, not the configuration.
 *
 * **Scoping is a `where` and not a filter applied afterwards.** Every method
 * begins from `MaintenanceRequest::inBuilding()`, so a request of another
 * dormitory is not in the result set to be removed; there is no code path in
 * which a mistake in a filter could let one through. That is the half of the
 * horizontal-access matrix this class is tested on.
 */
final readonly class MaintenanceQueue
{
    /** The largest page a client may ask for, whatever it asks for. */
    public const MAX_PAGE_SIZE = 100;

    public function __construct(
        private int $overdueAfterDays,
        private int $pageSize,
    ) {}

    public function overdueAfterDays(): int
    {
        return $this->overdueAfterDays;
    }

    public function pageSize(): int
    {
        return $this->pageSize;
    }

    /**
     * One page of the queue, or of the archive behind it.
     *
     * The order is the one a queue is worked in: the most pressing urgency
     * first, and inside one urgency the oldest first. Sorting by age alone
     * would bury an emergency reported this morning under a wobbly chair from
     * March. The archive is read the other way round — newest first — because
     * nobody works an archive, they look something up in it.
     *
     * @return LengthAwarePaginator<int, MaintenanceRequest>
     */
    public function page(
        Building $building,
        bool $archived = false,
        ?int $perPage = null,
    ): LengthAwarePaginator {
        $query = MaintenanceRequest::query()
            ->inBuilding($building)
            ->with(['reporter', 'room', 'assignee', 'building']);

        $archived ? $query->archived() : $query->open();

        /*
         * The tie-break follows the main key in each list, which it did not
         * before (acceptance of 15.09.2026): the archive ordered `created_at`
         * descending and then broke ties by `id` ascending, so two requests
         * filed in the same second came back the wrong way round relative to
         * everything else on the page. A tie-break pointing against the sort
         * it breaks is a page boundary that moves.
         */
        if ($archived) {
            $query->orderByDesc('created_at')->orderByDesc('id');
        } else {
            $query
                ->orderByRaw($this->urgencyOrdering())
                ->orderBy('created_at')
                ->orderBy('id');
        }

        /** @var LengthAwarePaginator<int, MaintenanceRequest> $page */
        $page = $query->paginate($perPage ?? $this->pageSize);

        return $page;
    }

    /**
     * FR-40, fourth criterion, asked of every dormitory at once: the requests
     * still to be done that the threshold or a missed planned date has made
     * overdue.
     *
     * Used by `ScanOverdueMaintenance`, which is why it returns a builder and
     * not a collection — the pass reads the table in chunks and a dormitory
     * with a long backlog must not arrive in one array.
     *
     * @return Builder<MaintenanceRequest>
     */
    public function overdueEverywhere(?CarbonInterface $moment = null): Builder
    {
        $moment = CarbonImmutable::instance($moment ?? CarbonImmutable::now());

        return MaintenanceRequest::query()
            /*
             * `notDone()` and not `open()`, which is the acceptance finding of
             * 15.09.2026. `open()` is everything short of a final state and
             * that includes `completed` — work reported done and waiting for
             * the reporter to confirm it — so the nightly digest carried
             * repairs the warden had already made. §3.5.2 draws this selection
             * as «past target_date and not done».
             */
            ->notDone()
            ->where(fn (Builder $inner) => $inner
                ->where('created_at', '<=', $moment->subDays($this->overdueAfterDays))
                ->orWhere(fn (Builder $late) => $late
                    ->whereNotNull('target_date')
                    ->whereDate('target_date', '<', $moment->toDateString())));
    }

    /**
     * The queue as rows of scalars, which is what the screen draws.
     *
     * @param  Collection<int, MaintenanceRequest>  $requests
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(Collection $requests, ?CarbonInterface $moment = null): Collection
    {
        $moment = CarbonImmutable::instance($moment ?? CarbonImmutable::now());

        return $requests->map(fn (MaintenanceRequest $request): array => [
            'id' => $request->getKey(),
            'submitted_at' => $request->created_at?->toIso8601String(),
            'age_days' => $request->ageInDaysAt($moment),
            'category' => $request->category?->label(),
            'urgency' => $request->urgency?->label(),
            'place' => $request->placeDescription(),
            'status' => $request->status?->value,
            'target_date' => $request->target_date?->toDateString(),
            'overdue' => $request->isOverdueAt($this->overdueAfterDays, $moment),
            'reporter' => (string) $request->reporter?->full_name,
            'assignee' => $request->assignee?->full_name,
        ])->values();
    }

    /**
     * Emergency, then urgent, then routine — expressed as a CASE so the
     * database does the ordering and the page boundary falls where the reader
     * expects it. Sorting a page in PHP would order each page correctly and
     * the queue as a whole not at all.
     */
    private function urgencyOrdering(): string
    {
        return "CASE urgency WHEN 'emergency' THEN 0 WHEN 'urgent' THEN 1 ELSE 2 END";
    }
}

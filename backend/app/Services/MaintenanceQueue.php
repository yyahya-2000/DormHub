<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\MaintenanceCategory;
use App\Enums\MaintenanceRequestStatus;
use App\Models\Building;
use App\Models\MaintenanceRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * FR-40, «Building maintenance queue»: the warden's one screen, and the export
 * behind it.
 *
 * A query object rather than four scopes on the model, for the reason §3.3.4
 * gives for putting a scenario in a service: the filters of FR-40 are not
 * independent of one another. «Age» is computed from `created_at` and is also
 * what «overdue» is measured against; «open» is a set of statuses and the
 * status filter narrows within it; and the export is the same query with a
 * period and no page. Four scopes would have been four places for the
 * definition of «age» to differ, and the number the resident is shown is the
 * number the digest acts on.
 *
 * **The overdue threshold is read here and nowhere else.** FR-40's third
 * criterion — «the overdue threshold is configuration, not code» — is kept by
 * this class taking the number in its constructor from
 * `config/dormitory.php`; the model's `isOverdueAt()` takes it as an argument
 * and holds no default of its own, so there is no second value to disagree
 * with the first. `ScanOverdueMaintenance` asks this object, not the
 * configuration.
 *
 * **Scoping is a `where` and not a filter applied afterwards.** Every method
 * begins from `MaintenanceRequest::inBuilding()`, so a request of another
 * dormitory is not in the result set to be removed; there is no code path in
 * which a mistake in a filter could let one through. That is the half of the
 * horizontal-access matrix this class is tested on.
 */
final readonly class MaintenanceQueue
{
    /** The columns of the export, in the order FR-40 names them. */
    public const COLUMNS = [
        'id' => 'Request',
        'submitted_at' => 'Submitted',
        'age_days' => 'Age, days',
        'category' => 'Category',
        'urgency' => 'Urgency',
        'place' => 'Place',
        'status' => 'Status',
        'target_date' => 'Planned for',
        'overdue' => 'Overdue',
        'reporter' => 'Reported by',
        'assignee' => 'Assigned to',
        'reopen_count' => 'Reopened',
    ];

    public function __construct(
        private AuditRecorder $audit,
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
     * One page of the queue.
     *
     * The default, with no filter at all, is the open requests: FR-40's fourth
     * criterion says the warden «sees all open requests for their own
     * building», and a screen that opened on three years of closed ones would
     * be a different screen. Naming a status — closed included — says
     * otherwise, which is what makes the status filter a filter rather than a
     * decoration.
     *
     * The order is the one a queue is worked in: the most pressing urgency
     * first, and inside one urgency the oldest first. Sorting by age alone
     * would bury an emergency reported this morning under a wobbly chair from
     * March.
     *
     * @return Collection<int, MaintenanceRequest>
     */
    public function page(
        Building $building,
        ?MaintenanceRequestStatus $status = null,
        ?MaintenanceCategory $category = null,
        ?int $minimumAgeDays = null,
        bool $onlyOverdue = false,
        ?CarbonInterface $from = null,
        ?CarbonInterface $until = null,
        int $page = 1,
        ?int $perPage = null,
    ): Collection {
        $perPage ??= $this->pageSize;

        return $this
            ->query($building, $status, $category, $minimumAgeDays, $onlyOverdue, $from, $until)
            ->with(['reporter', 'room', 'assignee', 'building'])
            ->orderByRaw($this->urgencyOrdering())
            ->orderBy('created_at')
            ->orderBy('id')
            ->offset(max(0, $page - 1) * $perPage)
            ->limit($perPage)
            ->get();
    }

    public function count(
        Building $building,
        ?MaintenanceRequestStatus $status = null,
        ?MaintenanceCategory $category = null,
        ?int $minimumAgeDays = null,
        bool $onlyOverdue = false,
        ?CarbonInterface $from = null,
        ?CarbonInterface $until = null,
    ): int {
        return $this
            ->query($building, $status, $category, $minimumAgeDays, $onlyOverdue, $from, $until)
            ->count();
    }

    /**
     * FR-40, fourth criterion, asked of every dormitory at once: the open
     * requests the threshold or a missed planned date has made overdue.
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
            ->open()
            ->where(fn (Builder $inner) => $inner
                ->where('created_at', '<=', $moment->subDays($this->overdueAfterDays))
                ->orWhere(fn (Builder $late) => $late
                    ->whereNotNull('target_date')
                    ->whereDate('target_date', '<', $moment->toDateString())));
    }

    /**
     * The queue as rows of scalars: what the CSV is written from and what the
     * JSON export carries.
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
            'reopen_count' => (int) $request->reopen_count,
        ])->values();
    }

    /**
     * FR-40, third criterion: the same rows as CSV.
     *
     * Written through `fputcsv` into a memory stream rather than by joining
     * strings, for the reason `VisitRegister::toCsv()` gives: a description
     * contains commas and quotation marks as a matter of course, and an export
     * that breaks its own format on one row in a thousand is worse than none.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    public function toCsv(Collection $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, array_values(self::COLUMNS));

        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                static fn (string $column): string => match (true) {
                    is_bool($row[$column] ?? null) => $row[$column] ? 'yes' : 'no',
                    default => (string) ($row[$column] ?? ''),
                },
                array_keys(self::COLUMNS),
            ));
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * An export discloses a list of who reported what and when, which is the
     * same kind of act as reading the readers of an announcement — so it is
     * recorded, as §3.9.6 records that one.
     */
    public function recordExport(
        User $viewer,
        Building $building,
        CarbonInterface $from,
        CarbonInterface $until,
        string $format,
        int $rows,
        ?string $ipAddress = null,
    ): void {
        $this->audit->record(
            action: AuditAction::MaintenanceQueueExported,
            actor: $viewer,
            subject: $building,
            payload: [
                'from' => $from->toDateString(),
                'until' => $until->toDateString(),
                'format' => $format,
                'rows' => $rows,
            ],
            ipAddress: $ipAddress,
        );
    }

    /**
     * @return Builder<MaintenanceRequest>
     */
    private function query(
        Building $building,
        ?MaintenanceRequestStatus $status,
        ?MaintenanceCategory $category,
        ?int $minimumAgeDays,
        bool $onlyOverdue,
        ?CarbonInterface $from,
        ?CarbonInterface $until,
    ): Builder {
        $now = CarbonImmutable::now();

        $query = MaintenanceRequest::query()->inBuilding($building);

        if ($status !== null) {
            $query->withStatus($status);
        } else {
            $query->open();
        }

        if ($category !== null) {
            $query->where('category', $category->value);
        }

        // FR-40's «age» filter. Read as «at least this old», because that is
        // the question a warden asks — «what has been sitting here more than a
        // week» — and the answer is a slice of the table, not a band.
        if ($minimumAgeDays !== null) {
            $query->where('created_at', '<=', $now->subDays($minimumAgeDays));
        }

        if ($onlyOverdue) {
            $query->where(fn (Builder $inner) => $inner
                ->where('created_at', '<=', $now->subDays($this->overdueAfterDays))
                ->orWhere(fn (Builder $late) => $late
                    ->whereNotNull('target_date')
                    ->whereDate('target_date', '<', $now->toDateString())));
        }

        if ($from !== null && $until !== null) {
            $query->submittedBetween($from, $until);
        }

        return $query;
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

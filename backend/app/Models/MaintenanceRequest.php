<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaintenanceCategory;
use App\Enums\MaintenanceLocation;
use App\Enums\MaintenanceRequestStatus;
use App\Enums\MaintenanceUrgency;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\MaintenanceRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MAINTENANCE_REQUEST of the ER model (§3.4.3).
 *
 * What the model owns is the arithmetic of the queue: how old the request is,
 * whether it has run past the threshold FR-40 makes configuration, whether the
 * confirmation window of FR-39 is still open. None of that is a decision — the
 * decisions live in `MaintenanceService` and the transitions in
 * `MaintenanceRequestStateMachine` — but each is asked by three different
 * callers (the queue, the two scheduled passes, the API resource), and
 * computing it in three places is how the warden's screen and the nightly job
 * come to disagree about the same request.
 *
 * **Age is counted from `created_at` and from nothing else.** FR-40 says «age
 * since submission» and the Gherkin of §2.4.3 repeats it for the accepted
 * request: «the request appears in the building queue with its age counted
 * from submission». The temptation is to restart the clock on acceptance,
 * because that is when somebody became responsible; it is refused because the
 * number the resident cares about is how long they have been waiting, and a
 * clock a warden can reset by pressing a button measures the warden.
 */
#[Fillable([
    'building_id',
    'room_id',
    'reporter_id',
    'category',
    'location',
    'location_note',
    'title',
    'description',
    'urgency',
    'photo_paths',
    'status',
    'assigned_to',
    'assigned_at',
    'target_date',
    'completed_at',
    'confirmed_at',
    'closed_at',
    'auto_closed',
    'reopen_count',
])]
class MaintenanceRequest extends Model
{
    /** @use HasFactory<MaintenanceRequestFactory> */
    use HasFactory;

    /**
     * The migration's defaults, repeated so that a request is complete in
     * memory and not only after a round trip — the same reason `Building`,
     * `Room` and `GuestRequest` repeat theirs.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'submitted',
        'urgency' => 'routine',
        'photo_paths' => '[]',
        'auto_closed' => false,
        'reopen_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'building_id' => 'integer',
            'room_id' => 'integer',
            'reporter_id' => 'integer',
            'assigned_to' => 'integer',
            'category' => MaintenanceCategory::class,
            'location' => MaintenanceLocation::class,
            'urgency' => MaintenanceUrgency::class,
            'status' => MaintenanceRequestStatus::class,
            'photo_paths' => 'array',
            'assigned_at' => 'datetime',
            'target_date' => 'date',
            'completed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'closed_at' => 'datetime',
            'auto_closed' => 'boolean',
            'reopen_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Building, $this>
     */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /**
     * Null for a common area (§3.4.3, «NULL for common areas»).
     *
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * The resident who filed it — and, by FR-39, the only person who may close
     * it.
     *
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * The history, oldest first: §3.4.1's fifth decision made readable.
     *
     * @return HasMany<MaintenanceWorkLog, $this>
     */
    public function workLog(): HasMany
    {
        return $this->hasMany(MaintenanceWorkLog::class)->orderBy('id');
    }

    /**
     * Where the defect is, in words, for a screen and for the CSV of FR-40.
     */
    public function placeDescription(): string
    {
        if ($this->location === MaintenanceLocation::OwnRoom) {
            $number = $this->relationLoaded('room')
                ? $this->room?->number
                : $this->room()->value('number');

            return $number === null ? 'A room' : sprintf('Room %s', $number);
        }

        return (string) ($this->location_note ?? 'A common area');
    }

    /**
     * FR-40: «age since submission», in whole days.
     */
    public function ageInDaysAt(?CarbonInterface $moment = null): int
    {
        $submitted = $this->created_at;

        if ($submitted === null) {
            return 0;
        }

        return (int) $submitted->diffInDays($moment ?? CarbonImmutable::now(), absolute: false);
    }

    /**
     * FR-40, fourth criterion: «requests older than the configured threshold
     * are flagged overdue».
     *
     * Two ways of being late and both of them count. A request whose planned
     * completion date has passed is late against a promise somebody made to
     * the resident; a request older than the threshold is late against the
     * dormitory's own service standard, and it catches the request nobody has
     * triaged at all — which has no planned date to be late against and is
     * the case the flag exists for.
     *
     * The threshold arrives as an argument rather than being read here, so
     * that the one place it is read from configuration is
     * `MaintenanceQueue`, and a test that moves the setting moves every
     * caller at once.
     */
    public function isOverdueAt(int $thresholdDays, ?CarbonInterface $moment = null): bool
    {
        if ($this->status === null || $this->status->isFinal()) {
            return false;
        }

        /*
         * **Work that is done is not late, whatever the calendar says**
         * (acceptance of 15.09.2026). A completed request is waiting for the
         * reporter to confirm it, and FR-39 gives them a week to do so; a
         * planned date that passes during that week is a date the work was
         * finished before or after, and either way there is nothing left for
         * the warden to chase. The nightly digest used to carry those rows,
         * telling him he was late with a repair he had already made.
         *
         * `completed_at` and not the status, which is the predicate §4.5
         * indexes and the one `MaintenanceQueue::overdueEverywhere()` asks in
         * SQL — so the row this method calls overdue and the row the sweep
         * selects are the same row. Reopening clears the column, so a request
         * that comes back from `completed` starts being chased again.
         */
        if ($this->completed_at !== null) {
            return false;
        }

        $moment ??= CarbonImmutable::now();

        if ($this->target_date !== null && $this->target_date->lessThan($moment->copy()->startOfDay())) {
            return true;
        }

        return $this->ageInDaysAt($moment) >= $thresholdDays;
    }

    /**
     * FR-39: whether the reporter may still say «not fixed».
     *
     * Measured from `completed_at` and not from the last log row, because the
     * window belongs to the completion: a request completed on Monday and
     * reopened on Wednesday starts a fresh window when it is completed again,
     * and `completed_at` is rewritten at that moment.
     */
    public function confirmationWindowIsOpenAt(int $windowDays, ?CarbonInterface $moment = null): bool
    {
        if ($this->status !== MaintenanceRequestStatus::Completed || $this->completed_at === null) {
            return false;
        }

        return $this->completed_at
            ->copy()
            ->addDays($windowDays)
            ->greaterThan($moment ?? CarbonImmutable::now());
    }

    /**
     * @return list<string>
     */
    public function photoPaths(): array
    {
        $paths = $this->photo_paths;

        return is_array($paths) ? array_values(array_filter($paths, 'is_string')) : [];
    }

    /**
     * Requests of one dormitory. Every building-scoped question the queue asks
     * goes through this, so there is one place the horizontal boundary of
     * FR-07 is expressed in SQL.
     *
     * @param  Builder<MaintenanceRequest>  $query
     */
    public function scopeInBuilding(Builder $query, Building|int $building): void
    {
        $query->where(
            'building_id',
            $building instanceof Building ? $building->getKey() : $building,
        );
    }

    /**
     * @param  Builder<MaintenanceRequest>  $query
     */
    public function scopeWithStatus(Builder $query, MaintenanceRequestStatus ...$statuses): void
    {
        $query->whereIn(
            'status',
            array_map(static fn (MaintenanceRequestStatus $status): string => $status->value, $statuses),
        );
    }

    /**
     * Requests still somebody's work: the whole of FR-40's «all open requests».
     *
     * @param  Builder<MaintenanceRequest>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn(
            'status',
            array_map(
                static fn (MaintenanceRequestStatus $status): string => $status->value,
                MaintenanceRequestStatus::open(),
            ),
        );
    }

    /**
     * FR-40's queue narrowed to the work that is still outstanding: open, and
     * nobody has reported it done.
     *
     * **A scope of its own beside `open()` rather than a change to it**
     * (acceptance of 15.09.2026). The two questions are genuinely different
     * and the warden's screen needs both. His queue shows a completed request
     * — it is still his until the resident confirms it, and FR-39's window is
     * where the module's one nudge lives. The overdue sweep must not: §3.5.2
     * draws the selection as «past target_date and **not done**», §4.5 indexes
     * it as `WHERE completed_at IS NULL`, and the sweep was reading `open()`,
     * which includes `completed`. The digest told the warden he was late with
     * work he had finished and was waiting to have signed off.
     *
     * @param  Builder<MaintenanceRequest>  $query
     */
    public function scopeNotDone(Builder $query): void
    {
        $query->open()->whereNull('completed_at');
    }

    /**
     * The other half of the table: requests that have run their course, which
     * is what the archive beside FR-40's queue shows. Closed and rejected are
     * one list — both are finished, and a warden looking something up after
     * the fact does not care which ending it had until he opens the card.
     *
     * @param  Builder<MaintenanceRequest>  $query
     */
    public function scopeArchived(Builder $query): void
    {
        $query->whereIn(
            'status',
            array_map(
                static fn (MaintenanceRequestStatus $status): string => $status->value,
                array_values(array_filter(
                    MaintenanceRequestStatus::cases(),
                    static fn (MaintenanceRequestStatus $status): bool => $status->isFinal(),
                )),
            ),
        );
    }
}

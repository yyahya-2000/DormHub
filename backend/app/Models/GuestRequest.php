<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GuestRequestStatus;
use App\Exceptions\ImmutableRecordException;
use App\Guests\TimeWindow;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\GuestRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * GUEST_REQUEST of the ER model (§3.4.3).
 *
 * What the model owns is the arithmetic of the visit: which window was asked
 * for, when the guest is due to leave. None of that is a decision — the
 * decisions live in `GuestRequestService` and the transitions in
 * `GuestRequestStateMachine` — but all of it is asked by four different
 * callers, and computing it in four places is how the card at the post and the
 * sweep at the closing hour come to disagree about the same evening.
 *
 * **The guest is a name and nothing more.** No document type, no number, no
 * purpose of the visit: the paper is compared with the person at the desk, and
 * a row that held a copy of it would be personal data the dormitory has no use
 * for once the guest has gone home (§2.7.1).
 */
#[Fillable([
    'student_id',
    'building_id',
    'guest_full_name',
    'visit_date',
    'planned_from',
    'planned_to',
    'status',
    'access_code',
    'decided_by',
    'decided_at',
    'decision_comment',
])]
class GuestRequest extends Model
{
    /** @use HasFactory<GuestRequestFactory> */
    use HasFactory;

    /**
     * The migration's defaults, repeated so that a request is complete in
     * memory and not only after a round trip — the same reason `Building` and
     * `Room` repeat theirs.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending_review',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'building_id' => 'integer',
            'decided_by' => 'integer',
            'visit_date' => 'date',
            'status' => GuestRequestStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * FR-21, and the same reasoning `GuestVisit` states: a draft may be
     * dropped, a decided request may not.
     *
     * Three of clause 2.1.2's six fields are read off this row, so from the
     * decision onwards it is part of the register and not a form. The database
     * refuses the delete through `guest_requests_no_deletion` as well; this
     * refusal is the one a developer meets first and the only one that can say
     * why.
     */
    protected static function booted(): void
    {
        static::deleting(function (GuestRequest $request): void {
            if ($request->status !== GuestRequestStatus::PendingReview) {
                throw ImmutableRecordException::for(self::class, 'a delete after the decision');
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * @return BelongsTo<Building, $this>
     */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * At most one (§3.4.1, decision 4), and the unique index on
     * `guest_visits.guest_request_id` is what earns the singular.
     *
     * @return HasOne<GuestVisit, $this>
     */
    public function visit(): HasOne
    {
        return $this->hasOne(GuestVisit::class);
    }

    /**
     * The guest's own consent, taken at the post (FR-35, §2.7.1). It hangs on
     * the request because the guest has no account to hang it on.
     *
     * @return HasMany<ConsentRecord, $this>
     */
    public function consentRecords(): HasMany
    {
        return $this->hasMany(ConsentRecord::class);
    }

    /**
     * The interval the resident asked for, pinned to the visit day.
     *
     * An interval whose end is at or before its start runs past midnight —
     * see `TimeWindow` for why that is decided here rather than by comparing
     * two strings.
     */
    public function plannedWindow(): TimeWindow
    {
        return TimeWindow::on(
            $this->visit_date ?? CarbonImmutable::now(),
            (string) $this->planned_from,
            (string) $this->planned_to,
        );
    }

    /**
     * The moment the guest is due to leave: the earlier of the end of the
     * approved interval and the hour the dormitory stops admitting guests.
     *
     * Both halves are needed and neither is redundant. The interval is what
     * the duty officer approved, and a guest approved until 20:00 is overdue
     * at 20:00 however late the dormitory closes. The end of the visiting
     * window is clause 2.2's boundary and the setting NFR-09 makes
     * per-building, and it caps an interval that would otherwise run past it.
     *
     * A visit that legitimately runs past midnight — an overnight interval on
     * a dormitory whose window admits one — is not capped at all: the window
     * it was approved against already runs into the next day, so
     * `TimeWindow::on()` has moved its end there too and the comparison below
     * cannot put the deadline before the entry.
     */
    public function dueAt(?Building $building = null): CarbonImmutable
    {
        $window = $this->plannedWindow();
        $building ??= $this->building;

        if ($building === null || $window->crossesMidnight()) {
            return $window->to;
        }

        $closes = $building->visitingWindowOn($this->visit_date ?? CarbonImmutable::now())->to;

        return $closes->lessThan($window->to) ? $closes : $window->to;
    }

    /**
     * Requests of one dormitory.
     *
     * @param  Builder<GuestRequest>  $query
     */
    public function scopeInBuilding(Builder $query, Building|int $building): void
    {
        $query->where(
            'building_id',
            $building instanceof Building ? $building->getKey() : $building,
        );
    }

    /**
     * @param  Builder<GuestRequest>  $query
     */
    public function scopeWithStatus(Builder $query, GuestRequestStatus ...$statuses): void
    {
        $query->whereIn(
            'status',
            array_map(static fn (GuestRequestStatus $status): string => $status->value, $statuses),
        );
    }

    /**
     * Requests whose visit falls inside `[$from, $until]`, both days included.
     * FR-21's «arbitrary period» is asked of the register in this shape.
     *
     * @param  Builder<GuestRequest>  $query
     */
    public function scopeVisitingBetween(Builder $query, CarbonInterface $from, CarbonInterface $until): void
    {
        $query->whereBetween('visit_date', [$from->toDateString(), $until->toDateString()]);
    }
}

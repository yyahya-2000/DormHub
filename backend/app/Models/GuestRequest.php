<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GuestDocumentType;
use App\Enums\GuestRequestStatus;
use App\Exceptions\ImmutableRecordException;
use App\Guests\TimeWindow;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\GuestRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * GUEST_REQUEST of the ER model (§3.4.3).
 *
 * What the model owns is the arithmetic of the visit: which window was asked
 * for, whether it runs past midnight, when the guest is due to leave. None of
 * that is a decision — the decisions live in `GuestRequestService` and the
 * transitions in `GuestRequestStateMachine` — but all of it is asked by four
 * different callers, and computing it in four places is how the card at the
 * post and the sweep at the control time come to disagree about the same
 * evening.
 *
 * **`guest_doc_number` never leaves this object in the clear by accident.** It
 * is cast `encrypted`, so the column holds an envelope; it is `Hidden`, so
 * `toArray()` and every resource that spreads a model cannot leak it; and the
 * masked form is a method one has to call. Reading it in full goes through
 * `GuestRequestService::revealDocumentNumber()`, which records the act
 * (NFR-06, §3.9.6).
 */
#[Fillable([
    'student_id',
    'building_id',
    'guest_full_name',
    'guest_doc_type',
    'guest_doc_number',
    'is_foreign_document',
    'purpose',
    'visit_date',
    'planned_from',
    'planned_to',
    'status',
    'access_code',
    'decided_by',
    'decided_at',
    'decision_comment',
    'responsible_officer_mark',
    'responsible_officer_mark_by',
    'responsible_officer_mark_at',
])]
#[Hidden(['guest_doc_number'])]
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
        'is_foreign_document' => false,
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
            'responsible_officer_mark_by' => 'integer',
            'guest_doc_type' => GuestDocumentType::class,
            // NFR-06 and §3.4.2: stored encrypted, displayed masked.
            'guest_doc_number' => 'encrypted',
            'is_foreign_document' => 'boolean',
            'visit_date' => 'date',
            'status' => GuestRequestStatus::class,
            'decided_at' => 'datetime',
            'responsible_officer_mark_at' => 'datetime',
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
     * FR-23: whether the visit runs past midnight into another day.
     *
     * The question the second criterion is written in — «an interval extending
     * beyond one day» — and the reason the arithmetic above has to be honest
     * about midnight. On a dormitory that keeps clause 2.2's window this is
     * always false and the criterion never bites; on one whose regime admits
     * an overnight guest it bites, and a foreign document then needs the mark.
     */
    public function spansMoreThanOneDay(): bool
    {
        return $this->plannedWindow()->crossesMidnight();
    }

    /**
     * The moment the guest is due to leave: the earlier of the end of the
     * approved interval and the building's control time.
     *
     * Both halves are needed and neither is redundant. The interval is what
     * the duty officer approved, and a guest approved until 20:00 is overdue
     * at 20:00 whatever the building's curfew says. The control time is clause
     * 2.2's boundary and the setting NFR-09 makes per-building, and it caps
     * an interval that would otherwise run past it.
     *
     * A visit that legitimately runs past midnight — an overnight interval on
     * a dormitory whose window admits one — is not capped by the curfew: the
     * curfew belongs to the visit day, the guest is staying beyond it with the
     * responsible officer's mark, and applying it would make the deadline fall
     * before the entry.
     *
     * **A curfew at or before the start of the interval belongs to the next
     * day**, by `TimeWindow`'s one rule, and this is the other way the deadline
     * could fall before the entry. A dormitory configured 08:00–23:00 with the
     * control time at 08:00 — a plausible reading of «closes in the morning» —
     * made every visit overdue from the moment it was recorded: the curfew read
     * as this morning's, which had already passed. The visit day of a guest
     * admitted at 14:00 does not end at eight that same morning; either the
     * hour belongs to the following day, in which case nothing is capped, or
     * the dormitory means something the column cannot express.
     */
    public function dueAt(?Building $building = null): CarbonImmutable
    {
        $window = $this->plannedWindow();
        $building ??= $this->building;

        if ($building === null || $window->crossesMidnight()) {
            return $window->to;
        }

        $curfew = TimeWindow::at(
            CarbonImmutable::parse(($this->visit_date ?? CarbonImmutable::now())->toDateString()),
            (string) $building->curfew_at,
        );

        if ($curfew->lessThanOrEqualTo($window->from)) {
            $curfew = $curfew->addDay();
        }

        return $curfew->lessThan($window->to) ? $curfew : $window->to;
    }

    /**
     * NFR-06: what a screen is allowed to show.
     *
     * The last four characters, which is what a comparison against the
     * document in the officer's hand needs and no more. A number shorter than
     * that is masked entirely rather than half-shown — a two-character number
     * is not a real document number, and revealing all of it because it is
     * short would be the mask failing on exactly the inputs nobody tested.
     */
    public function maskedDocumentNumber(): string
    {
        $number = (string) $this->guest_doc_number;
        $visible = 4;

        if (Str::length($number) <= $visible) {
            return str_repeat('•', max(Str::length($number), $visible));
        }

        return str_repeat('•', Str::length($number) - $visible).Str::substr($number, -$visible);
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

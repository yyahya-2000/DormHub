<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ResidencyStatus;
use Carbon\CarbonInterface;
use Database\Factories\ResidencyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RESIDENCY of the ER model (§3.4.3), historical by §3.4.1's third decision.
 *
 * Two different questions are asked of this table and they have different
 * answers, which is the subtlety FR-05 turns on.
 *
 * **Is the record closed?** `moved_out_at IS NOT NULL`. That is a fact about
 * the paperwork: a termination has been written down. It is *not* the same as
 * the bed being available, and reading it as though it were is the defect
 * acceptance walked through — a termination recorded for the 31st of December
 * closed the record in September and let somebody else be moved into an
 * occupied bed.
 *
 * **May the person still use the dormitory, and is the bed still theirs?**
 * `isCurrentOn($date)`. A termination may be recorded with a date in the
 * future, and until that date arrives the person is still living there.
 * FR-05's third criterion sets the boundary from the other side — access ends
 * **no later than** the stated date — so the comparison is strict: on the
 * stated date itself, access is already gone, and the bed is free from that
 * morning for the next occupant.
 *
 * FR-03 is asked of the period rather than of either flag, and it is asked of
 * the database: `residencies_bed_no_overlap` and `residencies_user_no_overlap`
 * exclude two rows whose `[moved_in_at, moved_out_at)` ranges intersect on one
 * bed, or on one person. `scopeOverlapping()` below asks the same question in
 * SQL, and it is used only to *show* the row that stands in the way — never to
 * decide, because a read that precedes a write decides nothing that a
 * concurrent request cannot undo.
 */
#[Fillable([
    'user_id',
    'bed_id',
    'contract_number',
    'moved_in_at',
    'moved_out_at',
    'moved_out_ground',
    'status',
])]
class Residency extends Model
{
    /** @use HasFactory<ResidencyFactory> */
    use HasFactory;

    /**
     * The migration's default, repeated so that a residency is active in
     * memory from the moment it is instantiated.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'bed_id' => 'integer',
            'moved_in_at' => 'date',
            'moved_out_at' => 'date',
            'status' => ResidencyStatus::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Bed, $this>
     */
    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }

    /**
     * Whether the residency still holds its bed.
     */
    public function isOpen(): bool
    {
        return $this->moved_out_at === null;
    }

    /**
     * Whether the person is still resident on the given day, which is the
     * question the building-scoped policies ask (FR-05, third criterion).
     */
    public function isCurrentOn(CarbonInterface $date): bool
    {
        if ($this->moved_in_at !== null && $this->moved_in_at->greaterThan($date)) {
            return false;
        }

        return $this->moved_out_at === null || $this->moved_out_at->greaterThan($date);
    }

    /**
     * Open residencies: those still holding a bed.
     *
     * @param  Builder<Residency>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('moved_out_at');
    }

    /**
     * Residencies in force on the given day, termination dates in the future
     * included.
     *
     * @param  Builder<Residency>  $query
     */
    public function scopeCurrentOn(Builder $query, CarbonInterface $date): void
    {
        $query
            ->whereDate('moved_in_at', '<=', $date)
            ->where(function (Builder $inner) use ($date): void {
                $inner->whereNull('moved_out_at')->orWhereDate('moved_out_at', '>', $date);
            });
    }

    /**
     * Residencies whose period intersects `[$from, $until)`, with an absent
     * `$until` meaning «and onwards, without end».
     *
     * The same arithmetic the exclusion constraint performs, expressed in the
     * query builder so that the conflicting record can be shown to the warden
     * after the database has refused the insert. The bounds are closed below
     * and open above, exactly as in the constraint, so a residency ending on
     * the day another begins is not a conflict here either.
     *
     * @param  Builder<Residency>  $query
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $from, ?CarbonInterface $until = null): void
    {
        $query->where(function (Builder $inner) use ($from): void {
            $inner->whereNull('moved_out_at')->orWhereDate('moved_out_at', '>', $from);
        });

        if ($until !== null) {
            $query->whereDate('moved_in_at', '<', $until);
        }
    }

    /**
     * Residencies inside one building. The building sits two joins away —
     * BUILDING → ROOM → BED → RESIDENCY — and every building-scoped question
     * about residents travels this path.
     *
     * @param  Builder<Residency>  $query
     */
    public function scopeInBuilding(Builder $query, Building|int $building): void
    {
        $buildingId = $building instanceof Building ? $building->getKey() : $building;

        $query->whereHas(
            'bed.room',
            fn (Builder $inner) => $inner->where('building_id', $buildingId)
        );
    }
}

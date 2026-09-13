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
 * **Is the bed taken?** `moved_out_at IS NULL`. The moment a termination date
 * is written the bed is free and may receive somebody else, which is the
 * second criterion of FR-05 — «the bed becomes free automatically after
 * eviction».
 *
 * **May the person still use the dormitory?** `isCurrentOn($date)`. A
 * termination may be recorded with a date in the future, and until that date
 * arrives the person is still living there. FR-05's third criterion sets the
 * boundary from the other side — access ends **no later than** the stated
 * date — so the comparison is strict: on the stated date itself, access is
 * already gone.
 */
#[Fillable([
    'user_id',
    'bed_id',
    'contract_number',
    'moved_in_at',
    'moved_in_ground',
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

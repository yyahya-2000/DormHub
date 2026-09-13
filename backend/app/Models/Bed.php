<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BedStatus;
use Database\Factories\BedFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * BED of the ER model (§3.4.3): the unit of residency (§3.4.1, decision 2).
 *
 * `activeResidency` is a `HasOne` over a table that holds many rows per bed,
 * and the singular is earned rather than assumed: `residencies_active_bed_uniq`
 * makes at most one of them open at any moment.
 */
#[Fillable(['room_id', 'label', 'status'])]
class Bed extends Model
{
    /** @use HasFactory<BedFactory> */
    use HasFactory;

    /**
     * The migration's default, repeated so that a bed is free in memory from
     * the moment it is instantiated.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => 'free',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'room_id' => 'integer',
            'status' => BedStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return HasMany<Residency, $this>
     */
    public function residencies(): HasMany
    {
        return $this->hasMany(Residency::class);
    }

    /**
     * The open residency, if there is one. At most one can exist, and the
     * partial unique index rather than this relation is what guarantees it.
     *
     * @return HasOne<Residency, $this>
     */
    public function activeResidency(): HasOne
    {
        return $this->hasOne(Residency::class)->whereNull('moved_out_at');
    }

    public function isOccupied(): bool
    {
        return $this->status === BedStatus::Occupied;
    }

    public function isAssignable(): bool
    {
        return $this->status->isAssignable();
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BedStatus;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ROOM of the ER model (§3.4.3).
 *
 * `capacity` is the number of beds the room may hold, and the two accessors
 * below are the arithmetic FR-02's criterion is written in: how many beds
 * exist, and how many places are still free. They read a loaded relation
 * rather than issuing a query of their own, so a list of forty rooms costs
 * two queries and not eighty.
 */
#[Fillable(['building_id', 'number', 'floor', 'capacity', 'type', 'status'])]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    /**
     * The same defaults the migration writes, repeated on the model so that an
     * instance created without them is complete in memory and not only after a
     * round trip to the database. A resource that reads `type` off a freshly
     * created row would otherwise find null.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'type' => 'corridor',
        'status' => 'in_service',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'building_id' => 'integer',
            'floor' => 'integer',
            'capacity' => 'integer',
            'type' => RoomType::class,
            'status' => RoomStatus::class,
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
     * @return HasMany<Bed, $this>
     */
    public function beds(): HasMany
    {
        return $this->hasMany(Bed::class);
    }

    /**
     * How many beds are registered in the room. This is the figure `capacity`
     * caps: FR-02 is about places, and a place exists once it is registered.
     */
    public function bedsCount(): int
    {
        return $this->relationLoaded('beds')
            ? $this->beds->count()
            : $this->beds()->count();
    }

    /**
     * How many further places may still be **registered** in this room:
     * capacity minus the beds that exist. This is the remainder FR-02's
     * rejection has to name, and it is deliberately not «places somebody could
     * move into» — a room of four beds with one occupant has nought free
     * places by this reckoning and three vacant beds by the next one.
     *
     * The two were confused for one number until acceptance read the register
     * of a half-empty room and saw zero. They are now both sent, under names
     * that say which is which.
     *
     * Never negative: the register refuses to create the bed that would make
     * it so, and a capacity lowered below the beds already registered reads as
     * zero rather than as a number nobody can act on.
     */
    public function freePlaces(): int
    {
        return max(0, $this->capacity - $this->bedsCount());
    }

    /**
     * How many registered places are free for somebody to move into today.
     *
     * A blocked bed counts for neither side: nobody holds it and nobody may be
     * put in it, which is the one state `BED.status` exists to express.
     */
    public function vacantBeds(): int
    {
        return $this->relationLoaded('beds')
            ? $this->beds->filter(fn (Bed $bed): bool => $bed->isAssignable())->count()
            : $this->beds()->where('status', BedStatus::Free->value)->count();
    }

    public function occupiedBedsCount(): int
    {
        return $this->relationLoaded('beds')
            ? $this->beds->filter(fn (Bed $bed): bool => $bed->isOccupied())->count()
            : $this->beds()->where('status', BedStatus::Occupied->value)->count();
    }

    public function acceptsResidents(): bool
    {
        return $this->status->acceptsResidents();
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BuildingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BUILDING of the ER model (§3.4.3). The three time columns are the
 * per-building regime NFR-09 asks for; they are settings rather than
 * constants precisely because the rules they mirror change between academic
 * years.
 */
#[Fillable(['name', 'address', 'floors_count', 'visiting_from', 'visiting_to', 'curfew_at', 'is_active'])]
class Building extends Model
{
    /** @use HasFactory<BuildingFactory> */
    use HasFactory;

    /**
     * The same defaults the migration writes, repeated here for the same
     * reason `Room` and `Residency` repeat theirs: a row created without them
     * is complete in memory and not only after a round trip to the database.
     *
     * Without this, `POST /buildings` answered with `visiting_from`,
     * `visiting_to`, `curfew_at` and `is_active` all null — the model had
     * never been told what the column defaults are, and the response is built
     * from the instance that was just saved rather than from a re-read. The
     * contract declares `is_active` a required boolean, so a generated client
     * typed it `boolean` and received null on the one response that creates
     * the object.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'floors_count' => 1,
        'visiting_from' => '08:00:00',
        'visiting_to' => '23:00:00',
        'curfew_at' => '23:00:00',
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'floors_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<RoleUser, $this>
     */
    public function roleGrants(): HasMany
    {
        return $this->hasMany(RoleUser::class);
    }

    /**
     * @return HasMany<Room, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }
}

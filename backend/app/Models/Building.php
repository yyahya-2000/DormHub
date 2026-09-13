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
}

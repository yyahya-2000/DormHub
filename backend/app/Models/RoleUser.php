<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * ROLE_USER of the ER model (§3.4.3): the grant of a role to a user, scoped
 * to a building. `building_id` NULL means the grant holds over the system as
 * a whole, which in this slice is the administrator alone (§3.4.1).
 */
#[Fillable(['user_id', 'role_id', 'building_id', 'granted_by', 'granted_at'])]
class RoleUser extends Pivot
{
    public $incrementing = true;

    protected $table = 'role_user';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'building_id' => 'integer',
            'granted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Building, $this>
     */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function isSystemWide(): bool
    {
        return $this->building_id === null;
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoleCode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * ROLE of the ER model (§3.4.3). Six rows since revision 2 of the role model,
 * inserted by the reference seeder from `RoleCode::cases()` and not editable
 * through the application.
 */
#[Fillable(['code', 'name'])]
class Role extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'code' => RoleCode::class,
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(RoleUser::class)
            ->withPivot(['id', 'building_id', 'granted_by', 'granted_at']);
    }
}

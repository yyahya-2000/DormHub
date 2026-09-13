<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoleCode;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

/**
 * USER of the ER model (§3.4.3).
 *
 * The model carries the one domain invariant FR-07 rests on: a role is held
 * either over the system as a whole or inside a single building, and the
 * question a policy asks is always the second form — «does this user hold this
 * role **in this building**» (§3.4.1, decision 1).
 */
#[Fillable(['external_id', 'full_name', 'email', 'phone', 'password_hash', 'status'])]
#[Hidden(['password_hash', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The ER model names the column `password_hash`, so the authentication
     * guard is pointed at it instead of the framework default.
     */
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->using(RoleUser::class)
            ->withPivot(['id', 'building_id', 'granted_by', 'granted_at']);
    }

    /**
     * @return HasMany<RoleUser, $this>
     */
    public function roleGrants(): HasMany
    {
        return $this->hasMany(RoleUser::class);
    }

    /**
     * Whether the user holds the role at all, in any scope. Answering only
     * this question is the mistake §3.3.3 warns against; it is used for the
     * system-wide roles and as the first half of the scoped check below.
     */
    public function hasRole(RoleCode $code): bool
    {
        return $this->grants()->contains(
            fn (RoleUser $grant): bool => $grant->role?->code === $code
        );
    }

    /**
     * Whether the user holds the role **over this building**. A system-wide
     * grant, which carries no building, satisfies the question for every
     * building; a scoped grant satisfies it for its own building only.
     */
    public function hasRoleInBuilding(RoleCode $code, Building|int|null $building): bool
    {
        $buildingId = $building instanceof Building ? $building->getKey() : $building;

        return $this->grants()->contains(function (RoleUser $grant) use ($code, $buildingId): bool {
            if ($grant->role?->code !== $code) {
                return false;
            }

            return $grant->building_id === null || $grant->building_id === $buildingId;
        });
    }

    public function isAdministrator(): bool
    {
        return $this->hasRole(RoleCode::Administrator);
    }

    /**
     * The buildings the user holds any staff role in. An administrator holds
     * a system-wide grant and is therefore not confined to this list; every
     * other role is.
     *
     * @return array<int, int>
     */
    public function scopedBuildingIds(): array
    {
        return $this->grants()
            ->pluck('building_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function isActive(): bool
    {
        return $this->status->canSignIn();
    }

    /**
     * @return Collection<int, RoleUser>
     */
    private function grants(): Collection
    {
        if (! $this->relationLoaded('roleGrants')) {
            $this->load('roleGrants.role');
        }

        return $this->roleGrants;
    }
}

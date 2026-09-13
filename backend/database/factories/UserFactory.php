<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RoleCode;
use App\Enums\UserStatus;
use App\Models\Building;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Generated people only. Constraint C-05 forbids real personal data anywhere
 * in the repository, including fixtures, so every name, address and document
 * that appears in a test or a seed is invented.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_id' => null,
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('+7900#######'),
            'password_hash' => static::$password ??= Hash::make('password'),
            'status' => UserStatus::Active,
            'remember_token' => Str::random(10),
        ];
    }

    public function withPassword(string $password): static
    {
        return $this->state(fn (): array => ['password_hash' => Hash::make($password)]);
    }

    public function blocked(): static
    {
        return $this->state(fn (): array => ['status' => UserStatus::Blocked]);
    }

    /**
     * Grants the role after creation. A building must be given for every role
     * but the administrator's, which is held over the system as a whole
     * (§3.4.1, decision 1).
     */
    public function withRole(RoleCode $code, ?Building $building = null): static
    {
        return $this->afterCreating(function (User $user) use ($code, $building): void {
            $role = Role::query()->where('code', $code->value)->sole();

            $user->roleGrants()->create([
                'role_id' => $role->getKey(),
                'building_id' => $code->isSystemWide() ? null : $building?->getKey(),
                'granted_at' => now(),
            ]);

            $user->unsetRelation('roleGrants');
        });
    }
}

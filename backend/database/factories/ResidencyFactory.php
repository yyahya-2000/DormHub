<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ResidencyStatus;
use App\Models\Bed;
use App\Models\Residency;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Generated residencies only: every person behind one comes from
 * `UserFactory`, and constraint C-05 keeps real personal data out of the
 * repository, fixtures included.
 *
 * @extends Factory<Residency>
 */
class ResidencyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'bed_id' => Bed::factory(),
            'contract_number' => sprintf('DOG-%d-%04d', CarbonImmutable::now()->year, fake()->unique()->numberBetween(1, 9999)),
            'moved_in_at' => CarbonImmutable::now()->subMonths(fake()->numberBetween(1, 10))->toDateString(),
            'moved_in_ground' => 'Accommodation order',
            'moved_out_at' => null,
            'moved_out_ground' => null,
            'status' => ResidencyStatus::Active,
        ];
    }

    /**
     * A residency already ended. Both columns are written together, because
     * the CHECK constraint refuses a termination date without a ground — the
     * same rule FR-05's first criterion states.
     */
    public function ended(?string $on = null, string $ground = 'End of study'): static
    {
        return $this->state(fn (): array => [
            'moved_out_at' => $on ?? CarbonImmutable::now()->subMonth()->toDateString(),
            'moved_out_ground' => $ground,
            'status' => ResidencyStatus::Ended,
        ]);
    }
}

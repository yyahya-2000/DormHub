<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Building;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Building>
 */
class BuildingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Block '.fake()->unique()->numberBetween(1, 99),
            'address' => fake()->streetAddress(),
            'floors_count' => fake()->numberBetween(4, 16),
            // Clause 2.2 of the dormitory rules: a guest is in the building
            // between 08:00 and 23:00.
            'visiting_from' => '08:00:00',
            'visiting_to' => '23:00:00',
            'curfew_at' => '23:00:00',
            'is_active' => true,
        ];
    }
}

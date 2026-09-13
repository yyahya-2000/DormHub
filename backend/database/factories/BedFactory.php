<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BedStatus;
use App\Models\Bed;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bed>
 */
class BedFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'label' => (string) fake()->unique()->numberBetween(1, 9999),
            'status' => BedStatus::Free,
        ];
    }

    public function blocked(): static
    {
        return $this->state(fn (): array => ['status' => BedStatus::Blocked]);
    }

    public function occupied(): static
    {
        return $this->state(fn (): array => ['status' => BedStatus::Occupied]);
    }
}

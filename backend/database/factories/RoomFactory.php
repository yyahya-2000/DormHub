<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BedStatus;
use App\Enums\RoomType;
use App\Models\Building;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $floor = fake()->numberBetween(1, 9);

        return [
            'building_id' => Building::factory(),
            'number' => $floor.str_pad((string) fake()->unique()->numberBetween(1, 99), 2, '0', STR_PAD_LEFT),
            'floor' => $floor,
            'capacity' => fake()->numberBetween(2, 4),
            'type' => RoomType::Corridor,
        ];
    }

    /**
     * Fills the room to its capacity with free places, which is the state most
     * tests start from: a register entry that is complete and empty.
     */
    public function withBeds(?int $count = null): static
    {
        return $this->afterCreating(function (Room $room) use ($count): void {
            $places = $count ?? $room->capacity;

            for ($index = 1; $index <= $places; $index++) {
                $room->beds()->create([
                    'label' => (string) $index,
                    'status' => BedStatus::Free,
                ]);
            }

            $room->unsetRelation('beds');
        });
    }
}

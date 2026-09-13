<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BedStatus;
use App\Enums\ResidencyStatus;
use App\Enums\RoleCode;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Enums\StudyStatus;
use App\Enums\UserStatus;
use App\Models\Bed;
use App\Models\Building;
use App\Models\Residency;
use App\Models\Role;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

/**
 * The housing register with enough in it to exercise FR-01 … FR-06 by hand:
 * two dormitories, three floors of rooms each, places in every room, and a
 * handful of residencies — one of them already ended, so the history the card
 * of FR-06 shows is not empty on a fresh database.
 *
 * **Everybody here is invented.** Constraint C-05 keeps real personal data out
 * of the repository, and a seeder is precisely where a real name would
 * otherwise creep in. The names are built from two fixed lists and a counter,
 * the contract numbers from a template, and none of them refers to a living
 * person. The shared password has no place outside a local database.
 *
 * The room numbering follows the usual convention — floor, then two digits —
 * so that `305` reads as «third floor, room 5» in the demonstration as it does
 * in a real dormitory.
 */
class HousingSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'password';

    /** Invented surnames and given names; combined by index, never sampled. */
    private const GIVEN_NAMES = [
        'Arseniy', 'Vera', 'Timur', 'Polina', 'Nikita', 'Alevtina',
        'Rostislav', 'Yulia', 'Gleb', 'Taisiya', 'Savva', 'Milana',
    ];

    private const FAMILY_NAMES = [
        'Zheltov', 'Zheltova', 'Kurbatov', 'Kurbatova', 'Shilov', 'Shilova',
        'Tregubov', 'Tregubova', 'Yakimov', 'Yakimova', 'Panfilov', 'Panfilova',
    ];

    private const CITIZENSHIPS = ['RU', 'RU', 'RU', 'BY', 'KZ', 'AM'];

    public function run(): void
    {
        $buildings = $this->buildings();

        $residentIndex = 0;

        foreach ($buildings as $building) {
            $rooms = $this->roomsOf($building);

            foreach ($rooms as $room) {
                $this->placesOf($room);
            }

            // Two of the rooms in each building receive residents, and one
            // room is left empty on purpose: the free-places report of FR-02
            // is uninteresting when nothing is free.
            foreach ($rooms->take(2) as $room) {
                foreach ($room->beds()->orderBy('label')->get() as $bed) {
                    $this->accommodate($building, $bed, $residentIndex++);
                }
            }
        }

        $this->pastResident($buildings->first(), $residentIndex);
    }

    /**
     * @return Collection<int, Building>
     */
    private function buildings(): Collection
    {
        return collect([
            ['name' => 'Block A', 'address' => '11 Sosnovaya Street, Zarechny', 'floors_count' => 9],
            ['name' => 'Block B', 'address' => '4 Klenovaya Street, Zarechny', 'floors_count' => 5],
        ])->map(fn (array $attributes): Building => Building::query()->firstOrCreate(
            ['name' => $attributes['name']],
            $attributes + [
                'visiting_from' => '08:00:00',
                'visiting_to' => '23:00:00',
                'curfew_at' => '23:00:00',
                'is_active' => true,
            ],
        ));
    }

    /**
     * @return Collection<int, Room>
     */
    private function roomsOf(Building $building): Collection
    {
        $plan = [
            ['floor' => 3, 'index' => 5, 'capacity' => 3, 'type' => RoomType::Corridor, 'status' => RoomStatus::InService],
            ['floor' => 3, 'index' => 6, 'capacity' => 2, 'type' => RoomType::Corridor, 'status' => RoomStatus::InService],
            ['floor' => 4, 'index' => 1, 'capacity' => 4, 'type' => RoomType::Block, 'status' => RoomStatus::InService],
            ['floor' => 5, 'index' => 2, 'capacity' => 2, 'type' => RoomType::Apartment, 'status' => RoomStatus::UnderRepair],
        ];

        return collect($plan)->map(fn (array $entry): Room => Room::query()->firstOrCreate(
            [
                'building_id' => $building->getKey(),
                'number' => $entry['floor'].str_pad((string) $entry['index'], 2, '0', STR_PAD_LEFT),
            ],
            [
                'floor' => $entry['floor'],
                'capacity' => $entry['capacity'],
                'type' => $entry['type'],
                'status' => $entry['status'],
            ],
        ));
    }

    /**
     * Places, exactly as many as the capacity allows. One place of the room
     * under repair is left blocked, so the state that no index can express
     * exists in the demonstration data too.
     */
    private function placesOf(Room $room): void
    {
        for ($index = 1; $index <= $room->capacity; $index++) {
            Bed::query()->firstOrCreate(
                ['room_id' => $room->getKey(), 'label' => (string) $index],
                [
                    'status' => $room->status === RoomStatus::UnderRepair && $index === 1
                        ? BedStatus::Blocked
                        : BedStatus::Free,
                ],
            );
        }
    }

    private function accommodate(Building $building, Bed $bed, int $index): void
    {
        if ($bed->status !== BedStatus::Free) {
            return;
        }

        $resident = $this->resident($building, $index);

        $existing = Residency::query()->where('bed_id', $bed->getKey())->whereNull('moved_out_at')->exists();

        if ($existing) {
            return;
        }

        Residency::query()->create([
            'user_id' => $resident->getKey(),
            'bed_id' => $bed->getKey(),
            'contract_number' => sprintf('DOG-%d-%04d', CarbonImmutable::now()->year, 1000 + $index),
            'moved_in_at' => CarbonImmutable::now()->subMonths(6)->toDateString(),
            'moved_in_ground' => 'Accommodation order of the admissions committee',
            'status' => ResidencyStatus::Active,
        ]);

        $bed->status = BedStatus::Occupied;
        $bed->save();
    }

    /**
     * One resident who has already moved out. The row survives the eviction
     * (§3.4.1, decision 3), the bed is free again, and the card of FR-06 has a
     * history to show.
     */
    private function pastResident(Building $building, int $index): void
    {
        $bed = Bed::query()
            ->whereHas('room', fn ($query) => $query->where('building_id', $building->getKey()))
            ->where('status', BedStatus::Free->value)
            ->orderBy('id')
            ->first();

        if ($bed === null) {
            return;
        }

        $resident = $this->resident($building, $index);

        Residency::query()->firstOrCreate(
            ['user_id' => $resident->getKey(), 'bed_id' => $bed->getKey()],
            [
                'contract_number' => sprintf('DOG-%d-%04d', CarbonImmutable::now()->year - 1, 900 + $index),
                'moved_in_at' => CarbonImmutable::now()->subMonths(20)->toDateString(),
                'moved_in_ground' => 'Accommodation order of the admissions committee',
                'moved_out_at' => CarbonImmutable::now()->subMonths(3)->toDateString(),
                'moved_out_ground' => 'Graduation, art. 105 cl. 2 of the Housing Code',
                'status' => ResidencyStatus::Ended,
            ],
        );
    }

    private function resident(Building $building, int $index): User
    {
        $given = self::GIVEN_NAMES[$index % count(self::GIVEN_NAMES)];
        $family = self::FAMILY_NAMES[$index % count(self::FAMILY_NAMES)];

        $user = User::query()->firstOrCreate(
            ['email' => sprintf('resident%02d@example.test', $index)],
            [
                'full_name' => $given.' '.$family,
                'phone' => sprintf('+7900%07d', 1000000 + $index),
                'study_status' => StudyStatus::Enrolled,
                'citizenship' => self::CITIZENSHIPS[$index % count(self::CITIZENSHIPS)],
                'password_hash' => Hash::make(self::DEMO_PASSWORD),
                'status' => UserStatus::Active,
            ],
        );

        $role = Role::query()->where('code', RoleCode::Resident->value)->sole();

        $user->roleGrants()->firstOrCreate(
            ['role_id' => $role->getKey(), 'building_id' => $building->getKey()],
            ['granted_at' => now()],
        );

        return $user;
    }
}

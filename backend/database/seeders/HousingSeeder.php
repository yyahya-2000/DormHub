<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BedStatus;
use App\Enums\Citizenship;
use App\Enums\ResidencyStatus;
use App\Enums\RoleCode;
use App\Enums\RoomType;
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
 * two dormitories, rooms on every storey of each, places in every room, and a
 * handful of residencies — one of them already ended, so the history the card
 * of FR-06 shows is not empty on a fresh database.
 *
 * **Every floor carries rooms, and that is a change from the first plan.** The
 * seeder used to write four rooms per dormitory on three storeys of the nine
 * a block declares, which left the floor summary of FR-02 reporting six empty
 * floors out of nine — a screen that looks broken while being perfectly
 * correct about a register nobody had filled in.
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
    /**
     * The password every seeded account shares. `password` locally, because
     * the stand is a local stand; DEMO_PASSWORD overrides it where the stand
     * is reachable from outside.
     */
    private function demoPassword(): string
    {
        return (string) env('DEMO_PASSWORD', 'password');
    }

    /**
     * The shared password, hashed once.
     *
     * Every account on the stand carries the same one, and bcrypt is slow by
     * design — at the default cost, hashing it per resident is most of the
     * time `db:seed` takes once the register covers every storey.
     */
    private ?string $passwordHash = null;

    /** Invented surnames and given names; combined by index, never sampled. */
    private const GIVEN_NAMES = [
        'Arseniy', 'Vera', 'Timur', 'Polina', 'Nikita', 'Alevtina',
        'Rostislav', 'Yulia', 'Gleb', 'Taisiya', 'Savva', 'Milana',
    ];

    private const FAMILY_NAMES = [
        'Zheltov', 'Zheltova', 'Kurbatov', 'Kurbatova', 'Shilov', 'Shilova',
        'Tregubov', 'Tregubova', 'Yakimov', 'Yakimova', 'Panfilov', 'Panfilova',
    ];

    private const CITIZENSHIPS = [
        Citizenship::Russia,
        Citizenship::Russia,
        Citizenship::Russia,
        Citizenship::Belarus,
        Citizenship::Kazakhstan,
        Citizenship::Armenia,
    ];

    public function run(): void
    {
        $buildings = $this->buildings();

        $residentIndex = 0;

        foreach ($buildings as $building) {
            $rooms = $this->roomsOf($building);

            foreach ($rooms as $room) {
                $this->placesOf($room);
            }

            /*
             * Rooms on each storey receive residents and the rest are left
             * empty on purpose. Every floor then has both an occupancy and a
             * free place, which is what makes the summary of FR-02 worth
             * looking at: a report where every line reads «0 of 0»
             * demonstrates nothing, and so does one where nothing is free.
             *
             * Every third storey takes one room rather than two, so the
             * summary is not nine identical lines. A dormitory does not fill
             * evenly, and a screen that says it does is read as a screen that
             * is not reading the register at all.
             */
            foreach ($rooms->groupBy('floor') as $floor => $onThisFloor) {
                foreach ($onThisFloor->take((int) $floor % 3 === 0 ? 1 : 2) as $room) {
                    foreach ($room->beds()->orderBy('label')->get() as $bed) {
                        $this->accommodate($building, $bed, $residentIndex++);
                    }
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
            ],
        ));
    }

    /**
     * Four rooms on every storey the dormitory declares, numbered by the usual
     * convention.
     *
     * The capacities and the types rotate rather than repeat, so a floor is
     * not four copies of one room: the register the demonstration pages
     * through has corridor rooms, block rooms and an apartment on each storey,
     * and the free-places arithmetic of FR-02 has different numbers to add up.
     *
     * The four rooms the plan used to name by hand — 305, 306, 401 and 502 —
     * all fall out of this grid at the same numbers, so a bookmark or a
     * screenshot taken against the old stand still points at a room.
     *
     * @return Collection<int, Room>
     */
    private function roomsOf(Building $building): Collection
    {
        $capacities = [3, 2, 4, 2];
        $types = [RoomType::Corridor, RoomType::Corridor, RoomType::Block, RoomType::Apartment];

        $plan = [];

        for ($floor = 1; $floor <= (int) $building->floors_count; $floor++) {
            foreach ([1, 2, 5, 6] as $position => $index) {
                $plan[] = [
                    'floor' => $floor,
                    'index' => $index,
                    'capacity' => $capacities[($floor + $position) % count($capacities)],
                    'type' => $types[($floor + $position) % count($types)],
                ];
            }
        }

        return collect($plan)->map(fn (array $entry): Room => Room::query()->firstOrCreate(
            [
                'building_id' => $building->getKey(),
                'number' => $entry['floor'].str_pad((string) $entry['index'], 2, '0', STR_PAD_LEFT),
            ],
            [
                'floor' => $entry['floor'],
                'capacity' => $entry['capacity'],
                'type' => $entry['type'],
            ],
        ));
    }

    /**
     * Places, exactly as many as the capacity allows. The first place of the
     * last room of the plan is left blocked, so the state that no index can
     * express exists in the demonstration data too.
     */
    private function placesOf(Room $room): void
    {
        for ($index = 1; $index <= $room->capacity; $index++) {
            Bed::query()->firstOrCreate(
                ['room_id' => $room->getKey(), 'label' => (string) $index],
                [
                    'status' => $room->number === '502' && $index === 1
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
                'moved_out_at' => CarbonImmutable::now()->subMonths(3)->toDateString(),
                'moved_out_ground' => 'Graduation, art. 105 cl. 2 of the Housing Code',
                'status' => ResidencyStatus::Ended,
            ],
        );
    }

    private function resident(Building $building, int $index): User
    {
        $given = self::GIVEN_NAMES[$index % count(self::GIVEN_NAMES)];

        /*
         * The two lists are ordered in pairs — a masculine form, then its
         * feminine one — so the surname has to keep the parity of the given
         * name or the stand fills up with «Vera Zheltov». The shift moves by
         * two for every lap of the given names, which is what keeps the
         * hundredth resident from being a copy of the first.
         */
        $family = self::FAMILY_NAMES[
            ($index + 2 * intdiv($index, count(self::GIVEN_NAMES))) % count(self::FAMILY_NAMES)
        ];

        $user = User::query()->firstOrCreate(
            ['email' => sprintf('resident%02d@example.test', $index)],
            [
                'full_name' => $given.' '.$family,
                'phone' => sprintf('+7900%07d', 1000000 + $index),
                'citizenship' => self::CITIZENSHIPS[$index % count(self::CITIZENSHIPS)],
                'password_hash' => $this->passwordHash ??= Hash::make($this->demoPassword()),
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

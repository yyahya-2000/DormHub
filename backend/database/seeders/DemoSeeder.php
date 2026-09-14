<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RoleCode;
use App\Enums\UserStatus;
use App\Models\Building;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The staff accounts, enough to exercise the role model by hand.
 *
 * The administrator holds the system; the remaining roles are held inside one
 * dormitory, and the manager of revision 2 is seeded **once per building** —
 * one manager to each block, which is what the arrangement is for. The others
 * stay on the first block: two wardens would say nothing the one already says.
 *
 * Every person below is invented. Constraint C-05 keeps real personal data out
 * of the repository, and a seeder is exactly the place where a real name would
 * otherwise creep in. The addresses are equally fictitious, and the shared
 * password is a development convenience that has no place outside a local
 * database.
 *
 * The seeder runs after the housing register, so every building it hands a
 * manager to already exists. Its own `firstOrCreate` on the first block is
 * kept all the same: the seeder has to stand on an empty database on its own.
 */
class DemoSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'password';

    public function run(): void
    {
        $primary = Building::query()->firstOrCreate(
            ['name' => 'Block A'],
            [
                'address' => '11 Sosnovaya Street, Zarechny',
                'floors_count' => 9,
                'visiting_from' => '08:00:00',
                'visiting_to' => '23:00:00',
            ],
        );

        $accounts = [
            ['admin@example.test', 'Anna Kovaleva', RoleCode::Administrator, null],
            ['duty@example.test', 'Pavel Orlov', RoleCode::DutyOfficer, $primary],
            ['warden@example.test', 'Marina Sizova', RoleCode::Warden, $primary],
            ['security@example.test', 'Igor Belov', RoleCode::SecurityOfficer, $primary],
            ['student@example.test', 'Dmitry Larin', RoleCode::Resident, $primary],
        ];

        foreach ($this->managers() as $manager) {
            $accounts[] = $manager;
        }

        foreach ($accounts as [$email, $fullName, $code, $building]) {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'full_name' => $fullName,
                    'phone' => null,
                    'password_hash' => Hash::make(self::DEMO_PASSWORD),
                    'status' => UserStatus::Active,
                ],
            );

            $role = Role::query()->where('code', $code->value)->sole();

            $user->roleGrants()->firstOrCreate(
                [
                    'role_id' => $role->getKey(),
                    // The administrator holds the role over the system; every
                    // other grant names the building it is confined to, and
                    // the CHECK constraint on `role_user` refuses any other
                    // shape.
                    'building_id' => $code->isSystemWide() ? null : $building?->getKey(),
                ],
                ['granted_at' => now()],
            );
        }
    }

    /**
     * One manager per dormitory, named from a fixed list by position so that a
     * second run produces the same people and never a different set.
     *
     * @return list<array{0: string, 1: string, 2: RoleCode, 3: Building}>
     */
    private function managers(): array
    {
        $names = ['Larisa Vetrova', 'Semyon Gaidukov', 'Zoya Nechaeva', 'Artyom Poluektov'];

        $managers = [];

        foreach (Building::query()->orderBy('id')->get() as $index => $building) {
            $managers[] = [
                sprintf('manager%02d@example.test', $index + 1),
                $names[$index % count($names)],
                RoleCode::Manager,
                $building,
            ];
        }

        return $managers;
    }
}

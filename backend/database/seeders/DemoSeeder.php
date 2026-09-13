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
 * One building and five accounts, enough to exercise the role model by hand.
 *
 * Every person below is invented. Constraint C-05 keeps real personal data out
 * of the repository, and a seeder is exactly the place where a real name would
 * otherwise creep in. The addresses are equally fictitious, and the shared
 * password is a development convenience that has no place outside a local
 * database.
 */
class DemoSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'password';

    public function run(): void
    {
        $building = Building::query()->firstOrCreate(
            ['name' => 'Block A'],
            [
                'address' => '11 Sosnovaya Street, Zarechny',
                'floors_count' => 9,
                'visiting_from' => '08:00:00',
                'visiting_to' => '23:00:00',
                'curfew_at' => '23:00:00',
                'is_active' => true,
            ],
        );

        $accounts = [
            ['admin@example.test', 'Anna Kovaleva', RoleCode::Administrator],
            ['duty@example.test', 'Pavel Orlov', RoleCode::DutyOfficer],
            ['warden@example.test', 'Marina Sizova', RoleCode::Warden],
            ['security@example.test', 'Igor Belov', RoleCode::SecurityOfficer],
            ['student@example.test', 'Dmitry Larin', RoleCode::Resident],
        ];

        foreach ($accounts as [$email, $fullName, $code]) {
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
                    // other grant names the building it is confined to.
                    'building_id' => $code->isSystemWide() ? null : $building->getKey(),
                ],
                ['granted_at' => now()],
            );
        }
    }
}

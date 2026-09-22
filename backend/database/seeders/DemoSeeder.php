<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BedStatus;
use App\Enums\ResidencyStatus;
use App\Enums\RoleCode;
use App\Enums\UserStatus;
use App\Models\Bed;
use App\Models\Building;
use App\Models\Residency;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
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
 * `duty@example.test` was here until revision 4 of the role model (21.09.2026)
 * merged the duty officer into the manager, and it is not replaced by a second
 * manager of the first block. Every screen that account was signed in to show
 * — the guest queue, the decision on a request, the roll of the building — is
 * reachable as `manager01@example.test`, which the loop below already creates
 * for that same block. What the stand gains is one account per role and no
 * account whose name says a role the system no longer has.
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
    /**
     * The password every seeded account shares. `password` locally, because
     * the stand is a local stand; DEMO_PASSWORD overrides it where the stand
     * is reachable from outside.
     */
    private function demoPassword(): string
    {
        return (string) env('DEMO_PASSWORD', 'password');
    }

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
                    'password_hash' => Hash::make($this->demoPassword()),
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

            /*
             * **The demo resident is given a bed** (acceptance of 15.09.2026).
             * A resident grant says what the account is; a residency says
             * where the person lives, and half the module routes ask the
             * second question — FR-16's guest request and FR-36's maintenance
             * request are open to a person who lives there and not to a person
             * whose account is scoped there. `student@example.test` held the
             * role and no bed, so the one account a demonstration signs in as
             * to show the resident's screens was answered 403 by both of them.
             *
             * The housing seeder accommodates the residents it invents itself
             * and knows nothing of this account, which is why the bed is taken
             * here and not there.
             */
            if ($code === RoleCode::Resident && $building !== null) {
                $this->accommodate($user, $building);
            }
        }
    }

    /**
     * The first free bed of the dormitory, and the register row that says the
     * person is in it. Idempotent: an account that already lives somewhere is
     * left where it is.
     */
    private function accommodate(User $resident, Building $building): void
    {
        if ($resident->openResidency()->exists()) {
            return;
        }

        /*
         * A bed nobody has ever held, and not merely a free one. The housing
         * seeder leaves one place free because the person who had it moved
         * out, and `residencies_bed_no_overlap` refuses a second row whose
         * period overlaps that history — so the obvious «first free bed»
         * lands on the one bed in the building that cannot take a move-in
         * dated six months back.
         */
        $bed = Bed::query()
            ->whereHas('room', fn ($query) => $query->where('building_id', $building->getKey()))
            ->where('status', BedStatus::Free->value)
            ->whereNotIn('id', Residency::query()->select('bed_id'))
            ->orderBy('id')
            ->first();

        if ($bed === null) {
            return;
        }

        Residency::query()->create([
            'user_id' => $resident->getKey(),
            'bed_id' => $bed->getKey(),
            'contract_number' => sprintf('DOG-%d-0001', CarbonImmutable::now()->year),
            'moved_in_at' => CarbonImmutable::now()->subMonths(6)->toDateString(),
            'status' => ResidencyStatus::Active,
        ]);

        $bed->status = BedStatus::Occupied;
        $bed->save();
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

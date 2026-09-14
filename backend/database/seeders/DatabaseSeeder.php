<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        /*
         * Reference data first: every seeder below writes grants of roles that
         * must already exist.
         *
         * The housing register comes before the staff accounts, and the order
         * is not arbitrary. Revision 2 of the role model puts a manager in
         * every dormitory, so the seeder that appoints them has to run once
         * the dormitories are there — otherwise «one per building» would mean
         * one, for the single block the staff seeder creates for itself.
         *
         * The module seeders come after the accounts for the same kind of
         * reason: a request is addressed to a person, so it needs the accounts
         * to exist. None of them invents a person of its own — if there are no
         * residents, they seed nothing.
         *
         * **`NotificationSeeder` runs last, and the acceptance of 15.09.2026
         * is why it moved there.** It used to run fourth and to invent the
         * rows its messages were about — guest request 1000+n, maintenance
         * request 3000+n moving to a status the enumeration has never had.
         * Every message it writes now quotes a row of the guest or maintenance
         * register, so those registers have to be seeded first.
         */
        $this->call([
            RoleSeeder::class,
            HousingSeeder::class,
            DemoSeeder::class,
            GuestSeeder::class,
            AnnouncementSeeder::class,
            MaintenanceSeeder::class,
            LostFoundSeeder::class,
            NotificationSeeder::class,
        ]);
    }
}

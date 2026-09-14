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
         * The personal-account seeders come last for the same kind of reason:
         * a consent belongs to a person and a notification is addressed to
         * one, so both need the accounts to exist. Neither invents a person of
         * its own — if there are no residents, they seed nothing.
         */
        $this->call([
            RoleSeeder::class,
            HousingSeeder::class,
            DemoSeeder::class,
            ConsentSeeder::class,
            NotificationSeeder::class,
            GuestSeeder::class,
            AnnouncementSeeder::class,
            MaintenanceSeeder::class,
            LostFoundSeeder::class,
        ]);
    }
}

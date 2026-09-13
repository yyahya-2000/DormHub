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
        // Reference data first: the demo accounts are grants of roles that
        // must already exist, and the housing register grants the resident
        // role to every person it accommodates.
        $this->call([
            RoleSeeder::class,
            DemoSeeder::class,
            HousingSeeder::class,
        ]);
    }
}

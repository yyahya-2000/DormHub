<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RoleCode;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * The reference seeder §4.4.2 refers to: the five roles of §1.1.4, and nothing
 * else. It is idempotent, so applying it to a database that already holds the
 * rows changes nothing.
 *
 * The colloquial Russian word for a porter-caretaker appears nowhere. The
 * entrance function belongs to the security service, and the role is named
 * «security officer» accordingly (§1.1.4).
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RoleCode::cases() as $code) {
            Role::query()->updateOrCreate(
                ['code' => $code->value],
                ['name' => $code->label()],
            );
        }
    }
}

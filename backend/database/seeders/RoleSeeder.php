<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RoleCode;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * The reference seeder §4.4.2 refers to: the five roles of §1.1.4 as revision 4
 * of the role model leaves them, and nothing else. It is idempotent, so
 * applying it to a database that already holds the rows changes nothing — and
 * an existing database picks up the manager row by being seeded again rather
 * than by a migration that would duplicate the list.
 *
 * It does not remove anything either, which is the half a seeder cannot do:
 * the duty officer's row is taken out by the migration of 21.09.2026, because
 * removing it means moving the grants that point at it first.
 *
 * The rows come from `RoleCode::cases()`, so the table and the enumeration
 * cannot disagree about which roles exist.
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

<?php

declare(strict_types=1);

namespace Tests\Feature\Authorisation;

use App\Enums\RoleCode;
use App\Models\Building;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The data half of revision 4 of the role model: what
 * `2026_09_21_100000_merge_the_duty_officer_into_the_manager` does to the
 * grants that named the role it removes.
 *
 * **Why this needs a test of its own.** `RefreshDatabase` migrates an empty
 * database, so every other test in the suite runs the migration against a
 * `roles` table with nothing in it, takes the early return and proves nothing.
 * The one case that can go wrong — a person who already held the manager's
 * grant in the building his duty officer's grant named — never appears, and it
 * is the case the partial unique index on `(user_id, role_id, building_id)`
 * turns into a failed deployment rather than a wrong row.
 *
 * So each test below puts the removed role back into `roles`, writes the
 * grants by hand, and runs the migration object directly. The file returns an
 * anonymous class, which is what makes calling `up()` a normal method call;
 * nothing here goes through the migrator, and nothing is recorded in the
 * migrations table.
 */
final class DutyOfficerMergeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `RoleCode` has no case for it any more, and that is the point of the
     * migration. The literal is the code as the enumeration carried it.
     */
    private const MERGED_ROLE = 'duty_officer';

    private Building $first;

    private Building $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->first = Building::factory()->create(['name' => 'Block 1']);
        $this->second = Building::factory()->create(['name' => 'Block 2']);
    }

    public function test_the_role_leaves_the_table_and_a_lone_grant_becomes_the_managers(): void
    {
        $officer = User::factory()->create(['email' => 'officer@example.test']);

        $this->grant($officer, $this->legacyRoleId(), $this->first);

        $this->merge();

        $this->assertDatabaseMissing('roles', ['code' => self::MERGED_ROLE]);
        $this->assertTrue($officer->fresh()->hasRoleInBuilding(RoleCode::Manager, $this->first));
        $this->assertSame(1, DB::table('role_user')->where('user_id', $officer->getKey())->count());
    }

    /**
     * The collision the migration is written around. The account keeps one
     * grant and it is the one that was already there: the manager's row is
     * untouched, so the date it was written on and the officer who wrote it
     * are the ones the account keeps, and the redundant row is the one that
     * goes.
     */
    public function test_a_person_holding_both_roles_in_one_building_keeps_the_manager_grant(): void
    {
        $person = User::factory()->create(['email' => 'both@example.test']);
        $appointer = User::factory()->create(['email' => 'warden@example.test']);

        $this->grant($person, $this->roleId(RoleCode::Manager), $this->first, [
            'granted_by' => $appointer->getKey(),
            'granted_at' => '2026-08-22 09:00:00',
        ]);
        $this->grant($person, $this->legacyRoleId(), $this->first);

        $this->merge();

        $grants = DB::table('role_user')->where('user_id', $person->getKey())->get();

        $this->assertCount(1, $grants);
        $this->assertSame($appointer->getKey(), $grants[0]->granted_by);
        $this->assertStringStartsWith('2026-08-22', (string) $grants[0]->granted_at);
    }

    /**
     * The near miss: the same person holds both roles, and not in the same
     * building. Nothing collides, so nothing is dropped — the account comes
     * out of the migration a manager of two dormitories, which is what its two
     * grants already said.
     */
    public function test_a_manager_of_another_building_loses_no_grant(): void
    {
        $person = User::factory()->create(['email' => 'split@example.test']);

        $this->grant($person, $this->roleId(RoleCode::Manager), $this->second);
        $this->grant($person, $this->legacyRoleId(), $this->first);

        $this->merge();

        $fresh = $person->fresh();

        $this->assertTrue($fresh->hasRoleInBuilding(RoleCode::Manager, $this->first));
        $this->assertTrue($fresh->hasRoleInBuilding(RoleCode::Manager, $this->second));
        $this->assertSame(2, DB::table('role_user')->where('user_id', $person->getKey())->count());
    }

    /**
     * `role_code` is the copy the CHECK constraint of §4.4.2 judges, kept
     * honest by a trigger on the column the migration writes. The migration
     * never names it, so the assertion is that the trigger is still the thing
     * that fills it.
     */
    public function test_the_promoted_grant_carries_the_managers_code(): void
    {
        $this->requirePostgres();

        $officer = User::factory()->create(['email' => 'code@example.test']);

        $this->grant($officer, $this->legacyRoleId(), $this->first);

        $this->merge();

        $this->assertSame(
            RoleCode::Manager->value,
            DB::table('role_user')->where('user_id', $officer->getKey())->value('role_code'),
        );
    }

    /**
     * Rolling back returns the row and nothing else, which is what the
     * migration's own `down()` says it does. A repair that put the grants back
     * would have to read the audit log, and no migration can be trusted to do
     * that on its own.
     */
    public function test_the_rollback_returns_an_empty_role(): void
    {
        $officer = User::factory()->create(['email' => 'rolled-back@example.test']);

        $this->grant($officer, $this->legacyRoleId(), $this->first);

        $migration = $this->migration();
        $migration->up();
        $migration->down();

        $restored = DB::table('roles')->where('code', self::MERGED_ROLE)->value('id');

        $this->assertNotNull($restored);
        $this->assertSame(0, DB::table('role_user')->where('role_id', $restored)->count());
        $this->assertTrue($officer->fresh()->hasRoleInBuilding(RoleCode::Manager, $this->first));
    }

    /**
     * The role row as the reference seeder wrote it before 21.09.2026.
     *
     * Written and read through the query builder throughout this class, and
     * not through `Role`: the model casts `code` to `RoleCode`, so hydrating a
     * row whose code the enumeration has dropped throws before any assertion
     * is reached.
     */
    private function legacyRoleId(): int
    {
        return (int) DB::table('roles')->insertGetId([
            'code' => self::MERGED_ROLE,
            'name' => 'Duty officer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function roleId(RoleCode $code): int
    {
        return (int) DB::table('roles')->where('code', $code->value)->value('id');
    }

    /**
     * Written through the query builder rather than through `StaffRegistry`,
     * because the service asks `RoleCode` for the role and the enumeration no
     * longer has the case this test needs.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function grant(User $user, int $roleId, Building $building, array $overrides = []): void
    {
        DB::table('role_user')->insert($overrides + [
            'user_id' => $user->getKey(),
            'role_id' => $roleId,
            'building_id' => $building->getKey(),
            'granted_at' => now(),
        ]);
    }

    private function merge(): void
    {
        $this->migration()->up();
    }

    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_09_21_100000_merge_the_duty_officer_into_the_manager.php'
        );
    }

    /**
     * The `role_code` column and its trigger are PostgreSQL's, and the
     * migration that adds them reports itself skipped on anything else.
     */
    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The scope constraint of a role grant is PostgreSQL-specific.');
        }
    }
}

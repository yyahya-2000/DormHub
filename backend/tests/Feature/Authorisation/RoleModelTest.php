<?php

declare(strict_types=1);

namespace Tests\Feature\Authorisation;

use App\Enums\RoleCode;
use App\Models\Building;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * FR-07, «Role model». The two tests §4.7.2 singles out are here under the
 * names the criteria are written in; the rest of the file is the route and
 * role matrix §4.7.1 asks for at the authorisation level.
 */
final class RoleModelTest extends TestCase
{
    use RefreshDatabase;

    private Building $first;

    private Building $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->first = Building::factory()->create(['name' => 'Block 1']);
        $this->second = Building::factory()->create(['name' => 'Block 2']);
    }

    public function test_five_roles_exist_administrator_warden_manager_security_officer_and_resident(): void
    {
        $this->assertSame(5, Role::query()->count());

        $this->assertEqualsCanonicalizing(
            ['admin', 'warden', 'manager', 'security', 'student'],
            Role::query()->pluck('code')->map(fn (RoleCode $code): string => $code->value)->all(),
        );
    }

    /**
     * FR-07's fourth criterion applied to the role revision 2 adds. The manager
     * stands beneath the warden inside one dormitory, so a manager's grant that
     * named no building would hand that account every dormitory there is —
     * which is the shape the CHECK constraint exists to refuse.
     */
    public function test_the_database_refuses_a_manager_granted_over_the_system(): void
    {
        $this->requirePostgres();

        $user = User::factory()->create();

        $this->assertRefused(
            'a building manager, granted over the system',
            $user,
            RoleCode::Manager,
            null,
        );

        $this->insertGrant($user, RoleCode::Manager, $this->first->getKey());

        $this->assertTrue($user->fresh()->hasRoleInBuilding(RoleCode::Manager, $this->first));
        $this->assertFalse($user->fresh()->hasRoleInBuilding(RoleCode::Manager, $this->second));
    }

    /**
     * Revision 2 in one sentence: the manager does the warden's operative work
     * and none of his appointments. The first half is asserted here on the
     * routes that exist; the second half has a file of its own
     * (`StaffAppointmentTest`).
     */
    public function test_the_manager_of_a_building_reaches_what_the_warden_of_that_building_reaches(): void
    {
        $manager = $this->userWith(RoleCode::Manager, $this->first);

        Sanctum::actingAs($manager);

        $this->getJson("/api/v1/buildings/{$this->first->id}")->assertOk();
        $this->getJson("/api/v1/buildings/{$this->first->id}/users")->assertOk();
        $this->getJson("/api/v1/buildings/{$this->first->id}/rooms")->assertOk();

        // And nothing of the dormitory next door.
        $this->getJson("/api/v1/buildings/{$this->second->id}")->assertStatus(403);
        $this->getJson("/api/v1/buildings/{$this->second->id}/users")->assertStatus(403);
        $this->getJson("/api/v1/buildings/{$this->second->id}/rooms")->assertStatus(403);
    }

    /**
     * The register of dormitories stays the administrator's. A manager must not
     * inherit it along with the warden's operative rights: FR-01's first
     * criterion names one role and revision 2 did not widen it.
     */
    public function test_the_manager_does_not_reach_the_register_of_dormitories(): void
    {
        $manager = $this->userWith(RoleCode::Manager, $this->first);

        Sanctum::actingAs($manager);

        $this->postJson('/api/v1/buildings', [
            'name' => 'Block C',
            'address' => '2 Beryozovaya Street, Zarechny',
            'floors_count' => 4,
        ])->assertStatus(403);

        $this->patchJson("/api/v1/buildings/{$this->first->id}", ['floors_count' => 3])->assertStatus(403);
        $this->deleteJson("/api/v1/buildings/{$this->first->id}")->assertStatus(403);
    }

    public function test_calling_a_protected_endpoint_with_a_token_of_the_wrong_role_is_refused_with_403(): void
    {
        $resident = $this->userWith(RoleCode::Resident, $this->first);

        Sanctum::actingAs($resident);

        // The resident belongs to this very building, so the refusal is about
        // the role and not about the scope.
        $this->getJson("/api/v1/buildings/{$this->first->id}/users")->assertStatus(403);
    }

    public function test_the_warden_of_building_1_obtains_no_resident_data_of_building_2_through_a_direct_api_call(): void
    {
        $wardenOfFirst = $this->userWith(RoleCode::Warden, $this->first);
        $this->userWith(RoleCode::Resident, $this->second, 'resident-of-second@example.test');

        Sanctum::actingAs($wardenOfFirst);

        // Own building: the roll is returned.
        $this->getJson("/api/v1/buildings/{$this->first->id}/users")->assertOk();

        // The neighbouring building: refused, and refused at the API rather
        // than by a screen that declines to draw a button.
        $this->getJson("/api/v1/buildings/{$this->second->id}/users")->assertStatus(403);
        $this->getJson("/api/v1/buildings/{$this->second->id}")->assertStatus(403);
    }

    public function test_the_roll_of_a_building_carries_only_the_people_attached_to_that_building(): void
    {
        $wardenOfFirst = $this->userWith(RoleCode::Warden, $this->first);
        $residentOfFirst = $this->userWith(RoleCode::Resident, $this->first, 'first@example.test');
        $residentOfSecond = $this->userWith(RoleCode::Resident, $this->second, 'second@example.test');

        Sanctum::actingAs($wardenOfFirst);

        $emails = collect($this->getJson("/api/v1/buildings/{$this->first->id}/users")->json('data'))
            ->pluck('email')
            ->all();

        $this->assertContains($residentOfFirst->email, $emails);
        $this->assertContains($wardenOfFirst->email, $emails);
        $this->assertNotContains($residentOfSecond->email, $emails);
    }

    public function test_rights_are_separated_by_role_and_by_building(): void
    {
        $matrix = [
            // role, building the grant names, building asked about, card, roll
            [RoleCode::Administrator, null, 'first', 200, 200],
            [RoleCode::Administrator, null, 'second', 200, 200],
            [RoleCode::Warden, 'first', 'first', 200, 200],
            [RoleCode::Warden, 'first', 'second', 403, 403],
            [RoleCode::Manager, 'first', 'first', 200, 200],
            [RoleCode::Manager, 'first', 'second', 403, 403],
            [RoleCode::SecurityOfficer, 'first', 'first', 200, 403],
            [RoleCode::SecurityOfficer, 'first', 'second', 403, 403],
            [RoleCode::Resident, 'first', 'first', 200, 403],
            [RoleCode::Resident, 'first', 'second', 403, 403],
        ];

        foreach ($matrix as $index => [$role, $granted, $asked, $cardStatus, $rollStatus]) {
            $user = $this->userWith(
                $role,
                $granted === null ? null : $this->{$granted},
                sprintf('matrix-%d@example.test', $index),
            );

            $target = $this->{$asked};

            Sanctum::actingAs($user);

            $this->getJson("/api/v1/buildings/{$target->id}")->assertStatus($cardStatus);
            $this->getJson("/api/v1/buildings/{$target->id}/users")->assertStatus($rollStatus);
        }
    }

    public function test_an_administrator_holds_the_role_over_the_system_and_every_other_grant_names_a_building(): void
    {
        $administrator = $this->userWith(RoleCode::Administrator, null);
        $warden = $this->userWith(RoleCode::Warden, $this->first, 'warden@example.test');

        $this->assertNull($administrator->roleGrants()->sole()->building_id);
        $this->assertSame($this->first->id, $warden->roleGrants()->sole()->building_id);

        $this->assertTrue($administrator->hasRoleInBuilding(RoleCode::Administrator, $this->second));
        $this->assertTrue($warden->hasRoleInBuilding(RoleCode::Warden, $this->first));
        $this->assertFalse($warden->hasRoleInBuilding(RoleCode::Warden, $this->second));
    }

    public function test_a_user_may_hold_one_role_in_one_building_and_another_role_in_another(): void
    {
        $user = $this->userWith(RoleCode::Warden, $this->first);

        $residentRole = Role::query()->where('code', RoleCode::Resident->value)->sole();
        $user->roleGrants()->create([
            'role_id' => $residentRole->getKey(),
            'building_id' => $this->second->getKey(),
            'granted_at' => now(),
        ]);
        $user->unsetRelation('roleGrants');

        Sanctum::actingAs($user);

        // The warden's roll in the first building, the resident's card only in
        // the second.
        $this->getJson("/api/v1/buildings/{$this->first->id}/users")->assertOk();
        $this->getJson("/api/v1/buildings/{$this->second->id}")->assertOk();
        $this->getJson("/api/v1/buildings/{$this->second->id}/users")->assertStatus(403);
    }

    /**
     * §3.4.1, decision 1 in the schema rather than in the seeder. The
     * acceptance run inserted a warden's grant with no building and the
     * database took it, which handed that account every dormitory.
     */
    public function test_the_database_refuses_a_grant_whose_scope_does_not_match_its_role(): void
    {
        $this->requirePostgres();

        $user = User::factory()->create();

        $this->assertRefused(
            'a role other than the administrator, granted over the system',
            $user,
            RoleCode::Warden,
            null,
        );

        $this->assertRefused(
            'the administrator, granted inside one building',
            $user,
            RoleCode::Administrator,
            $this->first->getKey(),
        );
    }

    public function test_the_database_still_accepts_the_two_shapes_the_design_allows(): void
    {
        $this->requirePostgres();

        $warden = User::factory()->create();
        $administrator = User::factory()->create();

        $this->insertGrant($warden, RoleCode::Warden, $this->first->getKey());
        $this->insertGrant($administrator, RoleCode::Administrator, null);

        $this->assertTrue($warden->fresh()->hasRoleInBuilding(RoleCode::Warden, $this->first));
        $this->assertTrue($administrator->fresh()->isAdministrator());
    }

    /**
     * The copy of the code that the constraint judges is filled by the
     * database, so a writer that has never heard of the column — every writer
     * in this application — still produces a row the constraint can read.
     */
    public function test_the_grant_carries_the_code_of_its_role_without_being_told(): void
    {
        $this->requirePostgres();

        $warden = $this->userWith(RoleCode::Warden, $this->first);

        $this->assertSame(
            RoleCode::Warden->value,
            DB::table('role_user')->where('user_id', $warden->id)->value('role_code'),
        );
    }

    private function requirePostgres(): void
    {
        if ($this->app['db']->connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The constraint is stated in PostgreSQL and exists only there.');
        }
    }

    /**
     * The insert is expected to fail, and a failed statement inside PostgreSQL
     * aborts the surrounding transaction — the very transaction RefreshDatabase
     * rolls the test back with. A savepoint keeps the rest of the test alive.
     */
    private function assertRefused(string $shape, User $user, RoleCode $role, ?int $buildingId): void
    {
        $connection = $this->app['db']->connection();
        $connection->statement('SAVEPOINT refused_grant');

        try {
            $this->insertGrant($user, $role, $buildingId);

            $this->fail(sprintf('The database accepted %s.', $shape));
        } catch (QueryException $exception) {
            $this->assertStringContainsString('role_user_scope_matches_role', $exception->getMessage());
        } finally {
            $connection->statement('ROLLBACK TO SAVEPOINT refused_grant');
        }
    }

    private function insertGrant(User $user, RoleCode $role, ?int $buildingId): void
    {
        DB::table('role_user')->insert([
            'user_id' => $user->getKey(),
            'role_id' => Role::query()->where('code', $role->value)->sole()->getKey(),
            'building_id' => $buildingId,
            'granted_at' => now(),
        ]);
    }

    private function userWith(RoleCode $role, ?Building $building, ?string $email = null): User
    {
        $factory = User::factory()->withRole($role, $building);

        return $email === null
            ? $factory->create()
            : $factory->create(['email' => $email]);
    }
}

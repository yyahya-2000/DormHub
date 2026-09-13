<?php

declare(strict_types=1);

namespace Tests\Feature\Authorisation;

use App\Enums\RoleCode;
use App\Models\Building;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_five_roles_exist_administrator_duty_officer_warden_security_officer_and_resident(): void
    {
        $this->assertSame(5, Role::query()->count());

        $this->assertEqualsCanonicalizing(
            ['admin', 'duty_officer', 'warden', 'security', 'student'],
            Role::query()->pluck('code')->map(fn (RoleCode $code): string => $code->value)->all(),
        );
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
            [RoleCode::DutyOfficer, 'first', 'first', 200, 200],
            [RoleCode::DutyOfficer, 'first', 'second', 403, 403],
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

    private function userWith(RoleCode $role, ?Building $building, ?string $email = null): User
    {
        $factory = User::factory()->withRole($role, $building);

        return $email === null
            ? $factory->create()
            : $factory->create(['email' => $email]);
    }
}

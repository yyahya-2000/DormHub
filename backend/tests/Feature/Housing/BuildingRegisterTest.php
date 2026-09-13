<?php

declare(strict_types=1);

namespace Tests\Feature\Housing;

use App\Enums\AuditAction;
use App\Enums\RoleCode;
use App\Models\Building;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * FR-01, «Register of dormitories and buildings». One test per acceptance
 * criterion, named as the criterion is written (§4.7.2).
 */
final class BuildingRegisterTest extends TestCase
{
    use RefreshDatabase;

    private Building $building;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->building = Building::factory()->create(['name' => 'Block 1']);
    }

    public function test_create_edit_and_archive_are_available_to_the_administrator_only(): void
    {
        $administrator = $this->userWith(RoleCode::Administrator, null);

        Sanctum::actingAs($administrator);

        $created = $this->postJson('/api/v1/buildings', [
            'name' => 'Block 7',
            'address' => '12 Lipovaya Street, Zarechny',
            'floors_count' => 12,
        ])->assertCreated()->json('data');

        $this->patchJson("/api/v1/buildings/{$created['id']}", ['floors_count' => 14])
            ->assertOk()
            ->assertJsonPath('data.floors_count', 14);

        $this->postJson("/api/v1/buildings/{$created['id']}/archive")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_a_warden_token_gets_403_on_create_edit_and_archive(): void
    {
        $warden = $this->userWith(RoleCode::Warden, $this->building);

        Sanctum::actingAs($warden);

        // The warden of this very building: the refusal is about the role and
        // not about the scope.
        $this->postJson('/api/v1/buildings', [
            'name' => 'Block 9',
            'address' => '3 Dubovaya Street, Zarechny',
            'floors_count' => 5,
        ])->assertStatus(403);

        $this->patchJson("/api/v1/buildings/{$this->building->id}", ['floors_count' => 4])
            ->assertStatus(403);

        $this->postJson("/api/v1/buildings/{$this->building->id}/archive")
            ->assertStatus(403);

        $this->deleteJson("/api/v1/buildings/{$this->building->id}")
            ->assertStatus(403);

        $this->assertDatabaseCount('buildings', 1);
    }

    public function test_the_other_three_roles_get_403_on_every_write_to_the_register(): void
    {
        foreach ([RoleCode::DutyOfficer, RoleCode::SecurityOfficer, RoleCode::Resident] as $index => $role) {
            Sanctum::actingAs($this->userWith($role, $this->building, sprintf('write-%d@example.test', $index)));

            $this->postJson('/api/v1/buildings', [
                'name' => 'Block '.$index,
                'address' => 'Somewhere',
                'floors_count' => 3,
            ])->assertStatus(403);

            $this->patchJson("/api/v1/buildings/{$this->building->id}", ['address' => 'Elsewhere'])
                ->assertStatus(403);

            $this->postJson("/api/v1/buildings/{$this->building->id}/archive")->assertStatus(403);
            $this->deleteJson("/api/v1/buildings/{$this->building->id}")->assertStatus(403);
        }
    }

    public function test_deleting_a_dormitory_that_has_rooms_attached_is_blocked_with_a_stated_reason(): void
    {
        Room::factory()->for($this->building)->create(['number' => '305']);

        Sanctum::actingAs($this->userWith(RoleCode::Administrator, null));

        $response = $this->deleteJson("/api/v1/buildings/{$this->building->id}")
            ->assertStatus(409)
            ->assertJsonPath('blocked_by.rooms', 1);

        // «Blocked with a stated reason»: the body says what stands in the way
        // and what to do instead, not merely that something failed.
        $this->assertStringContainsString('cannot be deleted', (string) $response->json('message'));
        $this->assertStringContainsString('room', strtolower((string) $response->json('message')));

        $this->assertDatabaseHas('buildings', ['id' => $this->building->id]);
    }

    public function test_a_dormitory_without_rooms_is_deleted(): void
    {
        Sanctum::actingAs($this->userWith(RoleCode::Administrator, null));

        $this->deleteJson("/api/v1/buildings/{$this->building->id}")->assertNoContent();

        $this->assertDatabaseMissing('buildings', ['id' => $this->building->id]);
    }

    public function test_a_refused_deletion_is_written_to_the_audit_log(): void
    {
        Room::factory()->for($this->building)->create(['number' => '401']);

        $administrator = $this->userWith(RoleCode::Administrator, null);
        Sanctum::actingAs($administrator);

        $this->deleteJson("/api/v1/buildings/{$this->building->id}")->assertStatus(409);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $administrator->id,
            'action' => AuditAction::BuildingDeletionBlocked->value,
            'subject_type' => Building::class,
            'subject_id' => $this->building->id,
            'result' => 'denied',
        ]);
    }

    public function test_every_change_to_the_register_is_written_to_the_audit_log(): void
    {
        $administrator = $this->userWith(RoleCode::Administrator, null);
        Sanctum::actingAs($administrator);

        $id = $this->postJson('/api/v1/buildings', [
            'name' => 'Block 21',
            'address' => '1 Beryozovaya Street, Zarechny',
            'floors_count' => 6,
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/buildings/{$id}", ['address' => '2 Beryozovaya Street, Zarechny'])->assertOk();
        $this->postJson("/api/v1/buildings/{$id}/archive")->assertOk();

        foreach ([AuditAction::BuildingCreated, AuditAction::BuildingUpdated, AuditAction::BuildingArchived] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'user_id' => $administrator->id,
                'action' => $action->value,
                'subject_type' => Building::class,
                'subject_id' => $id,
            ]);
        }
    }

    public function test_the_register_listing_is_scoped_to_the_buildings_the_account_holds_a_role_in(): void
    {
        $second = Building::factory()->create(['name' => 'Block 2']);

        Sanctum::actingAs($this->userWith(RoleCode::Warden, $this->building));

        $names = collect($this->getJson('/api/v1/buildings')->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['Block 1'], $names);

        Sanctum::actingAs($this->userWith(RoleCode::Administrator, null));

        $all = collect($this->getJson('/api/v1/buildings')->assertOk()->json('data'))->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['Block 1', 'Block 2'], $all);
        $this->assertContains($second->name, $all);
    }

    private function userWith(RoleCode $role, ?Building $building, ?string $email = null): User
    {
        $factory = User::factory()->withRole($role, $building);

        return $email === null ? $factory->create() : $factory->create(['email' => $email]);
    }
}

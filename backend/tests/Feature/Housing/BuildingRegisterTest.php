<?php

declare(strict_types=1);

namespace Tests\Feature\Housing;

use App\Enums\AuditAction;
use App\Enums\ResidencyStatus;
use App\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
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

    public function test_create_edit_and_delete_are_available_to_the_administrator_only(): void
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

        $this->deleteJson("/api/v1/buildings/{$created['id']}")->assertNoContent();
    }

    public function test_a_warden_token_gets_403_on_create_edit_and_delete(): void
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

        $this->deleteJson("/api/v1/buildings/{$this->building->id}")
            ->assertStatus(403);

        $this->assertDatabaseCount('buildings', 1);
    }

    public function test_the_other_two_roles_get_403_on_every_write_to_the_register(): void
    {
        foreach ([RoleCode::SecurityOfficer, RoleCode::Resident] as $index => $role) {
            Sanctum::actingAs($this->userWith($role, $this->building, sprintf('write-%d@example.test', $index)));

            $this->postJson('/api/v1/buildings', [
                'name' => 'Block '.$index,
                'address' => 'Somewhere',
                'floors_count' => 3,
            ])->assertStatus(403);

            $this->patchJson("/api/v1/buildings/{$this->building->id}", ['address' => 'Elsewhere'])
                ->assertStatus(403);

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

    public function test_a_refusal_caused_by_a_staff_grant_says_so_and_does_not_blame_the_rooms(): void
    {
        // No rooms at all, one staff appointment. The refusal used to read
        // «0 room(s) are attached to it» with `blocked_by: {rooms: 0}` beside
        // it — a sentence that contradicts itself and sends the reader to an
        // empty register.
        $this->userWith(RoleCode::Warden, $this->building, 'appointed@example.test');

        Sanctum::actingAs($this->userWith(RoleCode::Administrator, null));

        $response = $this->deleteJson("/api/v1/buildings/{$this->building->id}")
            ->assertStatus(409)
            ->assertJsonPath('blocked_by.rooms', 0)
            ->assertJsonPath('blocked_by.role_grants', 1);

        $message = (string) $response->json('message');

        $this->assertStringContainsString('role grant', $message);
        $this->assertStringNotContainsString('0 room', $message);

        // And the advice names something the API can actually do. There is no
        // route that deletes or moves a room, so none is offered.
        $this->assertStringNotContainsString('Move or delete the rooms', $message);
    }

    public function test_the_stated_reason_names_the_rooms_when_the_rooms_are_what_stand_in_the_way(): void
    {
        Room::factory()->for($this->building)->create(['number' => '305']);

        Sanctum::actingAs($this->userWith(RoleCode::Administrator, null));

        $response = $this->deleteJson("/api/v1/buildings/{$this->building->id}")
            ->assertStatus(409)
            ->assertJsonPath('blocked_by.rooms', 1);

        $this->assertStringContainsString('1 room(s)', (string) $response->json('message'));
    }

    public function test_creating_a_dormitory_answers_with_the_regime_settings_and_not_with_nulls(): void
    {
        // The contract declares the time columns non-null, and the read of the
        // same building returns them. The create used to answer null for all of
        // them, because the model did not repeat the migration's defaults and
        // the response is built from the instance that was just saved.
        Sanctum::actingAs($this->userWith(RoleCode::Administrator, null));

        $created = $this->postJson('/api/v1/buildings', [
            'name' => 'Block 33',
            'address' => '8 Klenovaya Street, Zarechny',
            'floors_count' => 9,
        ])
            ->assertCreated()
            ->assertJsonPath('data.visiting_from', '08:00:00')
            ->assertJsonPath('data.visiting_to', '23:00:00')
            ->json('data');

        // And the read of the same row agrees with the create, field for
        // field. That is the property that was broken, not the values.
        $read = $this->getJson("/api/v1/buildings/{$created['id']}")->assertOk()->json('data');

        $this->assertSame($created, $read);
    }

    /**
     * FR-16, first criterion: «the request is submitted no later than the lead
     * time **configured for the building**».
     *
     * The column has been read by the validator of a guest request since it was
     * added, and there was no way to configure it: it appeared in neither the
     * form rules nor the resource nor the contract's `Building`, so a PATCH
     * carrying it answered 200, wrote «nothing changed» to the audit log and
     * saved nothing. A setting that can only be changed with psql is not a
     * setting (NFR-09).
     */
    public function test_the_lead_time_a_dormitory_asks_for_is_read_and_written_over_the_api(): void
    {
        Sanctum::actingAs($this->userWith(RoleCode::Administrator, null));

        $this->getJson("/api/v1/buildings/{$this->building->id}")
            ->assertOk()
            ->assertJsonPath('data.guest_lead_time_hours', 0);

        $this->patchJson("/api/v1/buildings/{$this->building->id}", ['guest_lead_time_hours' => 24])
            ->assertOk()
            ->assertJsonPath('data.guest_lead_time_hours', 24);

        $this->assertSame(24, $this->building->fresh()->guest_lead_time_hours);

        $this->getJson("/api/v1/buildings/{$this->building->id}")
            ->assertOk()
            ->assertJsonPath('data.guest_lead_time_hours', 24);

        // FR-33: the log says what changed, and a change nobody recorded is
        // how the defect stayed invisible on the stand.
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::BuildingUpdated->value,
            'subject_id' => $this->building->id,
        ]);

        $this->assertContains(
            'guest_lead_time_hours',
            AuditLog::query()
                ->where('action', AuditAction::BuildingUpdated->value)
                ->latest('id')
                ->sole()
                ->payload['changed'],
        );
    }

    /**
     * NFR-09, and the dormitory `TimeWindow` was written for.
     *
     * A window from 08:00 to 02:00 is fifteen hours ending after midnight. The
     * create refused it — `visiting_to` carried `after:visiting_from` and read
     * the window as empty — while the edit accepted it, so the only way to the
     * regime was to create a lawful building and amend it. A register that
     * admits a configuration it cannot issue is two rules, not one.
     */
    public function test_a_dormitory_whose_window_runs_past_midnight_can_be_created_and_not_only_amended(): void
    {
        Sanctum::actingAs($this->userWith(RoleCode::Administrator, null));

        $created = $this->postJson('/api/v1/buildings', [
            'name' => 'Block 35',
            'address' => '3 Olkhovaya Street, Zarechny',
            'floors_count' => 5,
            'visiting_from' => '08:00:00',
            'visiting_to' => '02:00:00',
        ])
            ->assertCreated()
            ->assertJsonPath('data.visiting_to', '02:00:00')
            ->json('data');

        $window = Building::query()
            ->findOrFail($created['id'])
            ->visitingWindowOn(CarbonImmutable::parse('2026-09-14'));

        $this->assertTrue($window->crossesMidnight());
        $this->assertSame('2026-09-15 02:00:00', $window->to->format('Y-m-d H:i:s'));
    }

    public function test_a_dormitory_is_created_with_the_lead_time_it_is_given(): void
    {
        Sanctum::actingAs($this->userWith(RoleCode::Administrator, null));

        $this->postJson('/api/v1/buildings', [
            'name' => 'Block 34',
            'address' => '11 Kedrovaya Street, Zarechny',
            'floors_count' => 6,
            'guest_lead_time_hours' => 4,
        ])
            ->assertCreated()
            ->assertJsonPath('data.guest_lead_time_hours', 4);

        $this->patchJson(
            "/api/v1/buildings/{$this->building->id}",
            ['guest_lead_time_hours' => -1]
        )->assertStatus(422)->assertJsonValidationErrors('guest_lead_time_hours');
    }

    public function test_the_name_of_a_dormitory_is_unique_in_the_database_and_not_only_in_the_form(): void
    {
        Sanctum::actingAs($this->userWith(RoleCode::Administrator, null));

        $this->postJson('/api/v1/buildings', [
            'name' => 'Block 1',
            'address' => '9 Sosnovaya Street, Zarechny',
            'floors_count' => 5,
        ])->assertStatus(422)->assertJsonValidationErrors('name');

        // The form rule is what turns the collision into a field error; the
        // index is what makes the rule true when two requests race past it.
        // No service, no policy, no controller in the path.
        $this->expectException(QueryException::class);

        Building::query()->create([
            'name' => 'Block 1',
            'address' => '11 Sosnovaya Street, Zarechny',
            'floors_count' => 5,
        ]);
    }

    public function test_an_evicted_resident_no_longer_sees_the_dormitory_in_the_register_listing(): void
    {
        // The listing filtered on role grants, and eviction revokes no grant.
        // The row came back and the card behind it answered 403 — a link to a
        // door that is locked.
        $room = Room::factory()->for($this->building)->withBeds(1)->create([
            'number' => '305',
            'capacity' => 1,
        ]);

        $resident = $this->userWith(RoleCode::Resident, $this->building, 'gone@example.test');

        $residency = $resident->residencies()->create([
            'bed_id' => $room->beds()->sole()->getKey(),
            'contract_number' => 'DOG-GONE',
            'moved_in_at' => CarbonImmutable::now()->subMonths(6)->toDateString(),
        ]);

        Sanctum::actingAs($resident);
        $this->assertCount(1, $this->getJson('/api/v1/buildings')->assertOk()->json('data'));

        $residency->update([
            'moved_out_at' => CarbonImmutable::now()->subDay()->toDateString(),
            'moved_out_ground' => 'Graduation',
            'status' => ResidencyStatus::Ended,
        ]);

        $this->assertSame([], $this->getJson('/api/v1/buildings')->assertOk()->json('data'));
        $this->getJson("/api/v1/buildings/{$this->building->id}")->assertStatus(403);
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

        foreach ([AuditAction::BuildingCreated, AuditAction::BuildingUpdated] as $action) {
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

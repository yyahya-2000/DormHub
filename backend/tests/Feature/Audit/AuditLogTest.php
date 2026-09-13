<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * FR-33, «Audit log». One test per acceptance criterion, plus the two-level
 * append-only guarantee of §4.4.4.
 */
final class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private Building $building;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->building = Building::factory()->create();
    }

    public function test_each_entry_holds_user_action_object_and_time(): void
    {
        $warden = $this->userWith(RoleCode::Warden, $this->building);

        Sanctum::actingAs($warden);
        $this->getJson("/api/v1/buildings/{$this->building->id}")->assertOk();

        $entry = AuditLog::query()->where('action', AuditAction::BuildingViewed->value)->sole();

        $this->assertSame($warden->id, $entry->user_id);
        $this->assertSame(AuditAction::BuildingViewed, $entry->action);
        $this->assertSame(Building::class, $entry->subject_type);
        $this->assertSame($this->building->id, $entry->subject_id);
        $this->assertNotNull($entry->created_at);
    }

    public function test_viewing_a_card_containing_personal_data_is_recorded(): void
    {
        $warden = $this->userWith(RoleCode::Warden, $this->building);

        Sanctum::actingAs($warden);
        $this->getJson("/api/v1/buildings/{$this->building->id}/users")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $warden->id,
            'action' => AuditAction::BuildingUsersViewed->value,
            'subject_type' => Building::class,
            'subject_id' => $this->building->id,
        ]);
    }

    public function test_the_log_is_accessible_to_the_administrator_only(): void
    {
        $administrator = $this->userWith(RoleCode::Administrator, null);
        $warden = $this->userWith(RoleCode::Warden, $this->building, 'warden@example.test');

        // Something to read: a refused request writes nothing, so the log
        // would otherwise be empty and the assertion vacuous.
        Sanctum::actingAs($warden);
        $this->getJson("/api/v1/buildings/{$this->building->id}")->assertOk();
        $this->getJson('/api/v1/audit-logs')->assertStatus(403);

        Sanctum::actingAs($administrator);
        $this->getJson('/api/v1/audit-logs')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'action', 'user', 'subject', 'result', 'created_at']]]);
    }

    public function test_reading_the_log_is_itself_recorded(): void
    {
        $administrator = $this->userWith(RoleCode::Administrator, null);

        Sanctum::actingAs($administrator);
        $this->getJson('/api/v1/audit-logs')->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $administrator->id,
            'action' => AuditAction::AuditLogViewed->value,
        ]);
    }

    public function test_the_log_offers_no_route_that_changes_an_entry(): void
    {
        $administrator = $this->userWith(RoleCode::Administrator, null);

        Sanctum::actingAs($administrator);
        $this->getJson("/api/v1/buildings/{$this->building->id}")->assertStatus(200);

        $entry = AuditLog::query()->latest('id')->sole();

        foreach ([
            ['PUT', "/api/v1/audit-logs/{$entry->id}"],
            ['PATCH', "/api/v1/audit-logs/{$entry->id}"],
            ['DELETE', "/api/v1/audit-logs/{$entry->id}"],
        ] as [$method, $path]) {
            $this->json($method, $path)->assertStatus(404);
        }
    }

    public function test_the_model_refuses_an_update_or_a_delete_of_an_entry(): void
    {
        $administrator = $this->userWith(RoleCode::Administrator, null);

        Sanctum::actingAs($administrator);
        $this->getJson("/api/v1/buildings/{$this->building->id}")->assertOk();

        $entry = AuditLog::query()->latest('id')->sole();

        $this->assertFalse($entry->update(['action' => AuditAction::LoginSucceeded->value]));
        $this->assertFalse($entry->delete());

        $entry->refresh();

        $this->assertSame(AuditAction::BuildingViewed, $entry->action);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    /**
     * The database half of §4.4.4. It needs a PostgreSQL server and the two
     * roles the deployment carries, so on any other connection it is skipped
     * rather than silently passing.
     */
    public function test_update_and_delete_on_audit_logs_are_revoked_from_the_application_role(): void
    {
        $connection = $this->app['db']->connection();

        if ($connection->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Privileges of this kind exist only on PostgreSQL.');
        }

        $role = (string) config('dormitory.audit.application_db_role');

        if ($connection->selectOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [$role]) === null) {
            $this->markTestSkipped(sprintf('The application role "%s" does not exist on this server.', $role));
        }

        $this->assertFalse(
            (bool) $connection->scalar('SELECT has_table_privilege(?, ?, ?)', [$role, 'audit_logs', 'UPDATE']),
            'UPDATE on audit_logs is still granted to the application role.',
        );
        $this->assertFalse(
            (bool) $connection->scalar('SELECT has_table_privilege(?, ?, ?)', [$role, 'audit_logs', 'DELETE']),
            'DELETE on audit_logs is still granted to the application role.',
        );
        $this->assertTrue(
            (bool) $connection->scalar('SELECT has_table_privilege(?, ?, ?)', [$role, 'audit_logs', 'INSERT']),
            'The application role must still be able to append to audit_logs.',
        );
    }

    private function userWith(RoleCode $role, ?Building $building, ?string $email = null): User
    {
        $factory = User::factory()->withRole($role, $building);

        return $email === null
            ? $factory->create()
            : $factory->create(['email' => $email]);
    }
}

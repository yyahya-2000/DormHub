<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Enums\RoleCode;
use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * The page envelope is generated from api/openapi.yaml, so the document has
     * to describe what actually comes back — `meta.links` was returned and not
     * described, which is the same fault as describing what is not returned.
     */
    public function test_a_page_of_the_log_carries_the_envelope_the_contract_describes(): void
    {
        $administrator = $this->userWith(RoleCode::Administrator, null);

        Sanctum::actingAs($administrator);

        $response = $this->getJson('/api/v1/audit-logs')->assertOk();

        $response->assertJsonStructure([
            'data',
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => [
                'current_page',
                'from',
                'last_page',
                'links' => [['url', 'label', 'active']],
                'path',
                'per_page',
                'to',
                'total',
            ],
        ]);

        $this->assertIsArray($response->json('meta.links'));
        $this->assertNotEmpty($response->json('meta.links'));
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

    /**
     * §3.9.6 counts a refusal among the events the log must hold. Until the
     * exception handler wrote one, ten refused requests left the log empty and
     * an administrator had no way of seeing that somebody had been trying.
     */
    public function test_a_refused_request_is_written_to_the_log_as_a_denial(): void
    {
        $warden = $this->userWith(RoleCode::Warden, $this->building);

        Sanctum::actingAs($warden);
        $this->getJson('/api/v1/audit-logs')->assertStatus(403);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $warden->id,
            'action' => AuditAction::AccessDenied->value,
            'result' => 'denied',
        ]);

        $entry = AuditLog::query()->where('action', AuditAction::AccessDenied->value)->sole();

        $this->assertSame('GET', $entry->payload['method']);
        $this->assertSame('api/v1/audit-logs', $entry->payload['path']);
        $this->assertSame('audit-logs.index', $entry->payload['route']);
        $this->assertNotNull($entry->created_at);
    }

    /**
     * The pattern the log exists to reveal: a resident walking through the
     * building identifiers. Every refusal names the building it was refused
     * on, so the attempts line up on the object.
     */
    public function test_a_denial_names_the_object_the_request_was_refused_on(): void
    {
        $elsewhere = Building::factory()->create();
        $resident = $this->userWith(RoleCode::Resident, $this->building);

        Sanctum::actingAs($resident);
        $this->getJson("/api/v1/buildings/{$elsewhere->id}")->assertStatus(403);
        $this->getJson("/api/v1/buildings/{$elsewhere->id}/users")->assertStatus(403);

        $this->assertSame(
            2,
            AuditLog::query()
                ->where('action', AuditAction::AccessDenied->value)
                ->where('user_id', $resident->id)
                ->where('subject_type', Building::class)
                ->where('subject_id', $elsewhere->id)
                ->count(),
        );
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
     * The database half of §4.4.4, and the half the model can do nothing
     * about: `DB::table`, raw SQL and TRUNCATE never pass through an Eloquent
     * model at all.
     *
     * The check is the query itself. Asking `has_table_privilege` reads the
     * catalogue, which is a statement about what was granted, not about what
     * happens when the application role actually writes — and it is the second
     * that §4.4.4 promises. Each statement below is therefore issued after
     * SET ROLE, from inside the transaction the test runs in, and each is
     * expected back as SQLSTATE 42501.
     *
     * It needs a PostgreSQL server and the two roles the deployment carries,
     * so on any other connection it is skipped rather than silently passing.
     */
    public function test_the_application_role_is_refused_every_direct_write_to_the_log(): void
    {
        $connection = $this->app['db']->connection();

        if ($connection->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Privileges of this kind exist only on PostgreSQL.');
        }

        $role = (string) config('dormitory.audit.application_db_role');

        if ($connection->selectOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [$role]) === null) {
            $this->markTestSkipped(sprintf('The application role "%s" does not exist on this server.', $role));
        }

        $administrator = $this->userWith(RoleCode::Administrator, null);

        Sanctum::actingAs($administrator);
        $this->getJson("/api/v1/buildings/{$this->building->id}")->assertOk();

        $entry = AuditLog::query()->latest('id')->sole();
        $rows = AuditLog::query()->count();

        // SET LOCAL rather than SET: the role is then confined to the
        // transaction the test runs in, and cannot leak into the next test
        // through a pooled connection.
        $connection->statement(sprintf('SET LOCAL ROLE "%s"', str_replace('"', '""', $role)));

        try {
            $refusals = [
                'the query builder updating' => fn () => DB::table('audit_logs')
                    ->where('id', $entry->id)
                    ->update(['action' => AuditAction::LoginSucceeded->value]),
                'the query builder deleting' => fn () => DB::table('audit_logs')
                    ->where('id', $entry->id)
                    ->delete(),
                'a raw UPDATE' => fn () => $connection->statement(
                    'UPDATE audit_logs SET ip_address = ? WHERE id = ?',
                    ['203.0.113.9', $entry->id],
                ),
                'a raw DELETE' => fn () => $connection->statement(
                    'DELETE FROM audit_logs WHERE id = ?',
                    [$entry->id],
                ),
                'a TRUNCATE of the whole table' => fn () => $connection->statement('TRUNCATE audit_logs'),
            ];

            foreach ($refusals as $path => $attempt) {
                $this->assertRefusedByThePrivilege($path, $attempt);
            }

            // The positive control. An append-only log that cannot be appended
            // to would pass every assertion above and be useless, so the right
            // the application does need is exercised under the same role.
            DB::table('audit_logs')->insert([
                'action' => AuditAction::LoginFailed->value,
                'result' => 'failure',
                'created_at' => now(),
            ]);
        } finally {
            $connection->statement('RESET ROLE');
        }

        $entry->refresh();

        $this->assertSame(AuditAction::BuildingViewed, $entry->action);
        $this->assertSame($rows + 1, AuditLog::query()->count());
    }

    /**
     * The gap the acceptance run pointed at. `booted()` guards the record and
     * sees only the record: a mass update loads nothing and fires no event, so
     * `AuditLog::query()->update()` went straight to SQL with the revoked
     * grant as the only thing in its way. This test runs as the owning role,
     * where that grant does not apply, so it fails if the model stops
     * refusing.
     */
    public function test_no_eloquent_path_changes_an_entry_not_even_the_mass_update_that_fires_no_events(): void
    {
        $administrator = $this->userWith(RoleCode::Administrator, null);

        Sanctum::actingAs($administrator);
        $this->getJson("/api/v1/buildings/{$this->building->id}")->assertOk();

        $entry = AuditLog::query()->latest('id')->sole();

        $refusals = [
            'a mass update' => fn () => AuditLog::query()
                ->where('id', $entry->id)
                ->update(['action' => AuditAction::LoginSucceeded->value]),
            'a mass delete' => fn () => AuditLog::query()->where('id', $entry->id)->delete(),
            'a forced mass delete' => fn () => AuditLog::query()->where('id', $entry->id)->forceDelete(),
            'a truncate' => fn () => AuditLog::query()->truncate(),
            'an increment' => fn () => AuditLog::query()->where('id', $entry->id)->increment('subject_id'),
        ];

        foreach ($refusals as $path => $attempt) {
            try {
                $attempt();

                $this->fail(sprintf('The model allowed %s of an audit entry.', $path));
            } catch (ImmutableRecordException) {
                // The refusal this test is about.
            }
        }

        // The single-record path keeps refusing quietly, because a model event
        // that vetoes returns false rather than raising.
        $this->assertFalse($entry->update(['action' => AuditAction::LoginSucceeded->value]));
        $this->assertFalse($entry->delete());

        $entry->refresh();

        $this->assertSame(AuditAction::BuildingViewed, $entry->action);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    /**
     * A refused statement aborts the PostgreSQL transaction the test runs in,
     * and RefreshDatabase needs that transaction to roll the test back with.
     * Each attempt is therefore taken inside a savepoint.
     */
    private function assertRefusedByThePrivilege(string $path, callable $attempt): void
    {
        $connection = $this->app['db']->connection();
        $connection->statement('SAVEPOINT refused_write');

        try {
            $attempt();

            $this->fail(sprintf('The application role was allowed %s of an audit entry.', $path));
        } catch (QueryException $exception) {
            $this->assertSame(
                '42501',
                (string) $exception->getCode(),
                sprintf('%s failed, but not on the privilege: %s', $path, $exception->getMessage()),
            );
        } finally {
            $connection->statement('ROLLBACK TO SAVEPOINT refused_write');
        }
    }

    private function userWith(RoleCode $role, ?Building $building, ?string $email = null): User
    {
        $factory = User::factory()->withRole($role, $building);

        return $email === null
            ? $factory->create()
            : $factory->create(['email' => $email]);
    }
}

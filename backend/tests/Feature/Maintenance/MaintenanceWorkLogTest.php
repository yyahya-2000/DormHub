<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Enums\MaintenanceRequestStatus;
use App\Enums\RoleCode;
use App\Exceptions\ImmutableRecordException;
use App\Models\Building;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceWorkLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsAMaintenanceScenario;
use Tests\TestCase;

/**
 * §3.4.1's sixth decision and §4.4.4: `maintenance_work_logs` is insert-only
 * at two levels, exactly as `audit_logs` is.
 *
 * The two levels catch different things and both are asserted here. In the
 * application the model refuses the single-record path and the
 * `AppendOnlyBuilder` refuses the mass one — a mass update never loads a
 * record and fires no model event, so the hooks alone would let it through. In
 * the database the migration revokes UPDATE and DELETE from the application
 * role, which is what holds when the write does not pass through Eloquent at
 * all: `DB::table(...)`, raw SQL, TRUNCATE.
 *
 * **Why the revoke here and the trigger on `guest_visits`.** A visit row
 * admits two later writes — the exit and the overdue mark — so UPDATE cannot
 * be taken away wholesale and a trigger refuses the writes that are not those
 * two. A work-log row admits none at all, so the blunter instrument is the
 * right one, and it is also the one the application cannot talk its way past:
 * a trigger is a function somebody can drop, a revoked privilege is not
 * something the application role can give itself back.
 */
final class MaintenanceWorkLogTest extends TestCase
{
    use BuildsAMaintenanceScenario, RefreshDatabase;

    private Building $building;

    private User $resident;

    private User $warden;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 09:30:00'));

        $this->building = $this->dormitory('Block A');
        $this->resident = $this->residentInRoom412($this->building);
        $this->warden = $this->staff(RoleCode::Warden, $this->building, 'warden@example.test');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_an_entry_of_the_work_log_cannot_be_edited_or_removed_through_the_model(): void
    {
        $entry = $this->aWorkLogEntry();

        $this->assertFalse($entry->update(['comment' => 'Something else entirely.']));
        $this->assertFalse($entry->delete());

        $entry->refresh();

        $this->assertSame('The plumber comes on Friday.', $entry->comment);
        $this->assertDatabaseCount('maintenance_work_logs', 1);
    }

    /**
     * The gap the model hooks cannot close. `MaintenanceWorkLog::query()
     * ->update(...)` loads no record and fires no event, so it is the builder
     * that has to refuse it — and everything the builder refuses, the
     * application role is refused in the database too.
     */
    public function test_a_mass_update_or_delete_of_the_work_log_is_refused_by_the_builder(): void
    {
        $this->aWorkLogEntry();

        foreach ([
            'a mass update' => fn () => MaintenanceWorkLog::query()->update(['comment' => 'rewritten']),
            'a mass delete' => fn () => MaintenanceWorkLog::query()->delete(),
            'a truncate' => fn () => MaintenanceWorkLog::query()->truncate(),
        ] as $path => $attempt) {
            try {
                $attempt();
                $this->fail(sprintf('%s of the work log was not refused.', $path));
            } catch (ImmutableRecordException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertDatabaseCount('maintenance_work_logs', 1);
    }

    /**
     * §4.4.4, the database half, checked the way `AuditLogTest` checks the
     * same promise for `audit_logs`: not by reading the catalogue, which says
     * what was granted, but by issuing each write under SET LOCAL ROLE and
     * expecting SQLSTATE 42501 back.
     *
     * It needs a PostgreSQL server and the two roles the deployment carries,
     * so on any other connection it is skipped rather than silently passing.
     */
    public function test_the_application_role_is_refused_every_direct_write_to_the_work_log(): void
    {
        $connection = $this->app['db']->connection();

        if ($connection->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Privileges of this kind exist only on PostgreSQL.');
        }

        $role = (string) config('dormitory.audit.application_db_role');

        if ($connection->selectOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [$role]) === null) {
            $this->markTestSkipped(sprintf('The application role "%s" does not exist on this server.', $role));
        }

        $entry = $this->aWorkLogEntry();
        $rows = MaintenanceWorkLog::query()->count();

        // SET LOCAL rather than SET: the role is confined to the transaction
        // the test runs in and cannot leak into the next test through a pooled
        // connection.
        $connection->statement(sprintf('SET LOCAL ROLE "%s"', str_replace('"', '""', $role)));

        try {
            $refusals = [
                'the query builder updating' => fn () => DB::table('maintenance_work_logs')
                    ->where('id', $entry->id)
                    ->update(['comment' => 'rewritten']),
                'the query builder deleting' => fn () => DB::table('maintenance_work_logs')
                    ->where('id', $entry->id)
                    ->delete(),
                'a raw UPDATE' => fn () => $connection->statement(
                    'UPDATE maintenance_work_logs SET to_status = ? WHERE id = ?',
                    [MaintenanceRequestStatus::Closed->value, $entry->id],
                ),
                'a raw DELETE' => fn () => $connection->statement(
                    'DELETE FROM maintenance_work_logs WHERE id = ?',
                    [$entry->id],
                ),
                'a TRUNCATE of the whole table' => fn () => $connection->statement(
                    'TRUNCATE maintenance_work_logs'
                ),
            ];

            foreach ($refusals as $path => $attempt) {
                $this->assertRefusedByThePrivilege($path, $attempt);
            }

            // The positive control. An append-only log that cannot be appended
            // to would pass every assertion above and be useless, so the right
            // the application does need is exercised under the same role.
            DB::table('maintenance_work_logs')->insert([
                'maintenance_request_id' => $entry->maintenance_request_id,
                'from_status' => MaintenanceRequestStatus::Accepted->value,
                'to_status' => MaintenanceRequestStatus::InProgress->value,
                'created_at' => now(),
            ]);
        } finally {
            $connection->statement('RESET ROLE');
        }

        $entry->refresh();

        $this->assertSame('The plumber comes on Friday.', $entry->comment);
        $this->assertSame($rows + 1, MaintenanceWorkLog::query()->count());
    }

    /**
     * The database refuses a row that records no movement. The one legitimate
     * repetition — accepted, reopened, accepted again — is two rows with
     * `completed` between them, not one row saying a request became what it
     * already was.
     */
    public function test_the_database_refuses_an_entry_that_records_no_movement(): void
    {
        if ($this->app['db']->connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The constraint exists only on PostgreSQL.');
        }

        $entry = $this->aWorkLogEntry();
        $connection = $this->app['db']->connection();

        // Inside a savepoint, so the refused INSERT aborts only itself and
        // `RefreshDatabase` still has a transaction to roll the test back with.
        $connection->statement('SAVEPOINT refused_insert');

        try {
            DB::table('maintenance_work_logs')->insert([
                'maintenance_request_id' => $entry->maintenance_request_id,
                'from_status' => MaintenanceRequestStatus::Accepted->value,
                'to_status' => MaintenanceRequestStatus::Accepted->value,
                'created_at' => now(),
            ]);

            $this->fail('The database accepted a work-log entry that records no movement.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('maintenance_work_logs_record_a_movement', $exception->getMessage());
        } finally {
            $connection->statement('ROLLBACK TO SAVEPOINT refused_insert');
        }
    }

    private function aWorkLogEntry(): MaintenanceWorkLog
    {
        $request = MaintenanceRequest::factory()
            ->forBuilding($this->building)
            ->from($this->resident)
            ->inRoom($this->roomOf($this->resident))
            ->create();

        Sanctum::actingAs($this->warden);

        $this->postJson("/api/v1/maintenance-requests/{$request->id}/accept", [
            'target_date' => '2026-09-18',
            'comment' => 'The plumber comes on Friday.',
        ])->assertOk();

        return MaintenanceWorkLog::query()
            ->where('maintenance_request_id', $request->id)
            ->sole();
    }

    /**
     * A refused statement aborts the PostgreSQL transaction the test runs in,
     * and `RefreshDatabase` needs that transaction to roll the test back with.
     * Each attempt is therefore taken inside a savepoint — the same device
     * `AuditLogTest` uses for the same reason.
     *
     * @param  callable(): mixed  $attempt
     */
    private function assertRefusedByThePrivilege(string $path, callable $attempt): void
    {
        $connection = $this->app['db']->connection();
        $connection->statement('SAVEPOINT refused_write');

        try {
            $attempt();

            $this->fail(sprintf('The application role was allowed %s of a work-log entry.', $path));
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
}

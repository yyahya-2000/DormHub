<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The second half of §4.4.4, which names both tables in one statement:
 *
 *     REVOKE UPDATE, DELETE ON audit_logs, maintenance_work_logs FROM app_rw;
 *
 * It is a migration of its own rather than an edit to the one that revokes on
 * `audit_logs`, because that one has already run on every stand the project
 * has: a migration is a record of what happened and not a file that is kept up
 * to date. The body is the same and deliberately so — one shape of refusal,
 * checked the same way in `MaintenanceWorkLogTest`.
 *
 * **Why the revoke here and the trigger on `guest_visits`.** The two tables
 * are append-only in the same sense and the mechanisms differ because the
 * tables do. A visit row admits exactly two later writes — the exit and the
 * overdue mark — so UPDATE cannot be taken away wholesale and a trigger is
 * what refuses the writes that are not those two. A work-log row admits none
 * at all, so the blunter instrument is the right one, and the blunter
 * instrument is also the one the application cannot talk its way past: a
 * trigger is a function somebody can drop, a revoked privilege is not a thing
 * the application role can give itself back.
 *
 * The cost §4.4.4 names is that the migration must run under a role other than
 * the one it revokes from. Where only one role exists — a SQLite test database,
 * or a local Postgres with a single superuser — the statement is skipped and
 * the reason is reported, so that a silent no-op is never mistaken for an
 * applied revocation.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            $this->report('skipped: privileges of this kind exist only on PostgreSQL');

            return;
        }

        $role = (string) config('dormitory.audit.application_db_role');

        if ($role === '') {
            $this->report('skipped: no application database role is configured');

            return;
        }

        if ($connection->selectOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [$role]) === null) {
            $this->report(sprintf('skipped: the role "%s" does not exist on this server', $role));

            return;
        }

        if ((string) $connection->scalar('SELECT current_user') === $role) {
            $this->report(sprintf(
                'skipped: the migration runs as "%s", the very role the revocation targets',
                $role
            ));

            return;
        }

        $wrapped = $connection->getSchemaGrammar()->wrap($role);

        // The rights the application does need are granted first. Stating them
        // here rather than leaving them to the provisioning script is what
        // makes the revocation below mean anything: revoking a right the role
        // was never given proves nothing.
        $connection->statement(sprintf('GRANT SELECT, INSERT ON maintenance_work_logs TO %s', $wrapped));
        $connection->statement(sprintf(
            'GRANT USAGE, SELECT ON SEQUENCE maintenance_work_logs_id_seq TO %s',
            $wrapped
        ));

        $connection->statement(sprintf('REVOKE UPDATE, DELETE ON maintenance_work_logs FROM %s', $wrapped));
    }

    public function down(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $role = (string) config('dormitory.audit.application_db_role');

        if ($role === '' || $connection->selectOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [$role]) === null) {
            return;
        }

        $connection->statement(sprintf(
            'GRANT UPDATE, DELETE ON maintenance_work_logs TO %s',
            $connection->getSchemaGrammar()->wrap($role)
        ));
    }

    private function report(string $reason): void
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            fwrite(STDERR, 'maintenance_work_logs revocation '.$reason.PHP_EOL);
        }
    }
};

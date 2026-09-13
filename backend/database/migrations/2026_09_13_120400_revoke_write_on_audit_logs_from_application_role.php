<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The append-only guarantee of §4.4.4 at the database level. No service issues
 * an UPDATE or a DELETE against `audit_logs`, and this migration makes the
 * restriction survive a mistake in application code:
 *
 *     REVOKE UPDATE, DELETE ON audit_logs FROM app_rw;
 *
 * The cost §4.4.4 names is that the migration must run under a role other than
 * the one it revokes from, since a role cannot strip its own ownership rights.
 * The deployment therefore carries two roles: a migration role that owns the
 * schema and an application role that the running service connects as. Where
 * only one of them exists — a SQLite test database, or a local Postgres set up
 * with a single superuser — the statement is skipped and the reason is
 * reported, so that a silent no-op is never mistaken for an applied revocation.
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

        $roleExists = $connection->selectOne('SELECT 1 AS present FROM pg_roles WHERE rolname = ?', [$role]);

        if ($roleExists === null) {
            $this->report(sprintf('skipped: the role "%s" does not exist on this server', $role));

            return;
        }

        $currentUser = (string) $connection->scalar('SELECT current_user');

        if ($currentUser === $role) {
            $this->report(sprintf(
                'skipped: the migration runs as "%s", the very role the revocation targets',
                $role
            ));

            return;
        }

        $wrapped = $connection->getSchemaGrammar()->wrap($role);

        // The two rights the application does need on an append-only log are
        // granted first. Stating them here rather than leaving them to the
        // provisioning script is what makes the revocation below meaningful:
        // revoking a right the role was never given proves nothing.
        $connection->statement(sprintf('GRANT SELECT, INSERT ON audit_logs TO %s', $wrapped));
        $connection->statement(sprintf('GRANT USAGE, SELECT ON SEQUENCE audit_logs_id_seq TO %s', $wrapped));

        $connection->statement(sprintf('REVOKE UPDATE, DELETE ON audit_logs FROM %s', $wrapped));
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
            'GRANT UPDATE, DELETE ON audit_logs TO %s',
            $connection->getSchemaGrammar()->wrap($role)
        ));
    }

    private function report(string $reason): void
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            fwrite(STDERR, 'audit_logs revocation '.$reason.PHP_EOL);
        }
    }
};

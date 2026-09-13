<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FR-07's horizontal boundary, moved from code into the schema.
 *
 * §3.4.1, decision 1 says a role is held either over the system as a whole or
 * inside one building, and that the first form belongs to the administrator
 * alone. Until now that sentence lived in `RoleCode::isSystemWide()`, which
 * the seeder and the factory happen to consult. The table itself accepted
 * anything: one row granting a warden `building_id = NULL` gave that account
 * every dormitory, and the acceptance run inserted exactly such a row and was
 * not stopped. The route that hands out roles does not exist yet — which is
 * the reason to close this before it does, rather than after.
 *
 * A CHECK cannot look into another table, and the code of a role lives in
 * `roles`. The constraint is therefore given something local to judge:
 *
 *   - `role_user.role_code` carries the code of the granted role;
 *   - a foreign key on the pair `(role_id, role_code)` keeps that copy honest,
 *     so the column cannot drift from the row it names, and a renamed code
 *     follows through ON UPDATE CASCADE;
 *   - a trigger fills the column from `roles` on every insert and on every
 *     update that touches `role_id`, so no application code has to know the
 *     column exists — and no future write can forget it;
 *   - the CHECK then states the invariant in one line, over columns of its own
 *     table: system scope and the administrator are the same thing.
 *
 * The alternative — a trigger that raises an exception — would have hidden the
 * rule inside procedural code, which is where it was already hiding.
 *
 * As with §4.4.4, the statements below are PostgreSQL's. On any other driver
 * the migration reports itself skipped rather than passing silently.
 */
return new class extends Migration
{
    /**
     * `RoleCode::Administrator->value` as it stands. The literal is written
     * out rather than read from the enumeration: a migration describes the
     * schema at the moment it ran, and must not change meaning later because
     * an enumeration was edited.
     */
    private const SYSTEM_WIDE_ROLE = 'admin';

    public function up(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            $this->report('skipped: the statements below are PostgreSQL-specific');

            return;
        }

        // The pair a composite foreign key needs on the parent side.
        $connection->statement('ALTER TABLE roles ADD CONSTRAINT roles_id_code_uniq UNIQUE (id, code)');

        $connection->statement('ALTER TABLE role_user ADD COLUMN role_code VARCHAR(32)');
        $connection->statement(
            'UPDATE role_user SET role_code = roles.code FROM roles WHERE roles.id = role_user.role_id'
        );
        $connection->statement('ALTER TABLE role_user ALTER COLUMN role_code SET NOT NULL');

        $connection->statement(
            'ALTER TABLE role_user ADD CONSTRAINT role_user_role_code_fk'
            .' FOREIGN KEY (role_id, role_code) REFERENCES roles (id, code) ON UPDATE CASCADE'
        );

        /*
         * OR REPLACE, because a function is not a table: `migrate:fresh` drops
         * the schema's tables and leaves this behind, and the next run of the
         * migration would fail on a name that is already taken.
         */
        $connection->unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION role_user_fill_role_code() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                SELECT code INTO NEW.role_code FROM roles WHERE id = NEW.role_id;

                RETURN NEW;
            END;
            $$;
        SQL);

        $connection->statement(
            'CREATE TRIGGER role_user_fill_role_code_trg'
            .' BEFORE INSERT OR UPDATE OF role_id ON role_user'
            .' FOR EACH ROW EXECUTE FUNCTION role_user_fill_role_code()'
        );

        $connection->statement(sprintf(
            'ALTER TABLE role_user ADD CONSTRAINT role_user_scope_matches_role'
            ." CHECK ((role_code = '%s') = (building_id IS NULL))",
            self::SYSTEM_WIDE_ROLE,
        ));
    }

    public function down(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement('ALTER TABLE role_user DROP CONSTRAINT IF EXISTS role_user_scope_matches_role');
        $connection->statement('DROP TRIGGER IF EXISTS role_user_fill_role_code_trg ON role_user');
        $connection->statement('DROP FUNCTION IF EXISTS role_user_fill_role_code()');
        $connection->statement('ALTER TABLE role_user DROP CONSTRAINT IF EXISTS role_user_role_code_fk');
        $connection->statement('ALTER TABLE role_user DROP COLUMN IF EXISTS role_code');
        $connection->statement('ALTER TABLE roles DROP CONSTRAINT IF EXISTS roles_id_code_uniq');
    }

    private function report(string $reason): void
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            fwrite(STDERR, 'role grant scope constraint '.$reason.PHP_EOL);
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `btree_gist`, and nothing else.
 *
 * The migration that follows forbids overlapping residencies with an exclusion
 * constraint, and an exclusion constraint mixing `bed_id WITH =` and a date
 * range `WITH &&` needs one index to answer both. A plain GiST index cannot
 * compare two bigints for equality; `btree_gist` is the extension that teaches
 * it to, and it is the whole reason this file exists.
 *
 * It stands apart from the constraint for two reasons. `CREATE EXTENSION`
 * needs a privilege the constraint does not — on a managed instance it is the
 * one statement a deployment may have to arrange with the database
 * administrator beforehand — and an installed extension is a property of the
 * database rather than of the schema: rolling the constraint back should not
 * take the extension with it, because another table may by then be leaning on
 * it.
 *
 * The driver check follows the precedent of the audit-log revocation: a
 * statement that only PostgreSQL understands is skipped elsewhere, out loud,
 * so that a silent no-op is never mistaken for an applied change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->report('skipped: the extension exists only on PostgreSQL');

            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Without CASCADE. Anything still depending on the extension keeps it,
        // and the rollback says so rather than dropping that dependant too.
        DB::statement('DROP EXTENSION IF EXISTS btree_gist');
    }

    private function report(string $reason): void
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            fwrite(STDERR, 'btree_gist '.$reason.PHP_EOL);
        }
    }
};

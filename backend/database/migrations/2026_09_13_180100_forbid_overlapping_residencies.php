<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FR-03 as the criterion is actually written: «two residents cannot hold the
 * same bed over **overlapping periods**».
 *
 * What stood here before was `residencies_active_bed_uniq`, a partial unique
 * index over `bed_id WHERE moved_out_at IS NULL`. It forbids two **open**
 * residencies on one bed, which is a narrower rule, and the gap between the
 * two is not theoretical. Acceptance walked through it twice with ordinary API
 * calls: a residency backdated underneath a period that was already running,
 * and a residency written on top of one whose termination had been recorded
 * with a date three months out. Neither is two open rows, so neither was
 * refused, and both put two people on one bed today.
 *
 * The rule is therefore restated over the period rather than over the open
 * flag:
 *
 *     ALTER TABLE residencies ADD CONSTRAINT residencies_bed_no_overlap
 *         EXCLUDE USING gist (
 *             bed_id WITH =,
 *             daterange(moved_in_at, moved_out_at, '[)') WITH &&
 *         );
 *
 * and the same on `user_id` for the other half of §3.4.4 — one person, one bed
 * at a time. An open residency has no upper bound, so `daterange(d, NULL)` is
 * `[d, ∞)` and any two open periods on one bed overlap by construction: the
 * old rule is contained in the new one.
 *
 * **The bounds are `[)` — closed below, open above — and that is a domain
 * decision, not a default.** A resident moving out on the 15th and the next
 * one moving in on the 15th produce `[…, 15)` and `[15, …)`, which touch and
 * do not overlap. Under `[]` the register would refuse them and a bed could
 * only change hands with a night of nobody in it. The same arithmetic gives
 * `moved_in_at = moved_out_at` an empty range, and an empty range conflicts
 * with nothing: a residency that ended on the day it began held the bed for no
 * night, and the register lets the place be used.
 *
 * **The two partial unique indexes are dropped.** They are not kept as a
 * cheaper first line of defence, because the service reads the *name* of the
 * violated constraint to decide which rule was broken and what to show the
 * warden. With both in place, which of the two fires on a plain second open
 * residency is the planner's business and not the domain's, and the answer the
 * client gets would depend on it. One rule, stated once. The GiST index the
 * constraint creates also answers `WHERE bed_id = ?` — that is what
 * `btree_gist` is for — so nothing is lost on the lookup side either.
 *
 * **A database that already holds overlaps is refused, loudly.** The migration
 * looks for them first and stops with the conflicting identifiers named. The
 * alternative — closing or shifting one row of each pair automatically — was
 * rejected: both rows are records of an accommodation contract, no rule says
 * which of the two is the mistake, and a migration that picks one rewrites the
 * register's history without anyone deciding to. The development stand is
 * repaired by hand from the identifiers below and the migration is run again;
 * nothing is lost, which `migrate:fresh` cannot promise.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $this->refuseExistingOverlaps('bed_id', 'bed');
        $this->refuseExistingOverlaps('user_id', 'resident');

        DB::statement(
            'ALTER TABLE residencies ADD CONSTRAINT residencies_bed_no_overlap'
            .' EXCLUDE USING gist ('
            ."bed_id WITH =, (daterange(moved_in_at, moved_out_at, '[)')) WITH &&"
            .')'
        );

        DB::statement(
            'ALTER TABLE residencies ADD CONSTRAINT residencies_user_no_overlap'
            .' EXCLUDE USING gist ('
            ."user_id WITH =, (daterange(moved_in_at, moved_out_at, '[)')) WITH &&"
            .')'
        );

        // Superseded, not merely duplicated: see the note above.
        DB::statement('DROP INDEX IF EXISTS residencies_active_bed_uniq');
        DB::statement('DROP INDEX IF EXISTS residencies_active_user_uniq');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX residencies_active_bed_uniq ON residencies (bed_id)'
            .' WHERE moved_out_at IS NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX residencies_active_user_uniq ON residencies (user_id)'
            .' WHERE moved_out_at IS NULL'
        );

        DB::statement('ALTER TABLE residencies DROP CONSTRAINT IF EXISTS residencies_bed_no_overlap');
        DB::statement('ALTER TABLE residencies DROP CONSTRAINT IF EXISTS residencies_user_no_overlap');
    }

    /**
     * Stops the migration if the table already contains what the constraint is
     * about to forbid, naming the rows rather than the rule.
     */
    private function refuseExistingOverlaps(string $column, string $subject): void
    {
        $conflicts = DB::select(
            'SELECT earlier.id AS earlier_id, later.id AS later_id, earlier.'.$column.' AS subject_id'
            .' FROM residencies earlier'
            .' JOIN residencies later'
            .'   ON later.id > earlier.id'
            .'  AND later.'.$column.' = earlier.'.$column
            ." WHERE daterange(earlier.moved_in_at, earlier.moved_out_at, '[)')"
            ."    && daterange(later.moved_in_at, later.moved_out_at, '[)')"
            .' ORDER BY earlier.id'
            .' LIMIT 20'
        );

        if ($conflicts === []) {
            return;
        }

        $pairs = implode(', ', array_map(
            static fn (object $row): string => sprintf(
                'residencies %d and %d (%s %d)',
                $row->earlier_id,
                $row->later_id,
                $subject,
                $row->subject_id,
            ),
            $conflicts,
        ));

        throw new RuntimeException(sprintf(
            'The residency register already contains overlapping periods on the same %s, '
            .'so the exclusion constraint cannot be added: %s. '
            .'Nothing has been changed. Decide for each pair which record is wrong and '
            .'correct its moved_in_at / moved_out_at, then run the migration again. '
            .'The full list: SELECT a.id, b.id, a.%s FROM residencies a JOIN residencies b '
            ."ON b.id > a.id AND b.%s = a.%s WHERE daterange(a.moved_in_at, a.moved_out_at, '[)') "
            ."&& daterange(b.moved_in_at, b.moved_out_at, '[)');",
            $subject,
            $pairs,
            $column,
            $column,
            $column,
        ));
    }
};

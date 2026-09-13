<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RESIDENCY of the ER model (§3.4.3) and the third modelling decision of
 * §3.4.1: residency is **historical**. A row is never deleted on move-out;
 * `moved_out_at` is set, and the whole occupancy history of a bed and of a
 * person stays readable — which is what FR-06's card is built from.
 *
 * The centrepiece is `residencies_active_bed_uniq`. FR-03 forbids two
 * residents holding the same bed over overlapping periods, and the enforcement
 * sits **in the database**, as a partial unique index, rather than in a
 * service that reads before it writes. The difference is not stylistic: a
 * check-then-insert pair loses to a concurrent request between the two
 * statements, and an index does not. §4.4.3 spells the index out:
 *
 *     CREATE UNIQUE INDEX residencies_active_bed_uniq
 *         ON residencies (bed_id) WHERE moved_out_at IS NULL;
 *
 * Its mirror on `user_id` encodes the other half of §3.4.4: «a user likewise
 * occupies one bed at a time».
 *
 * Partial indexes are the specific PostgreSQL capability §3.8.3 names as a
 * reason for the choice of database, and this is the place the reason is
 * cashed in.
 *
 * Two columns stand outside the diagram and are here because FR-03 and FR-05
 * ask for them in prose: the ground on which the residency begins, and the
 * ground on which it ends. FR-05's first criterion — «termination of residency
 * is recorded with a ground and a date» — cannot be met by a status value
 * alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('residencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('bed_id')->constrained()->restrictOnDelete();
            $table->string('contract_number', 64);
            $table->date('moved_in_at');
            $table->string('moved_in_ground')->nullable();
            $table->date('moved_out_at')->nullable();
            $table->string('moved_out_ground')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            // The card of one person: the history, newest first.
            $table->index(['user_id', 'moved_in_at']);
        });

        // §3.4.1, decision 3, verbatim. At most one open residency per bed.
        DB::statement(
            'CREATE UNIQUE INDEX residencies_active_bed_uniq ON residencies (bed_id)'
            .' WHERE moved_out_at IS NULL'
        );

        // §3.4.4, the same rule read from the other side: one person, one bed.
        DB::statement(
            'CREATE UNIQUE INDEX residencies_active_user_uniq ON residencies (user_id)'
            .' WHERE moved_out_at IS NULL'
        );

        // A residency cannot end before it began. The service asserts it too,
        // and the database is the one that cannot be bypassed.
        DB::statement(
            'ALTER TABLE residencies ADD CONSTRAINT residencies_period_ordered'
            .' CHECK (moved_out_at IS NULL OR moved_out_at >= moved_in_at)'
        );

        // The ground for the termination is not optional once a termination
        // date exists: FR-05's first criterion asks for both or neither.
        DB::statement(
            'ALTER TABLE residencies ADD CONSTRAINT residencies_termination_grounded'
            .' CHECK (moved_out_at IS NULL OR moved_out_ground IS NOT NULL)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('residencies');
    }
};

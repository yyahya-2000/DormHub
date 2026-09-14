<?php

use App\Enums\MaintenanceCategory;
use App\Enums\MaintenanceLocation;
use App\Enums\MaintenanceRequestStatus;
use App\Enums\MaintenanceUrgency;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MAINTENANCE_REQUEST of the ER model (§3.4.3), with three departures from the
 * diagram, each of them driven by a requirement the diagram predates.
 *
 * **`photo_paths` is an array and not a `photo_path`.** FR-36 says «up to
 * three photographs» and the diagram carries one column. A side table was the
 * other candidate and was refused: a photograph here has no attribute of its
 * own beyond its path, is never queried apart from its request, and dies with
 * it — which is a value, not an entity. What the array buys that a side table
 * would have needed a trigger for is the ceiling: `jsonb_array_length(...) <=
 * 3` puts FR-36's «up to three» in the database beside the form rule, so the
 * two cannot drift and neither is the only one.
 *
 * **`location` and `location_note` are two columns.** FR-36's location is «own
 * room or a named common area», and the two halves are not the same kind of
 * fact. «Own room» is resolved against the residency register — the submitter
 * does not say which room they live in, the register does — and lands in
 * `room_id`. A common area has no row in the register at all, so it is named
 * in words. `room_id` NULL is the diagram's «NULL for common areas», and the
 * CHECK below makes the pairing an invariant rather than a habit.
 *
 * **FR-37's two impossibilities are here as well as in the form requests.**
 * «Acceptance without a planned completion date is impossible» is
 * `maintenance_requests_accepted_has_a_target_date`: past `submitted` and
 * outside `rejected`, `target_date` is NOT NULL. The rejection's reason is
 * *not* a column here — §3.4.1's sixth decision puts every comment in
 * `maintenance_work_logs`, and a reason copied onto the request as well would
 * be the field that quietly disagrees with the log.
 *
 * **`auto_closed` beside `confirmed_at`, and the CHECK that separates them.**
 * FR-39 asks for a request that ran out its confirmation window to close
 * «marked as automatically closed, not as confirmed». One boolean and one
 * timestamp say that, and the constraint refuses the row that claims both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_requests', function (Blueprint $table) {
            $table->id();

            // FR-36: bound to the building always, to a room only for «own
            // room». Both restrict on delete — a defect reported against a
            // room is part of that room's history.
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();

            $table->string('category', 32);
            $table->string('location', 32);
            $table->string('location_note', 255)->nullable();

            // The diagram's `title`. FR-36 does not ask for one, so it is
            // nullable: a client that offers a subject line may send it and a
            // client that does not is not forced to invent one.
            $table->string('title', 255)->nullable();
            $table->text('description');
            $table->string('urgency', 16)->default(MaintenanceUrgency::Routine->value);

            // Paths in the object store, never the files themselves.
            $table->jsonb('photo_paths')->default('[]');

            $table->string('status', 32)->default(MaintenanceRequestStatus::Submitted->value);

            // FR-37: «sets a planned completion date and a responsible party».
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->date('target_date')->nullable();

            $table->timestamp('completed_at')->nullable();

            // FR-39. The reporter's own act, and the two ways the request can
            // end without it.
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->boolean('auto_closed')->default(false);
            $table->unsignedSmallInteger('reopen_count')->default(0);

            $table->timestamps();

            // FR-40's queue: one building, one status, oldest first. `age
            // since submission» is `created_at`, which is why it is the third
            // column rather than `updated_at`.
            $table->index(['building_id', 'status', 'created_at']);

            // The same queue filtered by category, which FR-40 asks for.
            $table->index(['building_id', 'category']);

            // «My requests», the resident's own screen.
            $table->index(['reporter_id', 'created_at']);

            // The nightly pass of `ScanOverdueMaintenance`.
            $table->index(['status', 'target_date']);
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // The vocabulary of four columns, enforced where it cannot be
        // bypassed. The enums are the application's statement of the same
        // thing; this is what stops a hand-written UPDATE from inventing a
        // seventh status that every reader of the queue would have to survive.
        $vocabularies = [
            'status' => MaintenanceRequestStatus::values(),
            'category' => MaintenanceCategory::values(),
            'location' => MaintenanceLocation::values(),
            'urgency' => MaintenanceUrgency::values(),
        ];

        foreach ($vocabularies as $column => $values) {
            DB::statement(sprintf(
                'ALTER TABLE maintenance_requests ADD CONSTRAINT maintenance_requests_%s_known'
                ." CHECK (%s IN ('%s'))",
                $column,
                $column,
                implode("','", $values),
            ));
        }

        // FR-36, first criterion, as an invariant rather than as a habit of
        // the service: «own room» names a room and no note, a common area
        // names a note and no room.
        DB::statement(
            'ALTER TABLE maintenance_requests ADD CONSTRAINT maintenance_requests_location_is_coherent'
            .' CHECK ('
            ."     (location = '".MaintenanceLocation::OwnRoom->value."' AND room_id IS NOT NULL)"
            ."  OR (location = '".MaintenanceLocation::CommonArea->value."' AND room_id IS NULL"
            .'      AND location_note IS NOT NULL)'
            .' )'
        );

        // FR-36, third criterion: «up to three photographs».
        DB::statement(
            'ALTER TABLE maintenance_requests ADD CONSTRAINT maintenance_requests_at_most_three_photographs'
            .' CHECK (jsonb_typeof(photo_paths) = \'array\' AND jsonb_array_length(photo_paths) <= 3)'
        );

        // FR-37, second criterion: «acceptance without a planned completion
        // date is impossible». Stated of every state the acceptance leads to,
        // not only of the acceptance itself, so that no later write can clear
        // the date the resident was told.
        DB::statement(
            'ALTER TABLE maintenance_requests ADD CONSTRAINT maintenance_requests_accepted_has_a_target_date'
            .' CHECK ('
            ."     status IN ('".MaintenanceRequestStatus::Submitted->value
            ."','".MaintenanceRequestStatus::Rejected->value."')"
            .'  OR target_date IS NOT NULL'
            .' )'
        );

        // FR-37, first criterion, on the other half of the triage: a
        // responsible party has a moment, and a moment has a party.
        DB::statement(
            'ALTER TABLE maintenance_requests ADD CONSTRAINT maintenance_requests_assignment_complete'
            .' CHECK ((assigned_to IS NULL) = (assigned_at IS NULL))'
        );

        // FR-39: confirmed and automatically closed are the two endings, and
        // a row may claim exactly one of them.
        DB::statement(
            'ALTER TABLE maintenance_requests ADD CONSTRAINT maintenance_requests_closure_is_one_thing'
            .' CHECK ('
            ."     (confirmed_at IS NULL OR (status = '".MaintenanceRequestStatus::Closed->value."' AND auto_closed = false))"
            .' AND (auto_closed = false OR ('
            ."          status = '".MaintenanceRequestStatus::Closed->value."' AND confirmed_at IS NULL))"
            .' )'
        );

        // A closing has a moment, whichever of the two it was.
        DB::statement(
            'ALTER TABLE maintenance_requests ADD CONSTRAINT maintenance_requests_closed_has_a_moment'
            .' CHECK ('
            ."     status <> '".MaintenanceRequestStatus::Closed->value."' OR closed_at IS NOT NULL"
            .' )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_requests');
    }
};

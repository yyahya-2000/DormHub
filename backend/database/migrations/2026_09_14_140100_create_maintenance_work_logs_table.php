<?php

use App\Enums\MaintenanceRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MAINTENANCE_WORK_LOG of the ER model (§3.4.3), append-only by §3.4.1's sixth
 * decision: «recording each status change with actor, timestamp and comment
 * instead of overwriting a status field, so that “when was it reported and
 * when was it fixed” has an answer».
 *
 * The sentence is easy to read as bookkeeping and it is not. A request whose
 * `status` column is the only record of its history can say what it is and
 * never what happened to it: a request sitting at `accepted` for three weeks
 * looks exactly like one accepted this morning, and a request reopened twice
 * looks like one accepted once. FR-40 asks for age, FR-39 counts reopenings,
 * and FR-37's third criterion asks for «every transition stored with the
 * acting user and the time» — all three are questions about the sequence, and
 * a column that is overwritten holds no sequence at all.
 *
 * **`from_status` is nullable and the null means the first row.** Submission
 * is not a transition between two states; it is the state coming into
 * existence. Writing `submitted → submitted` would have avoided a nullable
 * column and made the log lie about what happened.
 *
 * **`actor_id` is nullable and the null means nobody.** `AutoCloseConfirmedWork`
 * closes a request the reporter never answered (FR-39), and no person took
 * that decision. Naming the scheduler as the actor would be the log asserting
 * that somebody looked; the null is the only honest way for the row to say
 * that the outcome was the calendar's. It is the same choice `guest_requests`
 * makes for a decision nobody took.
 *
 * There is no `updated_at`, for the same reason `audit_logs` has none: a row
 * that could be updated is not a log entry. The two mechanisms that make that
 * true are the model (`AppendOnlyBuilder` and the refusing hooks) and the
 * migration beside this one, which revokes UPDATE and DELETE from the
 * application's database role exactly as §4.4.4 writes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_work_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('maintenance_request_id')->constrained()->restrictOnDelete();

            // Null for the scheduled closure of FR-39: nobody decided.
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();

            // Null on the first row: the request came into being.
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);

            $table->text('comment')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // The history of one request, in order. The only way this table is
            // ever read.
            $table->index(['maintenance_request_id', 'id']);
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $known = "'".implode("','", MaintenanceRequestStatus::values())."'";

        DB::statement(
            'ALTER TABLE maintenance_work_logs ADD CONSTRAINT maintenance_work_logs_statuses_known'
            ." CHECK (to_status IN ({$known}) AND (from_status IS NULL OR from_status IN ({$known})))"
        );

        // A row that records no movement records nothing. The one legitimate
        // repetition — a request accepted, reopened and accepted again — is
        // two rows with `completed` between them, not one row saying a request
        // became what it already was.
        DB::statement(
            'ALTER TABLE maintenance_work_logs ADD CONSTRAINT maintenance_work_logs_record_a_movement'
            .' CHECK (from_status IS NULL OR from_status <> to_status)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_work_logs');
    }
};

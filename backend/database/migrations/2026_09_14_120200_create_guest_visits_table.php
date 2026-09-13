<?php

use App\Enums\GuestVisitStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GUEST_VISIT of the ER model (§3.4.3): the electronic form of the journal
 * clause 2.1.2 of the HSE rules of internal order makes the security service
 * keep by hand. It is the same register with the pen removed (§2.5.2).
 *
 * **The six fields of clause 2.1.2 are not all here, and that is the point of
 * §3.4.1's fourth decision.** The clause asks for information about the guest,
 * the time of arrival and of departure, the premises, whom the guest is
 * visiting, and the details of the document. Three of those live on
 * `GUEST_REQUEST`, which this table joins to; the two times and the operator
 * live here. Splitting them is what lets a request be cancelled without a
 * visit ever existing, and lets the request's personal data be depersonalised
 * on one schedule while the aggregate visit fact is kept on another (§3.9.4).
 *
 * **`due_at` is frozen at the moment of entry, not computed on the way out.**
 * It is the departure deadline FR-18's card shows and FR-20 measures against:
 * the earlier of the end of the approved interval and the building's control
 * time. Recomputing it later would mean that changing `BUILDING.curfew_at`
 * silently rewrote what last week's visits were judged by — a register whose
 * past changes when a setting does is not a register.
 *
 * **Write once, and three mechanisms rather than one (FR-21, NFR-14).** The
 * ordering CHECK refuses an exit before the entry. The trigger refuses any
 * change to a fact already written — entry, exit, operator, the overdue notice
 * — so `UPDATE guest_visits SET checked_out_at = …` cannot rewrite an evening
 * even from psql. And the service refuses it first, with an exception a caller
 * can read. The correction of a mistake is a new row in `audit_logs` naming
 * this visit, which is what «a correction is made as a correcting entry»
 * means.
 *
 * The trigger is not the revoke-grant device the audit log uses, and the
 * difference is deliberate: `guest_visits` has to admit exactly two later
 * writes — the exit, and the overdue mark — so UPDATE cannot be taken away
 * wholesale. What the trigger takes away is the ability to change a column
 * that already says something.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_visits', function (Blueprint $table) {
            $table->id();

            // One request yields at most one visit (§3.4.1, decision 4): the
            // relation is 1:0..1 and the unique index is what makes it so.
            $table->foreignId('guest_request_id')->unique()->constrained()->restrictOnDelete();

            $table->timestamp('checked_in_at');
            $table->foreignId('checked_in_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('checked_out_at')->nullable();
            $table->foreignId('checked_out_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->string('status', 32)->default(GuestVisitStatus::InBuilding->value);

            // The departure deadline this visit is judged by.
            $table->timestamp('due_at');

            // FR-20: set once, by the sweep, and the reason a repeat run sends
            // nothing a second time.
            $table->timestamp('overdue_notified_at')->nullable();

            // §2.4.2, second scenario: the guest was outside the permitted
            // interval and was admitted on the responsible officer's decision.
            // The reason is mandatory when the flag is set, which the CHECK
            // below enforces — an override with no reason recorded is the one
            // shape of this row that would tell nobody anything.
            $table->boolean('admitted_on_decision')->default(false);
            $table->text('admission_note')->nullable();

            $table->timestamps();

            // «Who is still inside this building» — US-04, and the query the
            // quarter-hourly sweep of FR-20 runs.
            $table->index(['status', 'due_at']);
            $table->index('checked_in_at');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE guest_visits ADD CONSTRAINT guest_visits_status_known'
            ." CHECK (status IN ('".implode("','", GuestVisitStatus::values())."'))"
        );

        // FR-19, second criterion: «an exit cannot be recorded earlier than
        // the entry». In the database, where no application mistake reaches.
        DB::statement(
            'ALTER TABLE guest_visits ADD CONSTRAINT guest_visits_exit_after_entry'
            .' CHECK (checked_out_at IS NULL OR checked_out_at >= checked_in_at)'
        );

        // An exit has a time and an operator, or neither of them.
        DB::statement(
            'ALTER TABLE guest_visits ADD CONSTRAINT guest_visits_exit_complete'
            .' CHECK ((checked_out_at IS NULL) = (checked_out_by IS NULL))'
        );

        // An admission on the responsible officer's decision states its reason.
        DB::statement(
            'ALTER TABLE guest_visits ADD CONSTRAINT guest_visits_override_is_reasoned'
            .' CHECK (admitted_on_decision = false OR admission_note IS NOT NULL)'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guest_visits_refuse_rewrite() RETURNS trigger AS $$
            BEGIN
                IF NEW.checked_in_at IS DISTINCT FROM OLD.checked_in_at
                    OR NEW.checked_in_by IS DISTINCT FROM OLD.checked_in_by
                    OR NEW.guest_request_id IS DISTINCT FROM OLD.guest_request_id
                    OR NEW.due_at IS DISTINCT FROM OLD.due_at
                    OR NEW.admitted_on_decision IS DISTINCT FROM OLD.admitted_on_decision
                THEN
                    RAISE EXCEPTION 'guest_visits is write-once: the entry recorded on visit % cannot be altered', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.checked_out_at IS NOT NULL
                    AND (NEW.checked_out_at IS DISTINCT FROM OLD.checked_out_at
                        OR NEW.checked_out_by IS DISTINCT FROM OLD.checked_out_by)
                THEN
                    RAISE EXCEPTION 'guest_visits is write-once: the exit recorded on visit % cannot be altered', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.overdue_notified_at IS NOT NULL
                    AND NEW.overdue_notified_at IS DISTINCT FROM OLD.overdue_notified_at
                THEN
                    RAISE EXCEPTION 'guest_visits is write-once: visit % has already been reported overdue', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(
            'CREATE TRIGGER guest_visits_write_once BEFORE UPDATE ON guest_visits'
            .' FOR EACH ROW EXECUTE FUNCTION guest_visits_refuse_rewrite()'
        );

        // A deletion would take the evening with it. The register is the
        // substitute for a paper journal whose pages are numbered.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guest_visits_refuse_deletion() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'guest_visits is append-only: visit % cannot be deleted', OLD.id
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(
            'CREATE TRIGGER guest_visits_no_deletion BEFORE DELETE ON guest_visits'
            .' FOR EACH ROW EXECUTE FUNCTION guest_visits_refuse_deletion()'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS guest_visits_no_deletion ON guest_visits');
            DB::unprepared('DROP TRIGGER IF EXISTS guest_visits_write_once ON guest_visits');
            DB::unprepared('DROP FUNCTION IF EXISTS guest_visits_refuse_deletion()');
            DB::unprepared('DROP FUNCTION IF EXISTS guest_visits_refuse_rewrite()');
        }

        Schema::dropIfExists('guest_visits');
    }
};

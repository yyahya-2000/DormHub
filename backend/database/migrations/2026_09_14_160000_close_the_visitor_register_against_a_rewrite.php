<?php

use App\Enums\GuestRequestStatus;
use App\Enums\GuestVisitStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FR-21, second criterion — «entries are immutable; a correction is made as a
 * correcting entry» — applied to the half of the register that was left open.
 *
 * **The register is two tables and the guarantee was on one of them.** Clause
 * 2.1.2 of the HSE rules of internal order makes the security service record
 * six things: information about the guest, the time of arrival, the time of
 * departure, the premises, whom the guest is visiting, and the details of the
 * document. Three of those — the guest's name, the document, the host and
 * therefore the room — are columns of `guest_requests`; only the two times and
 * the operator live on `guest_visits`. The write-once trigger created with
 * `guest_visits` held its half. The other half had nothing at all, so
 *
 *     UPDATE guest_requests SET guest_full_name = 'somebody else' WHERE id = 3;
 *
 * succeeded under the application role and the export of FR-21 then showed the
 * substituted name without a mark of any kind. A register that can be quietly
 * rewritten is not a register, and the paper journal it replaces is a book with
 * numbered pages.
 *
 * **Where the line falls: at the decision.** A request is a draft while nobody
 * has looked at it. `pending_review` is the state in which the resident may
 * still correct a mistyped surname and in which the duty officer's decision is
 * written onto the row, and nothing is refused there. From the decision onwards
 * the row is evidence: the approval names a particular guest, the access code
 * rests on that name, and the entry at the post is recorded against it. So
 * every field the journal reads — and the decision itself — is frozen once the
 * request has left `pending_review`, while `status` stays free, because the
 * lifecycle of §3.5.4 is exactly the sequence of legitimate later writes.
 *
 * Deletion follows the same line. A draft may be dropped; a decided request may
 * not, and this is stricter than the foreign key alone, which only protects the
 * requests that reached a visit. A guest refused at the post leaves no
 * `guest_visits` row at all — §3.9.6 keeps that refusal in the audit log — and
 * deleting the request would leave the log pointing at nothing.
 *
 * **And two columns the visit's own trigger had left open.** `admission_note`
 * is the written ground on which the officer admitted a guest against the
 * interval (§2.4.2, second scenario) and the single piece of evidence that the
 * exception was reasoned; it is written at the entry and never again. `status`
 * was unguarded altogether, so a closed evening could be set back to
 * `in_building` — the trigger now admits only the moves §3.5.4 and
 * `GuestVisitStatus` allow, and none out of a closed state.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared($this->guestRequestRewriteGuard());

        DB::unprepared(
            'CREATE TRIGGER guest_requests_write_once BEFORE UPDATE ON guest_requests'
            .' FOR EACH ROW EXECUTE FUNCTION guest_requests_refuse_rewrite()'
        );

        DB::unprepared($this->guestRequestDeletionGuard());

        DB::unprepared(
            'CREATE TRIGGER guest_requests_no_deletion BEFORE DELETE ON guest_requests'
            .' FOR EACH ROW EXECUTE FUNCTION guest_requests_refuse_deletion()'
        );

        // The trigger on `guest_visits` already points at this function; only
        // its body changes, so the two columns are closed without a moment in
        // which the table is unguarded.
        DB::unprepared($this->guestVisitRewriteGuard());
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS guest_requests_no_deletion ON guest_requests');
        DB::unprepared('DROP TRIGGER IF EXISTS guest_requests_write_once ON guest_requests');
        DB::unprepared('DROP FUNCTION IF EXISTS guest_requests_refuse_deletion()');
        DB::unprepared('DROP FUNCTION IF EXISTS guest_requests_refuse_rewrite()');

        DB::unprepared($this->guestVisitRewriteGuardBeforeThisMigration());
    }

    /**
     * Everything clause 2.1.2 reads off the request, plus the decision that
     * gave the visit its ground — frozen from the moment the request stops
     * being a draft.
     */
    private function guestRequestRewriteGuard(): string
    {
        $draft = GuestRequestStatus::PendingReview->value;

        return <<<SQL
            CREATE OR REPLACE FUNCTION guest_requests_refuse_rewrite() RETURNS trigger AS \$\$
            BEGIN
                IF OLD.status = '{$draft}' THEN
                    RETURN NEW;
                END IF;

                IF NEW.student_id IS DISTINCT FROM OLD.student_id
                    OR NEW.building_id IS DISTINCT FROM OLD.building_id
                    OR NEW.guest_full_name IS DISTINCT FROM OLD.guest_full_name
                    OR NEW.guest_doc_type IS DISTINCT FROM OLD.guest_doc_type
                    OR NEW.guest_doc_number IS DISTINCT FROM OLD.guest_doc_number
                    OR NEW.is_foreign_document IS DISTINCT FROM OLD.is_foreign_document
                    OR NEW.visit_date IS DISTINCT FROM OLD.visit_date
                    OR NEW.planned_from IS DISTINCT FROM OLD.planned_from
                    OR NEW.planned_to IS DISTINCT FROM OLD.planned_to
                THEN
                    RAISE EXCEPTION 'guest_requests is write-once: request % has been decided and the register reads it', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.access_code IS DISTINCT FROM OLD.access_code
                    OR NEW.decided_by IS DISTINCT FROM OLD.decided_by
                    OR NEW.decided_at IS DISTINCT FROM OLD.decided_at
                    OR NEW.decision_comment IS DISTINCT FROM OLD.decision_comment
                    OR NEW.responsible_officer_mark IS DISTINCT FROM OLD.responsible_officer_mark
                    OR NEW.responsible_officer_mark_by IS DISTINCT FROM OLD.responsible_officer_mark_by
                    OR NEW.responsible_officer_mark_at IS DISTINCT FROM OLD.responsible_officer_mark_at
                THEN
                    RAISE EXCEPTION 'guest_requests is write-once: the decision on request % cannot be altered', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        SQL;
    }

    private function guestRequestDeletionGuard(): string
    {
        $draft = GuestRequestStatus::PendingReview->value;

        return <<<SQL
            CREATE OR REPLACE FUNCTION guest_requests_refuse_deletion() RETURNS trigger AS \$\$
            BEGIN
                IF OLD.status = '{$draft}' THEN
                    RETURN OLD;
                END IF;

                RAISE EXCEPTION 'guest_requests is append-only once decided: request % cannot be deleted', OLD.id
                    USING ERRCODE = 'restrict_violation';
            END;
            \$\$ LANGUAGE plpgsql;
        SQL;
    }

    /**
     * The visit's guard with `admission_note` and `status` added.
     */
    private function guestVisitRewriteGuard(): string
    {
        $inBuilding = GuestVisitStatus::InBuilding->value;
        $overdue = GuestVisitStatus::Overdue->value;
        $closed = GuestVisitStatus::Closed->value;
        $closedLate = GuestVisitStatus::ClosedLate->value;

        return <<<SQL
            CREATE OR REPLACE FUNCTION guest_visits_refuse_rewrite() RETURNS trigger AS \$\$
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

                -- §2.4.2's exception, and the only evidence that it was
                -- reasoned. Written with the entry and never afterwards.
                IF NEW.admission_note IS DISTINCT FROM OLD.admission_note THEN
                    RAISE EXCEPTION 'guest_visits is write-once: the ground on which visit % was admitted cannot be altered', OLD.id
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

                -- The evening moves one way. `in_building` may be reported
                -- overdue or closed, an overdue visit may still be closed, and
                -- a closed one is finished.
                IF NEW.status IS DISTINCT FROM OLD.status
                    AND NOT (
                        (OLD.status = '{$inBuilding}' AND NEW.status IN ('{$overdue}', '{$closed}', '{$closedLate}'))
                        OR (OLD.status = '{$overdue}' AND NEW.status IN ('{$closed}', '{$closedLate}'))
                    )
                THEN
                    RAISE EXCEPTION 'guest_visits is write-once: visit % cannot move from % to %', OLD.id, OLD.status, NEW.status
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        SQL;
    }

    /**
     * The body created with `guest_visits`, restored verbatim on a rollback.
     */
    private function guestVisitRewriteGuardBeforeThisMigration(): string
    {
        return <<<'SQL'
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
        SQL;
    }
};

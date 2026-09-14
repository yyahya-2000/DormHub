<?php

use App\Enums\GuestRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The register keeps the guest's name and nothing else about their papers.
 *
 * Clause 2.1.2 asks the security service for the details of the document, and
 * the first version of this table carried them: the type, the number under an
 * encrypted cast, the foreign flag derived from the type, and the three
 * columns of the responsible officer's mark that only a foreign document could
 * ever demand. All of it is dropped here. The MVP records who came, to whom
 * and when; the document stays in the officer's hand at the desk, where it is
 * compared against the person standing there and not against a row.
 *
 * `purpose` goes with them. It was free text nobody decided anything by.
 *
 * The write-once guard of `guest_requests` names every column it freezes, so
 * its body is rewritten here — a trigger referring to a dropped column fails
 * on the next update and not at the moment the column goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_requests', function (Blueprint $table) {
            $table->dropColumn([
                'guest_doc_type',
                'guest_doc_number',
                'is_foreign_document',
                'purpose',
                'responsible_officer_mark',
                'responsible_officer_mark_at',
            ]);
        });

        // Dropped on its own: the column carries a foreign key, and Postgres
        // wants the constraint named before the column goes on some versions.
        Schema::table('guest_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsible_officer_mark_by');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared($this->rewriteGuard());
    }

    public function down(): void
    {
        Schema::table('guest_requests', function (Blueprint $table) {
            $table->string('guest_doc_type', 32)->default('internal_passport');
            $table->text('guest_doc_number')->default('');
            $table->boolean('is_foreign_document')->default(false);
            $table->string('purpose', 255)->nullable();
            $table->text('responsible_officer_mark')->nullable();
            $table->foreignId('responsible_officer_mark_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('responsible_officer_mark_at')->nullable();
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared($this->rewriteGuardWithTheDocument());
    }

    private function rewriteGuard(): string
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
                THEN
                    RAISE EXCEPTION 'guest_requests is write-once: the decision on request % cannot be altered', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        SQL;
    }

    private function rewriteGuardWithTheDocument(): string
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
};

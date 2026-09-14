<?php

use App\Enums\GuestRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GUEST_REQUEST of the ER model (§3.4.3), and the first half of §3.4.1's
 * fourth modelling decision: the request and the visit are separate entities
 * because their lifecycles, their value and their retention rules all differ.
 *
 * **`guest_doc_number` is stored encrypted (§3.4.2, NFR-06)**, which is why it
 * is a `text` and not a short string — Laravel's encrypted cast produces a
 * base64 envelope several times the length of the plaintext. Two consequences
 * follow and both are design rather than accident. The column cannot be
 * indexed, so nothing searches by it; FR-18 asks for search by code and by
 * surname and never by document number, and §2.7.1's minimisation is the
 * reason that is the right list rather than a shortcut. And the mask shown on
 * screen is computed by decrypting, so the last four characters cost a key
 * operation — which is acceptable at the volumes §3.9.1 counts and would not
 * be at a hundred times them.
 *
 * **`access_code` is unique and nullable.** Null until the duty officer
 * approves: §3.5.1 issues the code only at approval, so that there is nothing
 * to present at the post before a decision exists. A partial unique index
 * would do as well; a plain one is used because PostgreSQL already treats
 * NULLs as distinct in a unique index, and the simpler object is the one a
 * reader can check.
 *
 * **`decided_by` restricts on delete.** A decision is attributable to the
 * person who took it (FR-17, first criterion), and an account removed later
 * must not quietly turn an attributed decision into an anonymous one.
 *
 * **The overnight mark of FR-23** is three columns rather than a boolean, for
 * the same reason `decided_by`/`decided_at` are two: «somebody marked this»
 * is evidence only if it says who and when.
 */
return new class extends Migration
{
    /**
     * The document types this table once knew. Spelled out rather than read
     * off an enum: the enum was deleted when the register stopped keeping the
     * guest's papers, and a migration that has already run must still be
     * replayable from an empty database.
     */
    private const DOCUMENT_TYPES = [
        'internal_passport',
        'foreign_passport',
        'residence_permit',
        'student_card',
        'driving_licence',
    ];

    public function up(): void
    {
        Schema::create('guest_requests', function (Blueprint $table) {
            $table->id();

            // The resident who invites. Model Rules cl. 3.4 makes them
            // answerable for the guest's timely departure, so the link is the
            // one FR-20's notification is addressed by.
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('building_id')->constrained()->restrictOnDelete();

            $table->string('guest_full_name');
            $table->string('guest_doc_type', 32);
            $table->text('guest_doc_number');
            $table->boolean('is_foreign_document')->default(false);
            $table->string('purpose', 255)->nullable();

            $table->date('visit_date');
            $table->time('planned_from');
            $table->time('planned_to');

            $table->string('status', 32)->default(GuestRequestStatus::PendingReview->value);
            $table->string('access_code', 16)->nullable()->unique();

            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();

            // FR-23. The mark of the officer responsible for migration
            // registration, without which an overnight interval on a foreign
            // document cannot be approved.
            $table->text('responsible_officer_mark')->nullable();
            $table->foreignId('responsible_officer_mark_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('responsible_officer_mark_at')->nullable();

            $table->timestamps();

            // The duty officer's queue: one building, one status, oldest
            // first. The only list this table is read as in the hot path.
            $table->index(['building_id', 'status', 'visit_date']);

            // «How many has this resident had approved for that day» — the
            // quota check of §3.3.4, asked on every approval.
            $table->index(['student_id', 'visit_date']);

            // FR-18's second search, by surname at the post. A plain index on
            // a name a person types part of buys less than it looks, but it
            // does carry the exact-match half, and the alternative — a
            // trigram index — is a PostgreSQL extension this deployment has no
            // other use for.
            $table->index('guest_full_name');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // The vocabulary of two columns, enforced where it cannot be bypassed.
        // The enums are the application's statement of the same thing; this is
        // what stops a hand-written UPDATE from inventing a ninth status that
        // every reader of the queue would then have to survive.
        DB::statement(
            'ALTER TABLE guest_requests ADD CONSTRAINT guest_requests_status_known'
            ." CHECK (status IN ('".implode("','", GuestRequestStatus::values())."'))"
        );

        DB::statement(
            'ALTER TABLE guest_requests ADD CONSTRAINT guest_requests_doc_type_known'
            ." CHECK (guest_doc_type IN ('".implode("','", self::DOCUMENT_TYPES)."'))"
        );

        /*
         * The code exists exactly when a decision has been taken in the
         * guest's favour, and never before one. §3.5.1 states it as a design
         * note; here it is a constraint, because it is the one property of
         * this table that a mistake in a service would turn into an admission
         * at the post.
         *
         * `cancelled` is the one status that admits either, and the asymmetry
         * is real rather than a hedge: a resident may withdraw a request
         * before the decision, when no code was ever drawn, and may withdraw
         * an approved one after the code exists, in which case the code stays
         * on the row so that a guest who turns up anyway is met with «this
         * visit was cancelled» instead of «no such code».
         */
        $codeless = [GuestRequestStatus::PendingReview->value, GuestRequestStatus::Rejected->value];

        $coded = [
            GuestRequestStatus::Approved->value,
            GuestRequestStatus::InProgress->value,
            GuestRequestStatus::Overdue->value,
            GuestRequestStatus::Completed->value,
            GuestRequestStatus::Expired->value,
        ];

        DB::statement(
            'ALTER TABLE guest_requests ADD CONSTRAINT guest_requests_code_follows_approval'
            .' CHECK ('
            ."     (status IN ('".implode("','", $codeless)."') AND access_code IS NULL)"
            ."  OR (status IN ('".implode("','", $coded)."') AND access_code IS NOT NULL)"
            ."  OR status = '".GuestRequestStatus::Cancelled->value."'"
            .' )'
        );

        /*
         * A decision has a moment. FR-17's first criterion asks for the
         * deciding user's identifier as well, and the constraint is
         * deliberately the weaker of the two implications — an author without
         * a moment is refused, a moment without an author is not.
         *
         * The asymmetry is FR-17's fourth criterion: «a request not processed
         * by the start of the visit is treated as rejected». That rejection
         * has a time and no author, because nobody took it; writing the
         * scheduler in as the author would be the log asserting that a person
         * decided something no person looked at.
         */
        DB::statement(
            'ALTER TABLE guest_requests ADD CONSTRAINT guest_requests_decision_has_a_moment'
            .' CHECK (decided_by IS NULL OR decided_at IS NOT NULL)'
        );

        // FR-23: the mark, its author and its moment travel together.
        DB::statement(
            'ALTER TABLE guest_requests ADD CONSTRAINT guest_requests_officer_mark_complete'
            .' CHECK ((responsible_officer_mark IS NULL) = (responsible_officer_mark_by IS NULL)'
            .' AND (responsible_officer_mark IS NULL) = (responsible_officer_mark_at IS NULL))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_requests');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FR-35, first criterion, guest half: «for the guest, before entry is recorded
 * at the security post».
 *
 * `consent_records` was built for account holders — `user_id` NOT NULL, one
 * consent per person per document — and a guest has no account. §2.7.1 is
 * emphatic that this is not an oversight to be papered over: the request is
 * submitted by the resident while the personal data belong to the guest, so
 * consent is taken **from the guest, at the post, in person**, and cannot be
 * given «on the guest's behalf» at submission. There is nobody to hang the row
 * on but the request itself.
 *
 * So the subject of a consent becomes one of two things, and the CHECK says
 * exactly one. An account, or a guest request; never both, because a record
 * that named both would leave «whose consent is this» to whoever read it
 * first, and never neither, because a consent with no subject proves nothing
 * — and art. 9 part 3 of Federal Law No. 152-FZ makes proving it the
 * operator's burden.
 *
 * **The partial unique index is duplicated rather than widened.** The existing
 * one covers `(user_id, document_code) WHERE revoked_at IS NULL`, and with a
 * null `user_id` PostgreSQL treats every guest row as distinct from every
 * other — which is correct for the column and useless as a rule. A second
 * index over `(guest_request_id, document_code)` restores it on the other
 * side: at most one consent in force per request, so a second click at the
 * desk cannot produce a second «current» consent for the same guest.
 *
 * **Cascade is again refused.** A guest request is the subject of this
 * evidence; `restrictOnDelete` keeps the evidence from disappearing with it,
 * exactly as the original migration keeps it from disappearing with a user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consent_records', function (Blueprint $table) {
            $table->foreignId('guest_request_id')
                ->nullable()
                ->after('user_id')
                ->constrained()
                ->restrictOnDelete();
        });

        Schema::table('consent_records', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE consent_records ADD CONSTRAINT consent_records_one_subject'
            .' CHECK ((user_id IS NULL) <> (guest_request_id IS NULL))'
        );

        DB::statement(
            'CREATE UNIQUE INDEX consent_records_guest_active_uniq'
            .' ON consent_records (guest_request_id, document_code)'
            .' WHERE revoked_at IS NULL'
        );

        // «What did this guest consent to, and when» — the question the post
        // and the operator both ask, and the one the check-in answers with a
        // refusal when there is no row.
        DB::statement(
            'CREATE INDEX consent_records_guest_request_idx'
            .' ON consent_records (guest_request_id, document_code, accepted_at)'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS consent_records_guest_request_idx');
            DB::statement('DROP INDEX IF EXISTS consent_records_guest_active_uniq');
            DB::statement('ALTER TABLE consent_records DROP CONSTRAINT IF EXISTS consent_records_one_subject');
        }

        Schema::table('consent_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guest_request_id');
        });

        Schema::table('consent_records', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};

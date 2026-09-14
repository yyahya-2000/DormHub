<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FR-35 is withdrawn from the MVP, and the table it was written for goes with
 * it.
 *
 * The requirement asked for a consent document to be displayed at first
 * sign-in, recorded against a text revision, withdrawable from the personal
 * account, and taken again from every guest at the security post before an
 * entry could be written. Four screens, two document repositories, a refusal
 * the checkpoint had to answer with, and a second reason a notification could
 * fail to arrive — for a prototype that processes invented people on a
 * development stand. What the MVP demonstrates is the visitor register of
 * clause 2.1.2 and the modules around it; the lawful-ground machinery belongs
 * to the deployment that carries real personal data, and is named among the
 * directions of development instead.
 *
 * **What is not withdrawn is the rest of §2.7.** Data minimisation stands —
 * the register still keeps no copy of a guest's document — the audit log still
 * records every reading of a card that carries personal data, and the role
 * model still decides who may read one. Consent was one instrument among
 * those; the others are the ones the MVP can actually be judged on.
 *
 * **The two migrations that built the table are left where they are.**
 * 2026_09_14_100200 created it and 2026_09_14_120300 gave a consent a second
 * kind of subject; both are correct statements about the schema on the day
 * they ran, and a history that is rewritten is not a history. The removal is
 * this migration and it is dated today.
 *
 * `down()` rebuilds the table as those two together left it — the nullable
 * subject, the CHECK that admits exactly one of them, both partial unique
 * indexes and both lookup indexes — so a rollback of this one alone lands on
 * the schema that was there before it. The rows are not rebuilt: every one of
 * them was an act performed by a person, and an act is not recoverable from a
 * schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('consent_records');
    }

    public function down(): void
    {
        Schema::create('consent_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('guest_request_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('document_code', 64);
            $table->string('document_revision', 32);
            $table->timestamp('accepted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'document_code', 'accepted_at']);
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX consent_records_active_uniq'
            .' ON consent_records (user_id, document_code)'
            .' WHERE revoked_at IS NULL'
        );

        DB::statement(
            'ALTER TABLE consent_records ADD CONSTRAINT consent_records_withdrawal_ordered'
            .' CHECK (revoked_at IS NULL OR revoked_at >= accepted_at)'
        );

        DB::statement(
            'ALTER TABLE consent_records ADD CONSTRAINT consent_records_one_subject'
            .' CHECK ((user_id IS NULL) <> (guest_request_id IS NULL))'
        );

        DB::statement(
            'CREATE UNIQUE INDEX consent_records_guest_active_uniq'
            .' ON consent_records (guest_request_id, document_code)'
            .' WHERE revoked_at IS NULL'
        );

        DB::statement(
            'CREATE INDEX consent_records_guest_request_idx'
            .' ON consent_records (guest_request_id, document_code, accepted_at)'
        );
    }
};

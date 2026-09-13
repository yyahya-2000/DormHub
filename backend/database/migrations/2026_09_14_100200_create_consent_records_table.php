<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CONSENT_RECORD of the ER model (§3.4.3) and the eighth modelling decision of
 * §3.4.1: «stores the fact, the timestamp and the identifier of the
 * consent-text revision shown, because FR-35 requires consent to be
 * demonstrable and revocable after the fact».
 *
 * The six columns are the criterion, one for one. `user_id` and
 * `document_code` are *which consent*; `accepted_at` is the fact and the date;
 * `document_revision` is the text — the identifier of a file under
 * `resources/consent`, which is what makes the record demonstrable rather than
 * merely present; `ip_address` is where it was given from; `revoked_at` is the
 * withdrawal.
 *
 * **The row is never deleted and never overwritten, and withdrawal is a
 * column.** Art. 9 part 3 of Federal Law No. 152-FZ puts the burden of proving
 * consent on the operator, and the operator has to prove it for the period
 * *before* the withdrawal as much as after — deleting the row on withdrawal
 * would destroy the evidence that the processing which already happened was
 * lawful. So withdrawal writes `revoked_at`, the history stays, and
 * «consented, withdrew, consented again» is three rows and not a flag flipping.
 *
 * **`consent_records_active_uniq`** is the same partial-index device §3.4.1
 * uses for residency, for the same reason: «at most one consent of this
 * document in force for this person» is a rule about concurrent writes, and a
 * read-then-insert in a service loses to two requests. A withdrawn row leaves
 * the index, so consenting again is free.
 *
 * **No cascade on the user.** `restrictOnDelete`, like the audit log: the
 * record of a consent is evidence, and evidence does not disappear because
 * somebody deleted the account it belonged to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('document_code', 64);
            $table->string('document_revision', 32);
            $table->timestamp('accepted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            // «What does this person's consent history say» — the personal
            // account's own question, and the operator's.
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

        // A consent cannot be withdrawn before it was given. The registry
        // asserts it as well; the database is the one that cannot be bypassed.
        DB::statement(
            'ALTER TABLE consent_records ADD CONSTRAINT consent_records_withdrawal_ordered'
            .' CHECK (revoked_at IS NULL OR revoked_at >= accepted_at)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_records');
    }
};

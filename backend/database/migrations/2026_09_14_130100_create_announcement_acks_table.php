<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ANNOUNCEMENT_ACK of the ER model (§3.4.3), and §3.4.1's fifth modelling
 * decision realised: **a row per acknowledgement, not a flag on the
 * announcement and not a counter beside it.**
 *
 * The reason is FR-12's second criterion. The warden needs «a named list of
 * those who have not read», and a list of names cannot be derived from a
 * number. A counter would answer «forty of sixty», which is the one thing a
 * disciplinary conversation cannot use: the conversation is with one person,
 * and the question is whether that person was told. So the table holds who and
 * when, the unread half is the left join that finds no row here, and the share
 * FR-12 also asks for is a count over the same rows — one record serving both
 * halves of the criterion instead of two that could disagree.
 *
 * **`UNIQUE (announcement_id, user_id)` is what makes the acknowledgement
 * idempotent.** A resident who taps twice, or a client that retries a request
 * whose answer was lost, must not produce a second row: the share would then
 * exceed one and the audience count would be a fiction. The service writes
 * with `firstOrCreate` and this index is what makes that safe when two taps
 * arrive at once.
 *
 * **`acknowledged_at` rather than `created_at`.** The moment is the substance
 * of the record and not bookkeeping about the row, so it is named for what it
 * is; the framework's timestamps are left off for the same reason, since a
 * row that is written once and never touched has nothing to say about being
 * updated.
 *
 * The announcement cascades and the user restricts, and the asymmetry is the
 * one the whole schema uses: an acknowledgement of a notice that no longer
 * exists is not evidence of anything, while an acknowledgement whose author
 * has been erased would turn a named record into an anonymous one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcement_acks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('acknowledged_at');

            // Idempotence, in the database rather than in a service.
            $table->unique(['announcement_id', 'user_id']);

            // «What has this person already read» — the left join of the feed
            // (§4.6.1), which is driven from the reader and not from the
            // announcement.
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_acks');
    }
};

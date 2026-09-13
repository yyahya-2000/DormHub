<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOTIFICATION of the ER model (§3.4.3), which is deliberately the framework's
 * own table and not a table of ours — §3.4.1, decision 7: «notifications use
 * the framework's own table, keeping the multi-channel machinery without a
 * bespoke schema».
 *
 * The consequence worth stating is that the in-app notification of FR-34 costs
 * nothing beyond this file. `database` becomes one channel beside `mail` in
 * every notification's `via()`, the unread count is `read_at IS NULL`, and the
 * queue worker the compose file already runs writes the row. A bespoke
 * `notifications` table would have had to reproduce the channel dispatch, the
 * queued delivery and the read flag to arrive at the same place.
 *
 * `data` is `jsonb` rather than the framework's default `text`, because the ER
 * model says `jsonb` and because PostgreSQL can then be asked about the
 * contents of a notification — «which overdue-visit notices name this visit» —
 * without the application parsing every row (§3.8.3).
 *
 * The primary key is a UUID, which is the framework's choice and not ours: the
 * notification id travels to the client and is used to mark one message read,
 * and a guessable sequence would let a client probe for the existence of other
 * people's messages. The route checks ownership regardless; the identifier is
 * the cheaper half of the same defence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->jsonb('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The personal account reads one person's messages, newest first,
            // and counts the unread among them. Both are this index.
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};

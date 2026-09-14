<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-12 leaves the MVP, and with it the whole apparatus of acknowledgement.
 *
 * The requirement asked the resident to confirm that a notice had been read
 * and the warden to see who had not. Two columns and a table carried it: the
 * `announcement_acks` row per person per notice, and `announcements.is_mandatory`,
 * which said whether a notice asked for that row at all. Neither is written
 * any more — there is no route to write them from — and a schema that keeps a
 * table nothing fills is a claim that the requirement is still met.
 *
 * What the feed loses with them is the unread mark, which was the left join
 * against this table and nothing else. The announcement is now a notice that
 * is published, read and expires; who has read it is not recorded.
 *
 * The routine-announcement preference of FR-34 is untouched. It switches off
 * the optional notification category `announcement`, which is a question about
 * delivery and was never a question about acknowledgement — see
 * `2026_09_14_130200_let_a_resident_switch_off_routine_announcements`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('announcement_acks');

        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('is_mandatory');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->boolean('is_mandatory')->default(false);
        });

        Schema::create('announcement_acks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('acknowledged_at');

            $table->unique(['announcement_id', 'user_id']);
            $table->index('user_id');
        });
    }
};

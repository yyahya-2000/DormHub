<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FR-34's second criterion is withdrawn from the MVP, and the table it was
 * written for goes with it.
 *
 * The criterion asked for a switch per category, and the switch turned out to
 * cost more than it bought. A settings screen is one more screen to draw, one
 * more contract to keep, and a second reason a message can fail to arrive —
 * and a resident of the MVP has six categories, of which they would plausibly
 * turn off none. What remains of FR-34 is the part everybody actually uses:
 * the message arrives, the list shows it unread, opening it marks it read.
 *
 * **The categories themselves stay.** `App\Enums\NotificationCategory` is no
 * longer the unit a delivery rule is written against, but it is still the
 * label the personal account draws the message under, and it is still what
 * `restsOnConsent()` is decided per. What is gone is the second question —
 * «and has this person switched it off» — and with it the distinction between
 * a mandatory category and an optional one, which only ever meant «has a
 * switch» and «has none».
 *
 * **The two migrations that widened the CHECK are left where they are.** The
 * routine-announcement preference of 2026_09_14_130200 and the claim-notice
 * preference of 2026_09_14_150200 each added a category to the constraint
 * this migration now drops along with its table. They are correct statements
 * about the schema on the day they ran and a history that is rewritten is not
 * a history; the removal is this migration and it is dated today.
 *
 * `down()` rebuilds the table as the three migrations together left it, so a
 * rollback of this one alone lands on the schema that was there before it. The
 * rows are not rebuilt — every one of them was a person's decision and a
 * decision is not recoverable from a schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('notification_preferences');
    }

    public function down(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 64);
            $table->boolean('enabled');
            $table->timestamps();

            $table->unique(['user_id', 'category']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE notification_preferences'
                .' ADD CONSTRAINT notification_preferences_optional_only'
                ." CHECK (enabled OR category IN ('request_decision', 'maintenance_status',"
                ." 'announcement', 'lost_found_claim'))"
            );
        }
    }
};

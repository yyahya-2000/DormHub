<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FR-34, second criterion: «the user can disable non-mandatory categories».
 *
 * The ER model of §3.4.3 has no entity for this, and the addition is recorded
 * as such rather than slipped in: the diagram was drawn before the categories
 * of FR-34 were separated into mandatory and optional, and the criterion
 * cannot be met without somewhere to keep the choice.
 *
 * **Why a table and not a column on `users`.** A `jsonb` column would hold the
 * same map in fewer objects. It would not hold *when* the choice was made, and
 * that is the question this table exists to answer: a person who says they
 * were not told about an overdue visit is answered by a row that says the
 * category was switched off and on which day. The same argument decided
 * `ANNOUNCEMENT_ACK` in §3.4.1, decision 5 — a row rather than a flag,
 * because the row carries its own history.
 *
 * **Only a decision is stored.** The absence of a row means the default, and
 * the default is on: a person who has never opened the settings receives
 * everything. So the table holds the choices people actually made, which keeps
 * it proportional to the number of people who made one rather than to the
 * number of people times the number of categories.
 *
 * **`enabled` is a boolean and not merely the presence of a row.** Storing
 * only the mutes would be smaller and would lose the difference between «never
 * touched» and «switched off and deliberately back on», which is exactly the
 * difference an operator is asked about afterwards.
 *
 * The row cascades on delete of the user, unlike almost everything else in
 * this schema: it is a preference and not a record of anything that happened,
 * so it has no independent evidentiary value to preserve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 64);
            $table->boolean('enabled');
            $table->timestamps();

            // One decision per person per category. The service writes with an
            // upsert on this pair, so the uniqueness is what makes the write
            // safe against two settings screens saving at once.
            $table->unique(['user_id', 'category']);
        });

        /*
         * A mandatory category has no switch, so a row that claims one was
         * turned off is not a preference the application would honour — it is
         * a row that should not exist. The service refuses it, and the
         * database refuses it too, because a check that lives only in a
         * service is a check that an import or a console command walks past.
         *
         * The list is the optional half of App\Enums\NotificationCategory. It
         * is spelled out here rather than generated from the enum: a migration
         * is a statement about the schema as it was on the day it ran, and a
         * later case added to the enum must arrive with its own migration
         * rather than silently rewrite this one.
         */
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE notification_preferences'
                .' ADD CONSTRAINT notification_preferences_optional_only'
                ." CHECK (enabled OR category IN ('request_decision', 'maintenance_status'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};

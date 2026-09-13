<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FR-34 and FR-09 meeting: the routine announcement is an optional category
 * and the database has to admit a row that switches it off.
 *
 * `notification_preferences_optional_only` spells out the optional half of
 * `App\Enums\NotificationCategory` as a literal list, and its own migration
 * says why: «a migration is a statement about the schema as it was on the day
 * it ran, and a later case added to the enum must arrive with its own
 * migration rather than silently rewrite this one». This is that migration.
 *
 * `announcement` joins the list; `mandatory_announcement` does not, and the
 * omission is the point. An announcement the resident is required to
 * acknowledge rests on clause 4.2.7 of the rules of internal order rather than
 * on consent, and FR-12 makes its delivery evidential — a switch that could
 * silence it would leave the warden holding a list of people who had not
 * acknowledged a notice they were never sent.
 */
return new class extends Migration
{
    private const OPTIONAL = ['request_decision', 'maintenance_status', 'announcement'];

    private const PREVIOUS = ['request_decision', 'maintenance_status'];

    public function up(): void
    {
        $this->rewriteTheCheck(self::OPTIONAL);
    }

    public function down(): void
    {
        // A preference that switches off `announcement` would violate the
        // narrower constraint, so the rows go before it is put back.
        DB::table('notification_preferences')
            ->where('category', 'announcement')
            ->delete();

        $this->rewriteTheCheck(self::PREVIOUS);
    }

    /**
     * @param  list<string>  $categories
     */
    private function rewriteTheCheck(array $categories): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE notification_preferences'
            .' DROP CONSTRAINT IF EXISTS notification_preferences_optional_only'
        );

        DB::statement(
            'ALTER TABLE notification_preferences'
            .' ADD CONSTRAINT notification_preferences_optional_only'
            ." CHECK (enabled OR category IN ('".implode("', '", $categories)."'))"
        );
    }
};

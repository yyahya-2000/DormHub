<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FR-34 and FR-26 meeting: the claim notice is an optional category and the
 * database has to admit a row that switches it off.
 *
 * A migration of its own rather than an edit to the one before it, for the
 * reason that one gives in turn: «a migration is a statement about the schema
 * as it was on the day it ran, and a later case added to the enum must arrive
 * with its own migration rather than silently rewrite this one». This is the
 * second such migration and the third statement of the list.
 *
 * `lost_found_claim` is optional by the principle
 * `App\Enums\NotificationCategory` is drawn on: the movement of a claim is on
 * the person's own screen the moment they open the entry, and no provision of
 * the rules of internal order obliges anybody to answer a claim at all. The
 * module rests on residents being willing rather than on a duty (§2.5.4), so
 * the message rests on consent and the switch is real.
 */
return new class extends Migration
{
    private const OPTIONAL = [
        'request_decision',
        'maintenance_status',
        'announcement',
        'lost_found_claim',
    ];

    private const PREVIOUS = ['request_decision', 'maintenance_status', 'announcement'];

    public function up(): void
    {
        $this->rewriteTheCheck(self::OPTIONAL);
    }

    public function down(): void
    {
        // A preference that switches off `lost_found_claim` would violate the
        // narrower constraint, so the rows go before it is put back.
        DB::table('notification_preferences')
            ->where('category', 'lost_found_claim')
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

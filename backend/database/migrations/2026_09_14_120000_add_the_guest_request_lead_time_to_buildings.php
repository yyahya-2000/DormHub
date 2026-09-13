<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-16, first criterion: «the request is submitted no later than the lead
 * time **configured for the building**».
 *
 * A fourth regime setting beside `visiting_from`, `visiting_to` and
 * `curfew_at`, and it sits on `BUILDING` for the same reason they do (NFR-09):
 * the notice a dormitory wants before a visitor arrives is a matter of how
 * that dormitory is run, and Table 1.1 shows the sector does not agree on it.
 *
 * **The default is zero, and zero means «no notice required».** The HSE rules
 * of internal order set no lead time at all — clause 2.2 fixes the hours and
 * says nothing about how far ahead a resident must ask — so a non-zero default
 * would be the program inventing a house rule and then enforcing it. A
 * dormitory that wants four hours' notice sets four.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            $table->unsignedSmallInteger('guest_lead_time_hours')->default(0)->after('curfew_at');
        });
    }

    public function down(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            $table->dropColumn('guest_lead_time_hours');
        });
    }
};

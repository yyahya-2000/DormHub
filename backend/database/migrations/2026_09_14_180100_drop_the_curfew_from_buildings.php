<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One closing hour instead of two.
 *
 * `curfew_at` was a second boundary beside `visiting_to`, and on every
 * dormitory anybody configured the two held the same value. A form that asks
 * for both invites them to drift apart, and the departure deadline then
 * depends on which of the two the reader remembers. The end of the visiting
 * window is the hour the rules of internal order name, so it is the one that
 * stays; `GuestRequest::dueAt()` now caps an approved interval against it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            $table->dropColumn('curfew_at');
        });
    }

    public function down(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            $table->time('curfew_at')->default('23:00:00')->after('visiting_to');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-42, third criterion: «a one-time credential is delivered to the confirmed
 * contact rather than shown on screen, and the resident sets their own
 * password on first sign-in».
 *
 * The account an incoming resident receives is created with a random secret
 * that is generated, hashed and forgotten in the same statement — nobody, the
 * warden who created the account included, ever sees it. That alone makes the
 * account unusable until the resident sets a password through the one-time
 * token they were sent, so the column below is not what enforces the rule.
 *
 * What it does is make the state visible. An account waiting for its first
 * password is a different thing from an account whose password merely happens
 * to be unknown, and a client that cannot tell them apart can only guess which
 * screen to draw. `/auth/me` carries the flag, and setting a password clears
 * it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('password_change_required')->default(false)->after('password_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password_change_required');
        });
    }
};

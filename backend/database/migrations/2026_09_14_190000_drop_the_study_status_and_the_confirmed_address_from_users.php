<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns the MVP does not earn.
 *
 * `study_status` was on the resident card because art. 105 cl. 2 of the
 * Housing Code ties the term of an accommodation contract to the term of
 * study. That is true and it is still not this system's fact: nothing here
 * reads the column to decide anything, and the university's own register is
 * where the answer lives. A field the office has to retype and nobody checks
 * is a field that is wrong within a term.
 *
 * `email_confirmed_at` was stamped at the one moment a one-time code sent to
 * an address was spent on it. There are no one-time codes any more — an
 * account is created with a generated password that is printed and handed
 * over — so nothing in the application can stamp the column, and a column
 * nothing writes says nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['study_status', 'email_confirmed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('study_status', 32)->nullable()->after('phone');
            $table->timestamp('email_confirmed_at')->nullable()->after('email');
        });
    }
};

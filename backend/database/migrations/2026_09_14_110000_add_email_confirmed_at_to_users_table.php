<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-42: the moment the address on an account stopped being a claim and became
 * a fact.
 *
 * The criterion used to read «delivered to the confirmed contact», and nothing
 * in the system confirmed anything: the address was typed by the person
 * creating the account, checked for shape, and written. A slip of a finger sent
 * the credential to a stranger, and neither the resident nor the office had any
 * way of noticing.
 *
 * Confirming an address the ordinary way — send a link, wait for a click —
 * cannot be done here, because the message that would carry the link is the
 * same message that carries the credential: the account has no other way in.
 * What the system can state honestly is the weaker fact this column holds. A
 * one-time code sent to this address was received and spent, so somebody who
 * reads this address acted on it. The column is stamped once, in
 * `App\Services\PasswordSetup`, at the moment the code is exchanged for a
 * password.
 *
 * That is the narrowed criterion of 14.09.2026, and it is narrower on purpose:
 * a nullable column that is null for every account awaiting its first sign-in
 * is exactly the set of accounts whose address nobody has yet proved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('email_confirmed_at')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_confirmed_at');
        });
    }
};

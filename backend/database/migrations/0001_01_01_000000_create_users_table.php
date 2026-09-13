<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * USER of the ER model (§3.4.3). The column set follows the diagram: the
 * external identifier used by the university identity provider, the person's
 * name, the two contact fields, the password hash and the account status.
 *
 * `status` is an enumeration rather than a lookup table (§4.4.2): the values
 * are meaningful only together with the code that acts on them, so they are
 * a short string column carrying a CHECK constraint plus a backed PHP enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->nullable()->unique();
            $table->string('full_name');
            $table->string('email')->unique();
            $table->string('phone', 32)->nullable();
            $table->string('password_hash');
            $table->enum('status', ['active', 'blocked', 'archived'])->default('active');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};

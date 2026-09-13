<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AUDIT_LOG of the ER model (§3.4.3). The record format of §3.9.6 is
 * «who, what, over which object, when, from which IP, result», so `result`
 * joins the six diagram columns.
 *
 * `user_id` is nullable because a failed sign-in with an unknown login has no
 * author, and it restricts on delete rather than nulling: a log row is never
 * rewritten, not even by a cascade (§4.4.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('action', 64);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->jsonb('payload')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->enum('result', ['success', 'failure', 'denied'])->default('success');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ROLE_USER of the ER model (§3.4.3), and the single line that makes FR-07
 * enforceable: `building_id` scopes the grant to one dormitory and is NULL
 * only for a system-wide role (§3.4.1, decision 1). Without it the duty
 * officer of block 1 could decide requests in block 2.
 *
 * ON DELETE RESTRICT on every reference to registry data (§4.4.1): a role or
 * a building that is still granted to somebody cannot be deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->foreignId('building_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('granted_at')->useCurrent();

            $table->index(['user_id', 'building_id']);
            $table->index(['role_id', 'building_id']);
        });

        // A grant is unique per user, role and scope. Two partial indexes are
        // needed rather than one plain UNIQUE, because a NULL building_id does
        // not collide with another NULL under SQL's default comparison rules,
        // which would let the same system-wide role be granted twice.
        DB::statement(
            'CREATE UNIQUE INDEX role_user_scoped_uniq ON role_user (user_id, role_id, building_id)'
            .' WHERE building_id IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX role_user_global_uniq ON role_user (user_id, role_id)'
            .' WHERE building_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
    }
};

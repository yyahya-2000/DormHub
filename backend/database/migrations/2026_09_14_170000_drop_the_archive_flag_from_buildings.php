<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `buildings.is_active` existed for one operation — archiving a dormitory —
 * and nothing else ever read it: no listing filtered on it, no policy asked
 * about it. The MVP keeps the register to create, edit and delete, so the
 * column and its index go with the operation they served.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->dropColumn('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);

            $table->index('is_active');
        });
    }
};

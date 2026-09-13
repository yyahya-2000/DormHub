<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUILDING of the ER model (§3.4.3). The three time columns are the
 * per-building regime settings NFR-09 requires; their defaults reproduce the
 * visiting window of clause 2.2 of the dormitory rules, 08:00 to 23:00.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('address');
            $table->unsignedSmallInteger('floors_count')->default(1);
            $table->time('visiting_from')->default('08:00:00');
            $table->time('visiting_to')->default('23:00:00');
            $table->time('curfew_at')->default('23:00:00');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buildings');
    }
};

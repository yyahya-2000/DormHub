<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BED of the ER model (§3.4.3), and the table that makes §3.4.1's second
 * decision real: the bed, not the room, is the unit of residency. Without it
 * «three beds in the room, two taken» cannot be said, and FR-02's criterion
 * has nothing to check against.
 *
 * `UNIQUE (room_id, label)` is the constraint FR-02 names in its verification
 * clause — a bed number is unique inside its room, and the database is what
 * says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->string('label', 16);
            $table->string('status', 32)->default('free');
            $table->timestamps();

            $table->unique(['room_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beds');
    }
};

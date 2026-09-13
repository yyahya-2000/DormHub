<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ROOM of the ER model (§3.4.3), the second table of the housing register.
 *
 * `ON DELETE RESTRICT` on `building_id` is not decoration. §4.4.1 states it
 * plainly: «A building with rooms cannot be deleted, which is the acceptance
 * criterion of FR-01 enforced by the database rather than by a controller.»
 * The service that handles the refusal reads the violation the database
 * raises; it does not decide the matter itself.
 *
 * `capacity` is the ceiling FR-02 checks against, and it is the number of
 * **beds** the room may hold. The rule that occupancy never exceeds capacity
 * is therefore enforced one level down, on `beds`, and not by counting
 * residencies against a number kept here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            $table->string('number', 32);
            $table->unsignedSmallInteger('floor');
            $table->unsignedSmallInteger('capacity');
            $table->string('type', 32)->default('corridor');
            $table->string('status', 32)->default('in_service');
            $table->timestamps();

            // A room number identifies a room inside its own building and
            // nowhere wider: block 1 and block 2 both have a room 301.
            $table->unique(['building_id', 'number']);

            // The warden's register is read one building at a time and shown
            // floor by floor (§4.4.3: indexes follow the queries).
            $table->index(['building_id', 'floor']);
        });

        DB::statement('ALTER TABLE rooms ADD CONSTRAINT rooms_capacity_positive CHECK (capacity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};

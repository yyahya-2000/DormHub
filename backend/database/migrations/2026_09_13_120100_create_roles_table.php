<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ROLE of the ER model (§3.4.3). The role stays a table rather than an
 * enumeration, because ROLE_USER references it and carries the building_id
 * that scopes the grant (§4.4.2). The five rows come from the reference
 * seeder and are never edited through the application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};

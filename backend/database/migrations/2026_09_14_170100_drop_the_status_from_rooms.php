<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `rooms.status` held «in service», «under repair» and «withdrawn from the
 * housing stock». For the MVP a room either is in the register or is not, and
 * the one state that still has to be expressed — a place nobody may be put in —
 * is `beds.status`, which the residency check already reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('status', 32)->default('in_service');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `residencies.moved_in_ground` was a free-text field on the accommodation
 * form. FR-05 asks for a ground on the **termination** and says nothing about
 * the move-in, so the column goes and `moved_out_ground` stays — together with
 * the check constraint `residencies_termination_grounded` that keeps it
 * mandatory once a departure date is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residencies', function (Blueprint $table) {
            $table->dropColumn('moved_in_ground');
        });
    }

    public function down(): void
    {
        Schema::table('residencies', function (Blueprint $table) {
            $table->string('moved_in_ground')->nullable();
        });
    }
};

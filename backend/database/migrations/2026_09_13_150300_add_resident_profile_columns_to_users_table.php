<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two fields FR-06 requires on the resident card that the account itself
 * did not yet carry: the study status and the citizenship.
 *
 * Both are nullable, and deliberately so. A warden account is not a student
 * and has neither; a resident registered before the university directory is
 * connected has neither yet. The card renders what is known and says nothing
 * about what is not, rather than inventing a default.
 *
 * `study_status` is an enumeration on the same argument as every other status
 * column in this schema (§4.4.2): a value means nothing until the code that
 * acts on it exists. It acts on FR-05, where a change of study status is the
 * usual ground for ending a residency under art. 105 cl. 2 of the Housing
 * Code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('study_status', 32)->nullable()->after('phone');
            $table->string('citizenship', 64)->nullable()->after('study_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['study_status', 'citizenship']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The name of a dormitory identifies it, and until now only the form said so.
 *
 * `StoreBuildingRequest` carries `Rule::unique('buildings', 'name')`, which
 * reads the table and then the controller writes to it — the check-then-insert
 * pair §3.4.1 rejects everywhere else in this schema, and rejects for a
 * reason: between the two statements another request commits, and the register
 * ends up with two «Block 1» that no later code can tell apart. The form rule
 * stays, because it is what turns the collision into a field error a person
 * can read; the index is what makes the rule true.
 *
 * A database that already holds two dormitories of one name is refused rather
 * than renamed: which of them is the real Block 1 is not a question a
 * migration can answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->refuseExistingDuplicates();

        Schema::table('buildings', function (Blueprint $table): void {
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::table('buildings', function (Blueprint $table): void {
            $table->dropUnique(['name']);
        });
    }

    private function refuseExistingDuplicates(): void
    {
        $duplicates = DB::table('buildings')
            ->select('name')
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('name')
            ->all();

        if ($duplicates === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'The register already holds more than one dormitory under each of these names, '
            .'so the unique index cannot be added: %s. Nothing has been changed. '
            .'Rename the duplicates and run the migration again.',
            implode(', ', array_map(static fn ($name): string => '"'.$name.'"', $duplicates)),
        ));
    }
};

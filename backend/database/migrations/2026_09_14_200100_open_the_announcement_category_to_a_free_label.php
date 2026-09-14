<?php

use App\Enums\AnnouncementCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `announcements.category` stops being a closed vocabulary.
 *
 * The column was created with a CHECK against the five cases of
 * `App\Enums\AnnouncementCategory`, so a warden could only ever post under a
 * heading the enumeration had foreseen. In practice a dormitory has subjects
 * the five do not cover, and refusing them with a 422 pushes the notice into
 * `general`, where the filter of FR-11 stops being of any use.
 *
 * So the constraint goes and the enumeration stays as a catalogue of
 * suggestions the client offers first — the same arrangement as `citizenship`
 * read in the opposite direction: there the list is closed because «RU» and
 * «Россия» must not be two countries; here the list is open because two
 * wardens naming two genuinely different subjects is the ordinary case.
 *
 * **The column keeps its length and that is now the whole of the limit.**
 * `VARCHAR(32)` was wide enough for the longest of the five and is the bound
 * the form request validates against, so a label the database would truncate
 * is refused before it reaches the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE announcements DROP CONSTRAINT IF EXISTS announcements_category_known');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // A label outside the catalogue would violate the constraint being put
        // back, so those rows return to the residual heading first.
        DB::table('announcements')
            ->whereNotIn('category', AnnouncementCategory::values())
            ->update(['category' => AnnouncementCategory::General->value]);

        DB::statement(
            'ALTER TABLE announcements ADD CONSTRAINT announcements_category_known'
            ." CHECK (category IN ('".implode("','", AnnouncementCategory::values())."'))"
        );
    }
};

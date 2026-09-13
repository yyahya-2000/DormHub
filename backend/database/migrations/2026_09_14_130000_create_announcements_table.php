<?php

use App\Enums\AnnouncementCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ANNOUNCEMENT of the ER model (§3.4.3), for FR-09 and FR-11.
 *
 * **`building_id` is the addressee, and NULL means «all buildings»**
 * (§3.4.2). That one nullable column is the whole of FR-09's first criterion:
 * the feed filters on it, so an announcement is visible to its audience and to
 * nobody else, and there is no second mechanism — no recipient list, no join
 * table — that could disagree with it. The administrator addresses every
 * dormitory by leaving it null; a warden cannot, because the policy refuses
 * him the null and his grant names one building.
 *
 * **`expires_at` is the archive, and there is no archiving job.** FR-09's
 * second criterion — «on expiry it moves to the archive and leaves the feed» —
 * is realised as a comparison in the feed query rather than as a status column
 * somebody has to move. A nightly sweep that flipped a flag would give the
 * system two answers to «is this announcement current», one of them up to
 * twenty-four hours old, and the disagreement would show on exactly the notice
 * that mattered. NULL means the announcement does not expire.
 *
 * **`category` and `is_mandatory` are separate columns**, as the ER model has
 * them. The first is the subject and is what FR-11's filter reads; the second
 * is whether FR-12 requires an acknowledgement. They vary independently — see
 * `App\Enums\AnnouncementCategory`.
 *
 * **`is_pinned` of the ER diagram is deliberately absent.** It belongs to
 * FR-10, which is Could priority and outside the MVP (§4.6.1). A column
 * nothing writes and nothing reads would be a claim in the schema that the
 * requirement is met.
 *
 * **The building foreign key cascades, and that is a departure worth stating.**
 * Almost everything else in this schema restricts, because almost everything
 * else is evidence about a person. An announcement is a notice, and a
 * dormitory that can be deleted at all is one with no rooms and no staff
 * attached — `BuildingRegistry::delete` is refused otherwise. Restricting here
 * would have that refusal reported as «0 room(s) are attached to it», which is
 * precisely the self-contradicting message `explainRefusal` was written to
 * stop. The author restricts, because FR-09 attributes the notice to the
 * person who posted it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();

            // NULL means every dormitory (§3.4.2). The administrator's alone.
            $table->foreignId('building_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();

            $table->string('title');
            $table->text('body');
            $table->string('category', 32)->default(AnnouncementCategory::General->value);

            // FR-12 turns on this column and on nothing else.
            $table->boolean('is_mandatory')->default(false);

            $table->timestamp('published_at');
            // NULL: the announcement does not expire and stays in the feed.
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            // The feed of FR-11, which is the only list this table is read as:
            // one dormitory's announcements, newest first. The null-building
            // rows share the index, PostgreSQL indexing NULLs like any other
            // value.
            $table->index(['building_id', 'published_at']);

            // FR-11's category filter.
            $table->index('category');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // The vocabulary of the column, enforced where a hand-written UPDATE
        // cannot walk past it — the same reason `guest_requests` carries one.
        DB::statement(
            'ALTER TABLE announcements ADD CONSTRAINT announcements_category_known'
            ." CHECK (category IN ('".implode("','", AnnouncementCategory::values())."'))"
        );

        /*
         * An announcement that expires before it appears is in no feed and in
         * no archive either: it would be a row nobody could ever reach, and
         * the warden who wrote it would have no way of telling that from a
         * delivery failure.
         */
        DB::statement(
            'ALTER TABLE announcements ADD CONSTRAINT announcements_expiry_follows_publication'
            .' CHECK (expires_at IS NULL OR expires_at > published_at)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};

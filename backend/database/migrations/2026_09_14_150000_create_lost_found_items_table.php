<?php

use App\Enums\LostFoundCustody;
use App\Enums\LostFoundItemKind;
use App\Enums\LostFoundItemStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LOST_FOUND_ITEM of the ER model (§3.4.3), with one column the diagram does
 * not carry and one pair of dates it insists on.
 *
 * **There is no moderation column, and its absence is the requirement.**
 * FR-24's fourth criterion reads «publication passes through no staff approval
 * step», and §2.5.4 gives the reason: routing every find through a member of
 * staff would put back the delay the module exists to remove. The vocabulary
 * below therefore has no `pending`, no `awaiting_review` and no `draft` for an
 * entry to wait in — a state nothing can reach is not a safeguard, it is an
 * invitation to add the route that reaches it. An entry is `published` from
 * the moment the row exists.
 *
 * **`custody` is the column the diagram does not carry, and it records which
 * of §2.5.4's two paths a find took.** By default the object stays with the
 * resident who found it; the alternative is an object handed in at the
 * security post or deposited with the administration for safekeeping, which is
 * the case Civil Code art. 227 cl. 1 para. 2 addresses — a thing found on
 * premises and handed to the person representing the owner of those premises,
 * who thereby acquires the rights and bears the duties of the finder. The
 * column says which happened. It does not make either happen, and nothing in
 * the provision requires the second: on the default path no hand-over within
 * the meaning of that paragraph takes place at all (§2.7.5).
 *
 * **`happened_on` and `declared_on` are two dates that must not be confused**
 * (§3.4.2). The first is the day the object was found. The second is the day
 * the find was declared to the police or to a local self-government body
 * (art. 227 cl. 2), and it is NULL while no declaration has been made — so the
 * six-month period of art. 228 cl. 1, which runs from the declaration and
 * never from the registration, simply does not start. FR-27's automated
 * control of that period is Could priority and outside the MVP; **both columns
 * are here all the same**, because a declaration date cannot be retrofitted
 * onto records created without one, and the retrofit is the part that is
 * impossible rather than merely late (§2.5.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lost_found_items', function (Blueprint $table) {
            $table->id();

            // FR-25: the feed is one dormitory's. Restricting on delete for
            // the reason every other register row does — a find belongs to the
            // history of the building it was found in.
            $table->foreignId('building_id')->constrained()->restrictOnDelete();

            /*
             * The person who published the entry: the finder on the default
             * path, the member of staff who took the object in on the other.
             *
             * FR-25 says this identifier never leaves the application: the
             * card shows neither the name nor the contacts of the registering
             * user, and `LostFoundItemResource` does not serialise the column
             * at all. It is here because the service needs it to answer «whose
             * decision is this», which is the whole of FR-26.
             */
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();

            $table->string('kind', 16)->default(LostFoundItemKind::Found->value);
            $table->string('custody', 32)->default(LostFoundCustody::Finder->value);

            // FR-24's «category», in the shape a dormitory actually uses it:
            // a short line naming the object. A fixed directory of categories
            // was the other candidate and is refused — the objects a dormitory
            // loses are keys, cards, chargers, umbrellas, single gloves and a
            // saucepan, and a list that covered them would be longer than the
            // descriptions it replaced.
            $table->string('title', 255);
            $table->text('description')->nullable();

            // FR-24: mandatory. Where the object was found, in words — the
            // register holds rooms and knows nothing about the landing between
            // the third and fourth floors.
            $table->string('place', 255);

            // FR-24: mandatory, and the day of the finding rather than the day
            // of the entry. `created_at` is the second of those and they are
            // not the same fact.
            $table->date('happened_on');

            // NULL while nothing has been declared. See the class docblock.
            $table->date('declared_on')->nullable();

            // FR-24: optional. A path in the object store, never the file.
            $table->string('photo_path', 2048)->nullable();

            $table->string('status', 32)->default(LostFoundItemStatus::Published->value);

            // FR-26: «the status becomes resolved with the time».
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            // FR-25's feed: one dormitory, the available entries, newest find
            // first. The day of the finding and not `created_at`, because that
            // is the date the reader is shown and the one they sort by.
            $table->index(['building_id', 'status', 'happened_on']);

            // «What I published», the resident's own screen.
            $table->index(['reporter_id', 'created_at']);
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $vocabularies = [
            'status' => LostFoundItemStatus::values(),
            'kind' => LostFoundItemKind::values(),
            'custody' => LostFoundCustody::values(),
        ];

        foreach ($vocabularies as $column => $values) {
            DB::statement(sprintf(
                'ALTER TABLE lost_found_items ADD CONSTRAINT lost_found_items_%s_known'
                ." CHECK (%s IN ('%s'))",
                $column,
                $column,
                implode("','", $values),
            ));
        }

        // FR-26: «the status becomes resolved with the time». Both ways round,
        // so that neither half can be written without the other — a resolved
        // entry with no moment, and a moment on an entry still in the feed,
        // are the same mistake seen from two sides.
        DB::statement(
            'ALTER TABLE lost_found_items ADD CONSTRAINT lost_found_items_resolution_has_a_moment'
            .' CHECK ((status = \''.LostFoundItemStatus::Resolved->value.'\') = (resolved_at IS NOT NULL))'
        );

        /*
         * A declaration cannot precede the finding. It is the one relation
         * between the two dates that holds whatever the university's process
         * turns out to be, and it is the one a typed year gets wrong.
         *
         * Nothing here requires a declaration, and nothing here dates one from
         * the registration: art. 227 cl. 2 puts the duty on the finder where
         * the entitled person is unknown, and whether the university files
         * such declarations is the university's to settle (§2.7.5).
         */
        DB::statement(
            'ALTER TABLE lost_found_items ADD CONSTRAINT lost_found_items_declaration_follows_the_finding'
            .' CHECK (declared_on IS NULL OR declared_on >= happened_on)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('lost_found_items');
    }
};

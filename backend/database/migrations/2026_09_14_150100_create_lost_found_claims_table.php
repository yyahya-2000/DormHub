<?php

use App\Enums\LostFoundClaimStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LOST_FOUND_CLAIM of the ER model (§3.4.3): «that is mine, and here is how I
 * know».
 *
 * The diagram carries id, item, claimant, message, status and the moment. Four
 * columns are added, and each of them answers a question FR-26 asks that the
 * six cannot.
 *
 * **`handover_point`.** The Gherkin of §2.4.4 ends the acceptance with «the
 * claimant is notified with the handover point», so the acceptance carries a
 * place and the place has to be readable afterwards — a person who reads the
 * notification on a telephone at midnight and looks for it again in the
 * morning must find it on the claim rather than in a message they may have
 * cleared. The CHECK below makes an acceptance without one impossible, which
 * is the same shape FR-37's «acceptance without a planned completion date is
 * impossible» takes in the maintenance table.
 *
 * **`decided_by` and `decided_at`.** FR-26's first criterion distinguishes a
 * claim the *finder* accepted from a *warden's* decision on a referred one,
 * and the distinction is the module's central one (§2.5.4): the ordinary path
 * is peer-to-peer and the warden is the exception. A status column alone
 * cannot tell the two apart — both end at `accepted` — so the identifier of
 * whoever decided is what makes the record able to answer «who settled this».
 *
 * **`referred_at`.** The referral is the claimant's own act and not a second
 * claim, and it is the moment at which §2.5.4's first exception begins. It is
 * also what stops the loop: a claim carrying a referral cannot be referred
 * again, so a refusal cannot be bounced back and forth.
 *
 * **The partial unique index is the rule the API would otherwise have to keep
 * by hand.** One person may hold one outstanding claim on one entry. Without
 * it, a claimant refused once can file the same sentence again and again, and
 * the holder is left answering the same claim until they stop reading. A
 * settled claim — declined, or accepted — does not block a later one, which is
 * why the index is partial rather than a plain unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lost_found_claims', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lost_found_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('claimant_id')->constrained('users')->restrictOnDelete();

            // FR-26: «describing identifying features». The whole substance of
            // a claim, and the only thing the holder has to judge it on.
            $table->text('message');

            $table->string('status', 16)->default(LostFoundClaimStatus::New->value);

            // Where the object changes hands, named by whoever accepted the
            // claim (§2.4.4).
            $table->string('handover_point', 255)->nullable();

            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamp('referred_at')->nullable();

            $table->timestamps();

            // The claims of one entry, oldest first: the list the holder
            // answers, and the only way this table is read from the item side.
            $table->index(['lost_found_item_id', 'id']);

            // «My claims», the claimant's own screen.
            $table->index(['claimant_id', 'created_at']);
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE lost_found_claims ADD CONSTRAINT lost_found_claims_status_known'
            ." CHECK (status IN ('".implode("','", LostFoundClaimStatus::values())."'))"
        );

        // §2.4.4: an acceptance names the handover point. Stated of the state
        // and not of the act, so that no later write can clear the place the
        // claimant was told to come to.
        DB::statement(
            'ALTER TABLE lost_found_claims ADD CONSTRAINT lost_found_claims_acceptance_names_a_place'
            .' CHECK (status <> \''.LostFoundClaimStatus::Accepted->value.'\' OR handover_point IS NOT NULL)'
        );

        // A decision has a person and a moment, or it has neither.
        DB::statement(
            'ALTER TABLE lost_found_claims ADD CONSTRAINT lost_found_claims_decision_is_complete'
            .' CHECK ((decided_by IS NULL) = (decided_at IS NULL))'
        );

        // Only a claim nobody has answered yet may be without one.
        DB::statement(
            'ALTER TABLE lost_found_claims ADD CONSTRAINT lost_found_claims_answered_claims_are_decided'
            .' CHECK (status = \''.LostFoundClaimStatus::New->value.'\' OR decided_at IS NOT NULL)'
        );

        // A referral has a moment, and a moment belongs to a referral or to
        // what a referral became.
        DB::statement(
            'ALTER TABLE lost_found_claims ADD CONSTRAINT lost_found_claims_referral_has_a_moment'
            .' CHECK (status <> \''.LostFoundClaimStatus::Referred->value.'\' OR referred_at IS NOT NULL)'
        );

        // One outstanding claim per person per entry. See the class docblock.
        DB::statement(
            'CREATE UNIQUE INDEX lost_found_claims_one_outstanding_per_claimant'
            .' ON lost_found_claims (lost_found_item_id, claimant_id)'
            ." WHERE status IN ('".implode("','", array_map(
                static fn (LostFoundClaimStatus $status): string => $status->value,
                LostFoundClaimStatus::outstanding(),
            ))."')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('lost_found_claims');
    }
};

<?php

declare(strict_types=1);

namespace Tests\Feature\LostFound;

use App\Enums\AuditAction;
use App\Enums\LostFoundClaimStatus;
use App\Enums\LostFoundItemStatus;
use App\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\LostFoundClaim;
use App\Models\LostFoundItem;
use App\Models\User;
use App\Notifications\LostFoundClaimDecided;
use App\Notifications\LostFoundClaimFiled;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsALostFoundScenario;
use Tests\TestCase;

/**
 * FR-26's staff half: «a claim the two sides cannot settle is referred to the
 * warden, who decides», and the first acceptance criterion's second ground for
 * a closure.
 *
 * A file of its own beside `LostFoundClaimTest` because this is §2.5.4's
 * exception rather than its rule, and the module's central decision is that
 * the two are kept apart: the ordinary path never reaches a member of staff,
 * and the only way one enters is the referral asserted here.
 */
final class LostFoundDisputeTest extends TestCase
{
    use BuildsALostFoundScenario, RefreshDatabase;

    private Building $building;

    private User $finder;

    private User $owner;

    private User $warden;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 09:30:00'));

        $this->building = $this->dormitory('Block A');
        $this->finder = $this->residentOf($this->building, 'finder@example.test', '412');
        $this->owner = $this->residentOf($this->building, 'owner@example.test', '305');
        $this->warden = $this->consentingStaff(RoleCode::Warden, $this->building, 'warden@example.test');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * FR-26 end to end, by the routes a client actually calls: publication,
     * claim, refusal, referral, the warden's decision, the return of the
     * object.
     *
     * **The path this test exists for is the one that was broken.** A refusal
     * returns the find to the published list — FR-26's second criterion — and
     * the referral of that refusal was leaving the entry there. The warden
     * then upheld the claim, the person holding the object pressed «returned»,
     * and the module answered 409: the item table admits no
     * `published → resolved` edge, deliberately, so FR-26's first criterion in
     * its second form — closure «on a warden's decision on a referred claim» —
     * could not be performed at all. The entry's status now follows the
     * claim's: outstanding again on the referral, out of the feed for the
     * length of the dispute, closed at the end of it.
     */
    public function test_the_dispute_runs_from_the_publication_to_the_return_of_the_object(): void
    {
        Notification::fake();

        // 1. The finder publishes.
        Sanctum::actingAs($this->finder);

        $itemId = $this->postJson('/api/v1/lost-found', $this->publication($this->building))
            ->assertStatus(201)
            ->json('data.id');

        $this->assertSame(LostFoundItemStatus::Published, LostFoundItem::query()->findOrFail($itemId)->status);

        // 2. The owner claims it, and the entry leaves the feed.
        Sanctum::actingAs($this->owner);

        $claimId = $this->postJson('/api/v1/lost-found/'.$itemId.'/claims', $this->claimBody())
            ->assertStatus(201)
            ->json('data.id');

        $this->assertSame(LostFoundItemStatus::Claimed, LostFoundItem::query()->findOrFail($itemId)->status);

        // 3. The finder refuses, and FR-26's second criterion puts the entry
        //    back into the published list — nothing is outstanding on it.
        Sanctum::actingAs($this->finder);

        $this->postJson('/api/v1/lost-found/claims/'.$claimId.'/decline', [
            'reason' => 'The marks you describe are not the ones on this umbrella.',
        ])->assertStatus(200)->assertJsonPath('data.status', LostFoundClaimStatus::Declined->value);

        $this->assertSame(LostFoundItemStatus::Published, LostFoundItem::query()->findOrFail($itemId)->status);

        // 4. The owner refers the refusal. The claim is outstanding again, so
        //    the entry leaves the feed again — which is the fix.
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/lost-found/claims/'.$claimId.'/referral', [
            'note' => 'I can describe the inside of the case as well; please look again.',
        ])->assertStatus(200)->assertJsonPath('data.status', LostFoundClaimStatus::Referred->value);

        $this->assertSame(LostFoundItemStatus::Claimed, LostFoundItem::query()->findOrFail($itemId)->status);

        // And the contract's promise, checked against the feed rather than
        // against the column: the object is not offered to anybody else while
        // the warden is looking at it.
        $this->getJson('/api/v1/lost-found')->assertStatus(200)->assertJsonCount(0, 'data');

        // 5. The warden upholds the claim.
        Sanctum::actingAs($this->warden);

        $this->postJson('/api/v1/lost-found/claims/'.$claimId.'/decision', [
            'upheld' => true,
            'handover_point' => 'The warden\'s office, on a weekday morning',
            'note' => 'The claimant described the inside of the case correctly.',
        ])->assertStatus(200)->assertJsonPath('data.status', LostFoundClaimStatus::Accepted->value);

        $this->assertSame(LostFoundItemStatus::Claimed, LostFoundItem::query()->findOrFail($itemId)->status);

        // 6. The object changes hands and the person holding it says so. This
        //    is the call that used to answer 409.
        Sanctum::actingAs($this->finder);

        $this->postJson('/api/v1/lost-found/'.$itemId.'/resolve')
            ->assertStatus(200)
            ->assertJsonPath('data.status', LostFoundItemStatus::Resolved->value);

        $closed = LostFoundItem::query()->findOrFail($itemId);

        $this->assertSame(LostFoundItemStatus::Resolved, $closed->status);
        $this->assertNotNull($closed->resolved_at);

        // FR-26, third criterion: after closure the record is gone from the
        // public list on any filter.
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/lost-found')->assertStatus(200)->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/lost-found?status=claimed')->assertStatus(200)->assertJsonCount(0, 'data');
    }

    /**
     * The other ending of the same path: the warden does not uphold the claim,
     * and the entry the referral withdrew goes back into the feed.
     *
     * The mirror of the test above, and the reason the withdrawal is safe —
     * every refusal, the warden's included, runs the same question about what
     * is still outstanding, so the entry cannot be left stranded in `claimed`.
     */
    public function test_a_dispute_the_warden_refuses_puts_the_entry_back_into_the_feed(): void
    {
        $claim = $this->declinedClaim();

        $item = $claim->item()->firstOrFail();

        // The refusal returned it to the list; the referral takes it out.
        $this->assertSame(LostFoundItemStatus::Published, $item->fresh()?->status);

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/lost-found/claims/'.$claim->getKey().'/referral')->assertStatus(200);

        $this->assertSame(LostFoundItemStatus::Claimed, $item->fresh()?->status);

        Sanctum::actingAs($this->warden);
        $this->postJson('/api/v1/lost-found/claims/'.$claim->getKey().'/decision', [
            'upheld' => false,
            'note' => 'Neither description matches the object in the office.',
        ])->assertStatus(200);

        $this->assertSame(LostFoundItemStatus::Published, $item->fresh()?->status);

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/lost-found')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $item->getKey());

        // And nothing has been accepted, so the entry cannot be closed.
        Sanctum::actingAs($this->finder);
        $this->postJson('/api/v1/lost-found/'.$item->getKey().'/resolve')->assertStatus(409);
    }

    /**
     * A find that has gone home has nothing left to dispute.
     *
     * The consequence of the entry's status following the claim's, stated
     * deliberately rather than discovered: the referral has to take the entry
     * out of the feed, and `resolved` is final in the item table, so a refusal
     * referred after somebody else's claim closed the entry is 409. It could
     * not be anything else that helps — the warden may uphold a claim but the
     * module has no way to un-return an object, so a referral admitted here
     * would end in a decision nobody could act on.
     */
    public function test_a_refusal_cannot_be_referred_once_the_find_has_gone_home(): void
    {
        $refused = $this->declinedClaim();

        $item = $refused->item()->firstOrFail();

        // Somebody else describes it correctly, and the object changes hands.
        $neighbour = $this->residentOf($this->building, 'neighbour@example.test', '118');

        Sanctum::actingAs($neighbour);

        $rightful = $this->postJson('/api/v1/lost-found/'.$item->getKey().'/claims', $this->claimBody([
            'message' => 'The chip is on the upper side of the handle, and the tape is red rather than blue.',
        ]))->assertStatus(201)->json('data.id');

        Sanctum::actingAs($this->finder);

        $this->postJson('/api/v1/lost-found/claims/'.$rightful.'/accept', [
            'handover_point' => 'Room 412, any evening this week',
        ])->assertStatus(200);

        $this->postJson('/api/v1/lost-found/'.$item->getKey().'/resolve')->assertStatus(200);

        // And the earlier refusal now goes nowhere.
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/lost-found/claims/'.$refused->getKey().'/referral')
            ->assertStatus(409)
            ->assertJsonPath('status', LostFoundItemStatus::Resolved->value)
            ->assertJsonPath('attempted_status', LostFoundItemStatus::Claimed->value);

        $this->assertSame(LostFoundClaimStatus::Declined, $refused->fresh()?->status);
        $this->assertNull($refused->fresh()?->referred_at);
        $this->assertSame(LostFoundItemStatus::Resolved, $item->fresh()?->status);
    }

    /**
     * §2.4.4, second scenario, last line: «the claimant is offered the option
     * of referring the decision to the warden» — and this is the offer taken
     * up.
     *
     * The entry stays out of the feed while the disagreement is open: a
     * referred claim is outstanding, and offering the object to somebody else
     * while the warden is still looking at it is what the status prevents.
     */
    public function test_a_declined_claimant_refers_the_decision_to_the_warden(): void
    {
        Notification::fake();

        $claim = $this->declinedClaim();

        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/lost-found/claims/'.$claim->getKey().'/referral', [
            'note' => 'I can describe the inside of the case as well; please look again.',
        ])->assertStatus(200)->assertJsonPath('data.status', LostFoundClaimStatus::Referred->value);

        $referred = $claim->fresh();

        $this->assertNotNull($referred);
        $this->assertSame(LostFoundClaimStatus::Referred, $referred->status);
        $this->assertNotNull($referred->referred_at);
        $this->assertFalse($referred->mayBeReferred());

        // The warden of that dormitory is told, and the finder is not asked
        // again — the decision has left their hands.
        Notification::assertSentTo(
            $this->warden,
            LostFoundClaimFiled::class,
            fn (LostFoundClaimFiled $notice): bool => $notice->referred === true,
        );

        $this->assertSame(
            LostFoundItemStatus::Claimed,
            $referred->item()->firstOrFail()->status,
        );
    }

    /**
     * A claim may be referred once. A second attempt is the same 409 with the
     * same message, which is exactly what has happened to a claim the warden
     * has already refused.
     */
    public function test_a_claim_the_warden_has_already_refused_cannot_be_referred_again(): void
    {
        $claim = $this->declinedClaim();

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/lost-found/claims/'.$claim->getKey().'/referral')->assertStatus(200);

        Sanctum::actingAs($this->warden);
        $this->postJson('/api/v1/lost-found/claims/'.$claim->getKey().'/decision', [
            'upheld' => false,
            'note' => 'Neither description matches the object in the office.',
        ])->assertStatus(200);

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/lost-found/claims/'.$claim->getKey().'/referral')
            ->assertStatus(409)
            ->assertJsonPath('attempted_status', LostFoundClaimStatus::Referred->value);
    }

    /**
     * The referral is the claimant's own act. Nobody refers on their behalf —
     * not the person whose refusal is under review, and not the warden, who
     * would then be handing themselves a case.
     */
    public function test_only_the_claimant_refers_their_own_claim(): void
    {
        $claim = $this->declinedClaim();

        foreach ([$this->finder, $this->warden] as $person) {
            Sanctum::actingAs($person);

            $this->postJson('/api/v1/lost-found/claims/'.$claim->getKey().'/referral')
                ->assertStatus(403);
        }

        $this->assertSame(LostFoundClaimStatus::Declined, $claim->fresh()?->status);
    }

    /**
     * FR-26, first criterion, second ground: «a warden's decision on a
     * referred claim» makes the closure admissible.
     *
     * The decision moves the claim and not the entry. The object still has to
     * change hands, and the person who can say that it did is the person
     * handing it over — a warden closing an entry from a screen would be
     * recording a handover nobody witnessed.
     */
    public function test_the_warden_upholds_a_referred_claim_and_the_closure_becomes_admissible(): void
    {
        Notification::fake();

        $claim = $this->referredClaim();

        Sanctum::actingAs($this->warden);

        $this->postJson('/api/v1/lost-found/claims/'.$claim->getKey().'/decision', [
            'upheld' => true,
            'handover_point' => 'The warden\'s office, on a weekday morning',
            'note' => 'The claimant described the inside of the case correctly.',
        ])->assertStatus(200)->assertJsonPath('data.status', LostFoundClaimStatus::Accepted->value);

        $decided = $claim->fresh();

        $this->assertNotNull($decided);
        $this->assertSame(LostFoundClaimStatus::Accepted, $decided->status);
        $this->assertSame($this->warden->getKey(), $decided->decided_by);
        $this->assertSame('The warden\'s office, on a weekday morning', $decided->handover_point);

        // The entry is not closed by the decision.
        $item = $decided->item()->firstOrFail();
        $this->assertSame(LostFoundItemStatus::Claimed, $item->status);

        // Both sides are told: the claimant, and the person holding the object
        // whose refusal was reviewed.
        Notification::assertSentTo($this->owner, LostFoundClaimDecided::class);
        Notification::assertSentTo(
            $this->finder,
            LostFoundClaimDecided::class,
            fn (LostFoundClaimDecided $notice): bool => $notice->decidedByStaff === true,
        );

        // And the closure is now admissible to the person holding the object.
        Sanctum::actingAs($this->finder);

        $this->postJson('/api/v1/lost-found/'.$item->getKey().'/resolve')
            ->assertStatus(200)
            ->assertJsonPath('data.status', LostFoundItemStatus::Resolved->value);
    }

    /**
     * The warden not upholding it: the claim ends declined and the entry goes
     * back into the feed, because nothing is outstanding on it any more. The
     * claimant is offered nothing further — a claim a member of staff has
     * refused has nowhere to go inside the module, and a button that led
     * nowhere would be the interface promising otherwise.
     */
    public function test_the_warden_does_not_uphold_a_referred_claim_and_the_find_returns_to_the_list(): void
    {
        Notification::fake();

        $claim = $this->referredClaim();

        Sanctum::actingAs($this->warden);

        $this->postJson('/api/v1/lost-found/claims/'.$claim->getKey().'/decision', [
            'upheld' => false,
            'note' => 'The marks described do not match the object.',
        ])->assertStatus(200)->assertJsonPath('data.status', LostFoundClaimStatus::Declined->value);

        $this->assertSame(
            LostFoundItemStatus::Published,
            $claim->fresh()?->item()->firstOrFail()->status,
        );

        Notification::assertSentTo(
            $this->owner,
            LostFoundClaimDecided::class,
            fn (LostFoundClaimDecided $notice): bool => $notice->referralOffered === false,
        );
    }

    /**
     * A decision that overrides the person holding the object is stated with a
     * reason, in either direction — the same shape FR-37 gives a refusal.
     */
    public function test_a_wardens_decision_without_a_reason_is_refused(): void
    {
        $claim = $this->referredClaim();

        Sanctum::actingAs($this->warden);

        $this->postJson('/api/v1/lost-found/claims/'.$claim->getKey().'/decision', ['upheld' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');

        $this->postJson('/api/v1/lost-found/claims/'.$claim->getKey().'/decision', [
            'upheld' => true,
            'note' => 'The claimant is right.',
        ])->assertStatus(422)->assertJsonValidationErrors('handover_point');
    }

    /**
     * §2.5.4: the warden enters in two cases only. A claim nobody referred is
     * not one of them, and there is no route by which a member of staff
     * reaches an ordinary claim at all.
     */
    public function test_the_warden_cannot_decide_a_claim_nobody_referred(): void
    {
        $item = $this->findOf($this->finder, $this->building);
        $claim = $this->claimOn($item, $this->owner);
        $item->update(['status' => LostFoundItemStatus::Claimed]);

        Sanctum::actingAs($this->warden);

        $this->postJson('/api/v1/lost-found/claims/'.$claim->getKey().'/decision', [
            'upheld' => true,
            'handover_point' => 'The office',
            'note' => 'I have decided.',
        ])->assertStatus(409)
            ->assertJsonPath('status', LostFoundClaimStatus::New->value)
            // The step that never happened, named: the claim was never
            // referred, so there is nothing here for a member of staff.
            ->assertJsonPath('attempted_status', LostFoundClaimStatus::Referred->value);

        $this->assertSame(LostFoundClaimStatus::New, $claim->fresh()?->status);
    }

    /**
     * FR-07, the horizontal boundary: a warden of block A decides nothing in
     * block B. And the capability is the warden's and the manager's — not the
     * security officer's, who keeps objects and settles nothing, and not the
     * administrator's.
     */
    public function test_the_dispute_belongs_to_the_warden_and_the_manager_of_that_dormitory_and_to_nobody_else(): void
    {
        $claim = $this->referredClaim();

        $elsewhere = $this->dormitory('Block B');

        $outsiders = [
            'a warden of another dormitory' => $this->staff(RoleCode::Warden, $elsewhere, 'warden-b@example.test'),
            'the security officer' => $this->staff(RoleCode::SecurityOfficer, $this->building, 'post@example.test'),
            'the duty officer' => $this->staff(RoleCode::DutyOfficer, $this->building, 'duty@example.test'),
            'the administrator' => $this->staff(RoleCode::Administrator, null, 'admin@example.test'),
            'the person holding the object' => $this->finder,
            'the claimant' => $this->owner,
        ];

        foreach ($outsiders as $who => $person) {
            Sanctum::actingAs($person);

            $this->postJson('/api/v1/lost-found/claims/'.$claim->getKey().'/decision', [
                'upheld' => true,
                'handover_point' => 'Anywhere',
                'note' => 'A decision that is not theirs to take.',
            ])->assertStatus(403, sprintf('%s decided a referred claim.', $who));
        }

        // And the manager of that dormitory does hold it, which is what
        // §1.1.4's revision 2 puts with the register work.
        $manager = $this->consentingStaff(RoleCode::Manager, $this->building, 'manager@example.test');

        Sanctum::actingAs($manager);

        $this->postJson('/api/v1/lost-found/claims/'.$claim->getKey().'/decision', [
            'upheld' => false,
            'note' => 'Neither description matches.',
        ])->assertStatus(200);
    }

    /**
     * §3.9.6: the one decision in the module a member of staff takes over the
     * head of the person holding the object is recorded as an act of its own,
     * and not as another acceptance.
     */
    public function test_the_wardens_decision_is_recorded_as_an_act_of_its_own(): void
    {
        $claim = $this->referredClaim();

        Sanctum::actingAs($this->warden);

        $this->postJson('/api/v1/lost-found/claims/'.$claim->getKey().'/decision', [
            'upheld' => true,
            'handover_point' => 'The office',
            'note' => 'The claimant described the inside of the case correctly.',
        ])->assertStatus(200);

        $entry = AuditLog::query()
            ->where('action', AuditAction::LostFoundClaimDecidedByStaff->value)
            ->where('subject_id', $claim->getKey())
            ->sole();

        $this->assertSame($this->warden->getKey(), $entry->user_id);
        $this->assertTrue($entry->payload['decided_by_staff']);
        $this->assertSame(LostFoundClaimStatus::Referred->value, $entry->payload['from_status']);
    }

    /**
     * A claim the person holding the object has refused, which is the state
     * the referral starts from.
     *
     * **Filed and refused through the routes, not written to the table.** The
     * earlier version of this helper built the row with the factory and forced
     * the entry to `claimed` by hand, which is a state the application never
     * produces at this point: a refusal puts the entry back into the feed
     * (FR-26's second criterion), so the real dispute starts from `published`.
     * Every assertion about the entry in this file was therefore being made
     * against a fixture rather than against the module, and the gap that hid
     * is the one `test_the_dispute_runs_from_the_publication_to_the_return_of_the_object`
     * now closes.
     */
    private function declinedClaim(): LostFoundClaim
    {
        $item = $this->findOf($this->finder, $this->building);

        Sanctum::actingAs($this->owner);

        $claimId = $this->postJson('/api/v1/lost-found/'.$item->getKey().'/claims', $this->claimBody())
            ->assertStatus(201)
            ->json('data.id');

        Sanctum::actingAs($this->finder);

        $this->postJson('/api/v1/lost-found/claims/'.$claimId.'/decline', [
            'reason' => 'The marks you describe are not the ones on this umbrella.',
        ])->assertStatus(200);

        return LostFoundClaim::query()->findOrFail($claimId);
    }

    private function referredClaim(): LostFoundClaim
    {
        $claim = $this->declinedClaim();

        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/lost-found/claims/'.$claim->getKey().'/referral')->assertStatus(200);

        return $claim->fresh() ?? $claim;
    }
}

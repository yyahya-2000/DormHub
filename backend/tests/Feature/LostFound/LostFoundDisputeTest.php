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
     */
    private function declinedClaim(): LostFoundClaim
    {
        $item = $this->findOf($this->finder, $this->building);
        $item->update(['status' => LostFoundItemStatus::Claimed]);

        return LostFoundClaim::factory()
            ->on($item)
            ->by($this->owner)
            ->declined($this->finder, 'The marks you describe are not the ones on this umbrella.')
            ->create();
    }

    private function referredClaim(): LostFoundClaim
    {
        $claim = $this->declinedClaim();

        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/lost-found/claims/'.$claim->getKey().'/referral')->assertStatus(200);

        return $claim->fresh() ?? $claim;
    }
}

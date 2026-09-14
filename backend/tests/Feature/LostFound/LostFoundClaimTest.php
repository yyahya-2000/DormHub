<?php

declare(strict_types=1);

namespace Tests\Feature\LostFound;

use App\Enums\LostFoundClaimStatus;
use App\Enums\LostFoundItemStatus;
use App\Enums\RoleCode;
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
 * FR-26, «Claim for a find»: the two Gherkin scenarios of §2.4.4 that fall
 * inside the MVP, carried over word for word, and one test per acceptance
 * criterion.
 *
 * §2.4.4's third scenario — «expiry of the retention period» — is **FR-27**,
 * which is Could priority and outside the MVP as an automated control (§2.5.4).
 * There is nothing in this increment that counts the six months of Civil Code
 * art. 228 cl. 1, so a behaviour test of it would be a test of code that does
 * not exist. What the MVP does carry is the pair of dates the period will one
 * day be counted from, because a declaration date cannot be retrofitted onto
 * records created without one — and that is asserted in
 * `LostFoundRetentionDatesTest`, which records the scenario and states the
 * boundary rather than pretending to cross it.
 *
 * The clock is pinned throughout: the first scenario asserts «the status
 * becomes resolved **with the time**».
 */
final class LostFoundClaimTest extends TestCase
{
    use BuildsALostFoundScenario, RefreshDatabase;

    private Building $building;

    private User $finder;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 09:30:00'));

        $this->building = $this->dormitory('Block A');
        $this->finder = $this->residentOf($this->building, 'finder@example.test', '412');
        $this->owner = $this->residentOf($this->building, 'owner@example.test', '305');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * §2.4.4, first scenario, word for word:
     *
     *   Given a published find with a claim describing identifying features
     *   When the finder accepts the claim
     *   Then the claim moves to "accepted" and the claimant is notified with
     *        the handover point
     *    And when the finder then marks the item returned, the status becomes
     *        "resolved" with the time and the record no longer appears in the
     *        public list of available finds
     */
    public function test_the_finder_confirms_a_claim_and_the_item_goes_back_to_its_owner(): void
    {
        Notification::fake();

        // Given a published find with a claim describing identifying features.
        $find = $this->findOf($this->finder, $this->building);

        Sanctum::actingAs($this->owner);

        $claimId = $this->postJson('/api/v1/lost-found/'.$find->getKey().'/claims', $this->claimBody([
            'message' => 'The handle is chipped on the underside and there is a strip of blue tape near the tip.',
        ]))->assertStatus(201)->json('data.id');

        $this->assertSame(LostFoundItemStatus::Claimed, $find->fresh()?->status);
        Notification::assertSentTo($this->finder, LostFoundClaimFiled::class);

        // When the finder accepts the claim.
        Sanctum::actingAs($this->finder);

        $this->postJson('/api/v1/lost-found/claims/'.$claimId.'/accept', [
            'handover_point' => 'Room 412, after six in the evening',
        ])->assertStatus(200);

        // Then the claim moves to "accepted"…
        $claim = LostFoundClaim::query()->findOrFail($claimId);

        $this->assertSame(LostFoundClaimStatus::Accepted, $claim->status);
        $this->assertSame($this->finder->getKey(), $claim->decided_by);

        // …and the claimant is notified with the handover point.
        Notification::assertSentTo(
            $this->owner,
            LostFoundClaimDecided::class,
            function (LostFoundClaimDecided $notice): bool {
                return $notice->status === LostFoundClaimStatus::Accepted
                    && $notice->handoverPoint === 'Room 412, after six in the evening';
            },
        );

        // And when the finder then marks the item returned…
        $this->postJson('/api/v1/lost-found/'.$find->getKey().'/resolve')
            ->assertStatus(200)
            ->assertJsonPath('data.status', LostFoundItemStatus::Resolved->value);

        // …the status becomes "resolved" with the time…
        $returned = $find->fresh();

        $this->assertNotNull($returned);
        $this->assertSame(LostFoundItemStatus::Resolved, $returned->status);
        $this->assertSame(
            CarbonImmutable::now()->toIso8601String(),
            $returned->resolved_at?->toIso8601String(),
        );

        // …and the record no longer appears in the public list of available
        // finds.
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/lost-found')->assertStatus(200)->assertJsonCount(0, 'data');
    }

    /**
     * §2.4.4, second scenario, word for word:
     *
     *   Given the same find with one claim outstanding
     *   When the finder declines the claim because the stated marks do not
     *        match
     *   Then the claim moves to "declined" and the find returns to the
     *        published list
     *    And the claimant is offered the option of referring the decision to
     *        the warden
     */
    public function test_the_finder_declines_a_claim(): void
    {
        Notification::fake();

        // Given the same find with one claim outstanding.
        $find = $this->findOf($this->finder, $this->building);

        Sanctum::actingAs($this->owner);

        $claimId = $this->postJson('/api/v1/lost-found/'.$find->getKey().'/claims', $this->claimBody())
            ->assertStatus(201)->json('data.id');

        $this->assertSame(LostFoundItemStatus::Claimed, $find->fresh()?->status);

        // When the finder declines the claim because the stated marks do not
        // match.
        Sanctum::actingAs($this->finder);

        $this->postJson('/api/v1/lost-found/claims/'.$claimId.'/decline', [
            'reason' => 'The marks you describe are not the ones on this umbrella.',
        ])->assertStatus(200);

        // Then the claim moves to "declined"…
        $claim = LostFoundClaim::query()->findOrFail($claimId);

        $this->assertSame(LostFoundClaimStatus::Declined, $claim->status);

        // …and the find returns to the published list.
        $this->assertSame(LostFoundItemStatus::Published, $find->fresh()?->status);

        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/lost-found')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $find->getKey());

        // And the claimant is offered the option of referring the decision to
        // the warden.
        $this->assertTrue($claim->mayBeReferred());

        Notification::assertSentTo(
            $this->owner,
            LostFoundClaimDecided::class,
            function (LostFoundClaimDecided $notice): bool {
                return $notice->status === LostFoundClaimStatus::Declined
                    && $notice->referralOffered === true;
            },
        );
    }

    /**
     * FR-26, first criterion: «a find is closed as returned **only** on a
     * claim the finder accepted or on a warden's decision on a referred
     * claim».
     *
     * The «only», asked directly: an entry whose claim nobody has answered
     * cannot be closed, and the refusal is a 409 rather than a 403 — the
     * caller is the right person and the same call after an acceptance would
     * succeed.
     */
    public function test_a_find_whose_claim_nobody_accepted_cannot_be_closed_as_returned(): void
    {
        $find = $this->findOf($this->finder, $this->building);
        $this->claimOn($find, $this->owner);
        $find->update(['status' => LostFoundItemStatus::Claimed]);

        Sanctum::actingAs($this->finder);

        $this->postJson('/api/v1/lost-found/'.$find->getKey().'/resolve')
            ->assertStatus(409)
            ->assertJsonPath('outstanding_claims', 1);

        $this->assertSame(LostFoundItemStatus::Claimed, $find->fresh()?->status);
    }

    /**
     * The same «only» from the other end: a find nobody has claimed at all is
     * not closed either, and the transition table is what refuses it.
     */
    public function test_a_find_nobody_has_claimed_cannot_be_closed_as_returned(): void
    {
        $find = $this->findOf($this->finder, $this->building);

        Sanctum::actingAs($this->finder);

        $this->postJson('/api/v1/lost-found/'.$find->getKey().'/resolve')->assertStatus(409);

        $this->assertSame(LostFoundItemStatus::Published, $find->fresh()?->status);
    }

    /**
     * §3.5.5's note: «Several claims may coexist. The entry returns to
     * Published if none of them is confirmed.»
     *
     * So the first refusal changes nothing about the entry and the second puts
     * it back — which is the part of FR-26's second criterion a single-claim
     * test cannot reach.
     */
    public function test_the_find_returns_to_the_published_list_once_no_claim_is_outstanding(): void
    {
        $find = $this->findOf($this->finder, $this->building);
        $third = $this->residentOf($this->building, 'third@example.test', '210');

        Sanctum::actingAs($this->owner);
        $first = $this->postJson('/api/v1/lost-found/'.$find->getKey().'/claims', $this->claimBody())
            ->assertStatus(201)->json('data.id');

        Sanctum::actingAs($third);
        $second = $this->postJson('/api/v1/lost-found/'.$find->getKey().'/claims', $this->claimBody([
            'message' => 'Mine has a supermarket token on the ring and one key wrapped in red tape.',
        ]))->assertStatus(201)->json('data.id');

        Sanctum::actingAs($this->finder);

        $this->postJson('/api/v1/lost-found/claims/'.$first.'/decline')->assertStatus(200);
        $this->assertSame(
            LostFoundItemStatus::Claimed,
            $find->fresh()?->status,
            'One claim is still outstanding and the entry went back into the feed.',
        );

        $this->postJson('/api/v1/lost-found/claims/'.$second.'/decline')->assertStatus(200);
        $this->assertSame(LostFoundItemStatus::Published, $find->fresh()?->status);
    }

    /**
     * §4.6.2: «a test asserts only the finder may accept or decline».
     *
     * §2.5.4 in one assertion: the module is peer-to-peer, the decision
     * belongs to the person holding the object, and nobody else reaches it —
     * not another resident, not the claimant, and not the warden, whose one
     * way in is a referral.
     */
    public function test_only_the_finder_may_accept_or_decline_a_claim(): void
    {
        $find = $this->findOf($this->finder, $this->building);
        $claim = $this->claimOn($find, $this->owner);
        $find->update(['status' => LostFoundItemStatus::Claimed]);

        $others = [
            'the claimant' => $this->owner,
            'another resident' => $this->residentOf($this->building, 'third@example.test', '210'),
            'the warden' => $this->consentingStaff(RoleCode::Warden, $this->building, 'warden@example.test'),
            'the administrator' => $this->staff(RoleCode::Administrator, null, 'admin@example.test'),
        ];

        foreach ($others as $who => $person) {
            Sanctum::actingAs($person);

            $this->postJson('/api/v1/lost-found/claims/'.$claim->getKey().'/accept', [
                'handover_point' => 'Anywhere at all',
            ])->assertStatus(403, sprintf('%s accepted a claim that is not theirs to answer.', $who));

            $this->postJson('/api/v1/lost-found/claims/'.$claim->getKey().'/decline')
                ->assertStatus(403, sprintf('%s declined a claim that is not theirs to answer.', $who));
        }

        $this->assertSame(LostFoundClaimStatus::New, $claim->fresh()?->status);
    }

    /**
     * §4.6.2: «a validation rule refuses a claim on one's own entry».
     *
     * A 422 and not a 403, because what is wrong is the object the request
     * names and not the account that named it — the same account may claim any
     * number of other finds, and a 403 would be written to the audit log as an
     * access denial, which this is not.
     */
    public function test_a_validation_rule_refuses_a_claim_on_ones_own_entry(): void
    {
        $find = $this->findOf($this->finder, $this->building);

        Sanctum::actingAs($this->finder);

        $this->postJson('/api/v1/lost-found/'.$find->getKey().'/claims', $this->claimBody())
            ->assertStatus(422)
            ->assertJsonValidationErrors('lost_found_item_id');

        $this->assertSame(0, LostFoundClaim::query()->count());
        $this->assertSame(LostFoundItemStatus::Published, $find->fresh()?->status);
    }

    /**
     * A claim is the sentence «that is mine», and there is nothing to say it
     * about a notice whose author has lost something and holds nothing
     * (§3.4.3, `kind` «lost or found»).
     */
    public function test_a_notice_of_a_loss_admits_no_claim(): void
    {
        $loss = $this->lossOf($this->owner, $this->building);

        Sanctum::actingAs($this->finder);

        $this->postJson('/api/v1/lost-found/'.$loss->getKey().'/claims', $this->claimBody())
            ->assertStatus(422)
            ->assertJsonValidationErrors('lost_found_item_id');
    }

    /**
     * FR-26, third criterion, at the point a claimant meets it: an entry that
     * has gone home cannot be claimed.
     */
    public function test_a_find_that_has_gone_home_cannot_be_claimed(): void
    {
        $closed = LostFoundItem::factory()
            ->forBuilding($this->building)->from($this->finder)->resolved()->create();

        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/lost-found/'.$closed->getKey().'/claims', $this->claimBody())
            ->assertStatus(403);
    }

    /**
     * §2.4.4: «the claimant is notified with the handover point». A place is
     * the whole content of the message, so an acceptance without one is
     * refused with a 422 naming the field rather than producing a notification
     * that tells the claimant nothing.
     */
    public function test_an_acceptance_without_a_handover_point_is_refused(): void
    {
        $find = $this->findOf($this->finder, $this->building);
        $claim = $this->claimOn($find, $this->owner);
        $find->update(['status' => LostFoundItemStatus::Claimed]);

        Sanctum::actingAs($this->finder);

        $this->postJson('/api/v1/lost-found/claims/'.$claim->getKey().'/accept')
            ->assertStatus(422)
            ->assertJsonValidationErrors('handover_point');

        $this->assertSame(LostFoundClaimStatus::New, $claim->fresh()?->status);
    }

    /**
     * One person, one outstanding claim per entry. Without the rule a claimant
     * refused once can file the same sentence again and again, and the person
     * holding the object is left answering it until they stop reading.
     *
     * Stated twice — a validation rule for the sentence, a partial unique
     * index for everything that is not this form — and asserted here at the
     * boundary, where a person pressing a button twice meets it.
     */
    public function test_a_second_outstanding_claim_by_the_same_person_is_refused(): void
    {
        $find = $this->findOf($this->finder, $this->building);

        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/lost-found/'.$find->getKey().'/claims', $this->claimBody())
            ->assertStatus(201);

        $this->postJson('/api/v1/lost-found/'.$find->getKey().'/claims', $this->claimBody())
            ->assertStatus(422)
            ->assertJsonValidationErrors('lost_found_item_id');

        $this->assertSame(1, LostFoundClaim::query()->count());
    }

    /**
     * The other side of the same rule: a claim that has been settled does not
     * block a later one. A person whose first claim was refused may claim
     * again if they find a better reason — the index is partial for exactly
     * this.
     */
    public function test_a_settled_claim_does_not_block_a_later_one(): void
    {
        $find = $this->findOf($this->finder, $this->building);

        Sanctum::actingAs($this->owner);
        $first = $this->postJson('/api/v1/lost-found/'.$find->getKey().'/claims', $this->claimBody())
            ->assertStatus(201)->json('data.id');

        Sanctum::actingAs($this->finder);
        $this->postJson('/api/v1/lost-found/claims/'.$first.'/decline')->assertStatus(200);

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/lost-found/'.$find->getKey().'/claims', $this->claimBody([
            'message' => 'There is also a supermarket token on the ring, which I forgot to say.',
        ]))->assertStatus(201);

        $this->assertSame(2, LostFoundClaim::query()->count());
    }

    /**
     * A claim that says «it's mine» gives the person holding the object
     * nothing to judge, which would make the refusal they then have to write
     * arbitrary — and the whole peer-to-peer arrangement of §2.5.4 rests on
     * those refusals being answerable.
     */
    public function test_a_claim_without_identifying_features_is_refused(): void
    {
        $find = $this->findOf($this->finder, $this->building);

        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/lost-found/'.$find->getKey().'/claims', ['message' => 'mine'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }

    /**
     * §2.5.4's other path: the object is in the administration's keeping, so
     * the claim is answered by the staff of that dormitory rather than by the
     * officer who happened to take it in. The finder's path is untouched by
     * this — a resident's find is still a resident's to settle.
     */
    public function test_a_claim_on_a_deposited_item_is_answered_by_the_staff_of_that_dormitory(): void
    {
        Notification::fake();

        $officer = $this->consentingStaff(RoleCode::SecurityOfficer, $this->building, 'post@example.test');
        $warden = $this->consentingStaff(RoleCode::Warden, $this->building, 'warden@example.test');

        $deposited = $this->depositedFind($officer, $this->building);

        Sanctum::actingAs($this->owner);

        $claimId = $this->postJson('/api/v1/lost-found/'.$deposited->getKey().'/claims', $this->claimBody())
            ->assertStatus(201)->json('data.id');

        // The circle and not the one account: the officer who took it in on
        // Friday is not on shift on Monday.
        Notification::assertSentTo($officer, LostFoundClaimFiled::class);
        Notification::assertSentTo($warden, LostFoundClaimFiled::class);

        // And the warden, who holds the object, answers it here — which is the
        // deposited path and not the dispute of FR-26's first criterion.
        Sanctum::actingAs($warden);

        $this->postJson('/api/v1/lost-found/claims/'.$claimId.'/accept', [
            'handover_point' => 'The security post, at any hour',
        ])->assertStatus(200);

        $this->assertSame(
            LostFoundClaimStatus::Accepted,
            LostFoundClaim::query()->findOrFail($claimId)->status,
        );
    }

    /**
     * An accepted claim is an ending: a claimant told where to collect an
     * object is not told afterwards that the message was a mistake.
     */
    public function test_an_accepted_claim_cannot_be_answered_a_second_time(): void
    {
        $find = $this->findOf($this->finder, $this->building);
        $claim = LostFoundClaim::factory()->on($find)->by($this->owner)
            ->accepted($this->finder)->create();
        $find->update(['status' => LostFoundItemStatus::Claimed]);

        Sanctum::actingAs($this->finder);

        $this->postJson('/api/v1/lost-found/claims/'.$claim->getKey().'/decline')
            ->assertStatus(409)
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('attempted_status', 'declined');
    }

    /**
     * FR-34: the movement of a claim is on the person's own screen, so the
     * person who pressed the button is not told what they have just pressed.
     */
    public function test_the_person_who_answered_a_claim_is_not_told_about_their_own_answer(): void
    {
        Notification::fake();

        $find = $this->findOf($this->finder, $this->building);
        $claim = $this->claimOn($find, $this->owner);
        $find->update(['status' => LostFoundItemStatus::Claimed]);

        Sanctum::actingAs($this->finder);

        $this->postJson('/api/v1/lost-found/claims/'.$claim->getKey().'/accept', [
            'handover_point' => 'Room 412',
        ])->assertStatus(200);

        Notification::assertSentTo($this->owner, LostFoundClaimDecided::class);
        Notification::assertNotSentTo($this->finder, LostFoundClaimDecided::class);
    }
}

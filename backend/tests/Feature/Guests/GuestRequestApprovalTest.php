<?php

declare(strict_types=1);

namespace Tests\Feature\Guests;

use App\Enums\AuditAction;
use App\Enums\GuestRequestStatus;
use App\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\GuestRequest;
use App\Models\User;
use App\Notifications\GuestRequestDecided;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsAGuestScenario;
use Tests\TestCase;

/**
 * FR-17, «Approval of a guest request», and FR-23's second criterion, which
 * bites at the same moment.
 *
 * The verification of FR-17 singles out one check by name — «call
 * `POST /api/v1/guest-requests/{id}/approve` with a student's token and expect
 * 403, and with a duty officer of another building's token and expect 403».
 * The duty officer is gone since revision 4 of the role model, so the second
 * token is a manager's: the nearest holder of the same capability one block
 * over. It is the check §4.7.2 makes of every module: the horizontal boundary
 * tested through the API rather than through a screen that declines to draw a
 * button.
 */
final class GuestRequestApprovalTest extends TestCase
{
    use BuildsAGuestScenario, RefreshDatabase;

    private Building $building;

    private Building $otherBuilding;

    private User $resident;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 09:30:00'));

        $this->building = $this->dormitory('Block A');
        $this->otherBuilding = $this->dormitory('Block B');

        $this->resident = $this->residentOf($this->building, 'resident@example.test');
        $this->officer = $this->staff(RoleCode::Manager, $this->building, 'decider@example.test');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * First criterion: «the decision is stored with the deciding user's
     * identifier and the time». Third criterion: «the applicant is notified».
     * And the transition of §3.5.4, pending review → approved.
     */
    public function test_an_approval_stores_the_officer_the_time_and_the_code_and_notifies_the_applicant(): void
    {
        Notification::fake();

        $request = $this->pendingRequest();

        Sanctum::actingAs($this->officer);

        $response = $this->postJson("/api/v1/guest-requests/{$request->id}/approve", [
            'comment' => 'Approved; the guest is expected at the post.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', GuestRequestStatus::Approved->value)
            ->assertJsonPath('data.decided_by', $this->officer->getKey());

        $this->assertNotNull($response->json('data.decided_at'));

        $stored = $request->fresh();

        $this->assertSame(GuestRequestStatus::Approved, $stored->status);
        $this->assertSame($this->officer->getKey(), $stored->decided_by);
        $this->assertNotNull($stored->decided_at);

        // §3.5.1: the code is issued **only** at approval, so that before the
        // decision there is nothing to present at the post.
        $this->assertNotNull($stored->access_code);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $stored->access_code);

        Notification::assertSentTo(
            $this->resident,
            GuestRequestDecided::class,
            fn (GuestRequestDecided $message): bool => $message->approved
                && $message->requestId === $request->getKey(),
        );
    }

    /**
     * The queue names people rather than identifiers: who approved, who let
     * the guest out, and which room the inviting resident holds.
     */
    public function test_the_queue_names_the_officer_who_decided_and_the_room_of_the_host(): void
    {
        $request = $this->pendingRequest();

        Sanctum::actingAs($this->officer);

        $this->postJson("/api/v1/guest-requests/{$request->id}/approve")->assertOk();

        $this->getJson('/api/v1/guest-requests?building_id='.$this->building->getKey())
            ->assertOk()
            ->assertJsonPath('data.0.decided_by', $this->officer->getKey())
            ->assertJsonPath('data.0.decided_by_name', $this->officer->full_name)
            ->assertJsonPath('data.0.inviting_resident.id', $this->resident->getKey())
            ->assertJsonPath('data.0.inviting_resident.full_name', $this->resident->full_name)
            ->assertJsonPath('data.0.inviting_resident.room', '305');
    }

    /**
     * The page is the caller's, within a ceiling the caller cannot raise.
     */
    public function test_the_queue_is_paginated_and_the_page_size_is_capped(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->pendingRequest();
        }

        Sanctum::actingAs($this->officer);

        $url = '/api/v1/guest-requests?building_id='.$this->building->getKey();

        $this->getJson($url.'&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3);

        $this->getJson($url.'&per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2);

        $this->getJson($url.'&per_page=5000')->assertStatus(422);
    }

    public function test_no_access_code_exists_before_the_decision(): void
    {
        $this->assertNull($this->pendingRequest()->access_code);
    }

    /**
     * Second criterion: «rejection without a reason is impossible».
     */
    public function test_a_rejection_without_a_reason_is_refused(): void
    {
        $request = $this->pendingRequest();

        Sanctum::actingAs($this->officer);

        $this->postJson("/api/v1/guest-requests/{$request->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/guest-requests/{$request->id}/reject", ['reason' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(GuestRequestStatus::PendingReview, $request->fresh()->status);
    }

    public function test_a_rejection_with_a_reason_is_stored_and_the_applicant_is_told_the_reason(): void
    {
        Notification::fake();

        $request = $this->pendingRequest();

        Sanctum::actingAs($this->officer);

        $this->postJson("/api/v1/guest-requests/{$request->id}/reject", [
            'reason' => 'The daily ceiling for this room has already been reached.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', GuestRequestStatus::Rejected->value)
            ->assertJsonPath('data.decision_comment', 'The daily ceiling for this room has already been reached.');

        $this->assertNull($request->fresh()->access_code);

        Notification::assertSentTo(
            $this->resident,
            GuestRequestDecided::class,
            fn (GuestRequestDecided $message): bool => ! $message->approved
                && $message->comment === 'The daily ceiling for this room has already been reached.',
        );
    }

    /**
     * The check §4.7.2 singles out, half one.
     */
    public function test_a_student_may_not_approve_a_guest_request(): void
    {
        $request = $this->pendingRequest();

        Sanctum::actingAs($this->resident);

        $this->postJson("/api/v1/guest-requests/{$request->id}/approve")->assertStatus(403);

        $this->assertSame(GuestRequestStatus::PendingReview, $request->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::AccessDenied->value]);
    }

    /**
     * The check §4.7.2 singles out, half two: the token of somebody who holds
     * the decision one block over.
     */
    public function test_a_decider_of_another_building_may_not_approve(): void
    {
        $request = $this->pendingRequest();

        Sanctum::actingAs($this->staff(RoleCode::Manager, $this->otherBuilding, 'decider-b@example.test'));

        $this->postJson("/api/v1/guest-requests/{$request->id}/approve")->assertStatus(403);

        $this->assertSame(GuestRequestStatus::PendingReview, $request->fresh()->status);
    }

    /**
     * Revision 3 of the guest module (16.09.2026): the decision belongs to the
     * warden **and** the manager of the building beside the duty officer of
     * the day, and the decision row names whoever took it. Revision 4 of the
     * role model has since removed the third of them, leaving these two.
     *
     * The agreement of 13.09.2026 had withheld it from the register roles.
     * That reading did not survive contact with a dormitory — the person who
     * keeps the register is the person at the desk — and the test that asserted
     * it is this one, turned round rather than deleted, so that the change of
     * rule stays visible in the suite.
     */
    public function test_the_warden_and_the_manager_of_the_building_decide_a_guest_request(): void
    {
        foreach ([
            [RoleCode::Warden, 'warden@example.test'],
            [RoleCode::Manager, 'manager@example.test'],
        ] as [$code, $email]) {
            $request = $this->pendingRequest();
            $decider = $this->staff($code, $this->building, $email);

            Sanctum::actingAs($decider);

            $this->postJson("/api/v1/guest-requests/{$request->id}/approve")
                ->assertOk()
                ->assertJsonPath('data.status', GuestRequestStatus::Approved->value)
                ->assertJsonPath('data.decided_by', $decider->getKey());

            $stored = $request->fresh();

            $this->assertSame(GuestRequestStatus::Approved, $stored->status);
            $this->assertSame($decider->getKey(), $stored->decided_by);
            $this->assertNotNull($stored->access_code);
        }
    }

    /**
     * The horizontal boundary of FR-07, asked of the roles revision 3 has just
     * widened. Widening the set of roles that decide must not widen the set of
     * buildings any of them decides in — the escalation this project has caught
     * before.
     */
    public function test_the_warden_and_the_manager_of_another_building_may_not_decide(): void
    {
        foreach ([
            [RoleCode::Warden, 'warden-b@example.test'],
            [RoleCode::Manager, 'manager-b@example.test'],
        ] as [$code, $email]) {
            $request = $this->pendingRequest();

            Sanctum::actingAs($this->staff($code, $this->otherBuilding, $email));

            $this->postJson("/api/v1/guest-requests/{$request->id}/approve")
                ->assertStatus(403);

            $this->postJson("/api/v1/guest-requests/{$request->id}/reject", ['reason' => 'No.'])
                ->assertStatus(403);

            $this->assertSame(GuestRequestStatus::PendingReview, $request->fresh()->status);

            // And the queue of the building they have no grant in stays shut.
            $this->getJson('/api/v1/guest-requests?building_id='.$this->building->getKey())
                ->assertStatus(403);
        }

        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::AccessDenied->value]);
    }

    /**
     * The administrator is the one role left that reads the queue of a
     * dormitory and decides in none: §3.9.6 puts him over the register, not at
     * the desk of a building.
     */
    public function test_the_administrator_reads_the_queue_and_does_not_decide(): void
    {
        $request = $this->pendingRequest();

        Sanctum::actingAs($this->staff(RoleCode::Administrator, null, 'admin@example.test'));

        $this->postJson("/api/v1/guest-requests/{$request->id}/approve")
            ->assertStatus(403);

        $this->assertSame(GuestRequestStatus::PendingReview, $request->fresh()->status);

        $this->getJson('/api/v1/guest-requests?building_id='.$this->building->getKey())
            ->assertOk();
    }

    /**
     * §3.5.4: an illegal transition is 409. Two officers with the same queue
     * open, and the second one presses a button the first has already spent.
     */
    public function test_a_request_that_has_already_been_decided_cannot_be_decided_again(): void
    {
        $request = $this->pendingRequest();

        Sanctum::actingAs($this->officer);

        $this->postJson("/api/v1/guest-requests/{$request->id}/reject", ['reason' => 'Not today.'])
            ->assertOk();

        $this->postJson("/api/v1/guest-requests/{$request->id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('status', GuestRequestStatus::Rejected->value)
            ->assertJsonPath('attempted_status', GuestRequestStatus::Approved->value);
    }

    /**
     * §3.3.4: «assert the per-resident and per-building daily quota». 422, and
     * the body says which ceiling was hit.
     */
    public function test_a_quota_breach_is_refused_and_names_the_ceiling_it_broke(): void
    {
        config(['dormitory.guests.daily_quota_per_resident' => 1]);

        $first = $this->pendingRequest();
        $second = $this->pendingRequest();

        Sanctum::actingAs($this->officer);

        $this->postJson("/api/v1/guest-requests/{$first->id}/approve")->assertOk();

        $this->postJson("/api/v1/guest-requests/{$second->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('quota_scope', 'resident')
            ->assertJsonPath('quota_limit', 1);

        $this->assertSame(GuestRequestStatus::PendingReview, $second->fresh()->status);
    }

    /**
     * The refusal has to survive the rollback of the transaction that refused
     * it. §3.9.6 counts a refusal among the events the log must hold, and a
     * record written inside the transaction would be carried off with it.
     */
    public function test_a_refused_decision_is_in_the_audit_log_even_though_nothing_was_written(): void
    {
        config(['dormitory.guests.daily_quota_per_resident' => 1]);

        $first = $this->pendingRequest();
        $second = $this->pendingRequest();

        Sanctum::actingAs($this->officer);

        $this->postJson("/api/v1/guest-requests/{$first->id}/approve")->assertOk();
        $this->postJson("/api/v1/guest-requests/{$second->id}/approve")->assertStatus(422);

        $refusal = AuditLog::query()
            ->where('action', AuditAction::GuestRequestDecisionRefused->value)
            ->sole();

        $this->assertSame($second->getKey(), $refusal->subject_id);
        $this->assertSame($this->officer->getKey(), $refusal->user_id);
        $this->assertSame('resident', $refusal->payload['quota_scope']);
    }

    /**
     * FR-17, fourth criterion: «a request not processed by the start of the
     * visit is treated as rejected».
     */
    public function test_a_request_nobody_decided_by_the_start_of_the_visit_is_treated_as_rejected(): void
    {
        Notification::fake();

        $request = $this->pendingRequest();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 14:01:00'));

        $this->artisan('guests:expire-stale-requests')->assertSuccessful();

        $closed = $request->fresh();

        $this->assertSame(GuestRequestStatus::Rejected, $closed->status);
        $this->assertNotNull($closed->decided_at);

        // Nobody decided it, so nobody is named as having decided it. The
        // CHECK on the table admits a moment without an author for exactly
        // this row.
        $this->assertNull($closed->decided_by);
        $this->assertStringContainsString('No decision was taken', (string) $closed->decision_comment);

        Notification::assertSentTo($this->resident, GuestRequestDecided::class);
    }

    public function test_a_request_whose_visit_has_not_begun_is_left_alone_by_the_sweep(): void
    {
        $request = $this->pendingRequest();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 13:59:00'));

        $this->artisan('guests:expire-stale-requests')->assertSuccessful();

        $this->assertSame(GuestRequestStatus::PendingReview, $request->fresh()->status);
    }

    /**
     * §3.5.4, `Approved → Expired`: the interval ran out and the guest never
     * came. No notification — nothing happened.
     */
    public function test_an_approved_request_whose_interval_passed_with_no_entry_expires(): void
    {
        Notification::fake();

        $request = $this->pendingRequest();

        Sanctum::actingAs($this->officer);
        $this->postJson("/api/v1/guest-requests/{$request->id}/approve")->assertOk();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 18:01:00'));

        $this->artisan('guests:expire-stale-requests')->assertSuccessful();

        $this->assertSame(GuestRequestStatus::Expired, $request->fresh()->status);

        Notification::assertSentTimes(GuestRequestDecided::class, 1);
    }

    /**
     * The author withdraws their own; a member of staff does not, because a
     * cancellation would close the request with a record saying the resident
     * changed their mind.
     */
    public function test_the_author_cancels_their_own_request_and_nobody_else_does(): void
    {
        $request = $this->pendingRequest();

        Sanctum::actingAs($this->officer);
        $this->postJson("/api/v1/guest-requests/{$request->id}/cancellation")->assertStatus(403);

        Sanctum::actingAs($this->resident);
        $this->postJson("/api/v1/guest-requests/{$request->id}/cancellation")
            ->assertOk()
            ->assertJsonPath('data.status', GuestRequestStatus::Cancelled->value);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function pendingRequest(array $overrides = []): GuestRequest
    {
        return GuestRequest::factory()
            ->forBuilding($this->building)
            ->from($this->resident)
            ->create($overrides + [
                'visit_date' => '2026-09-14',
                'planned_from' => '14:00:00',
                'planned_to' => '18:00:00',
            ]);
    }
}

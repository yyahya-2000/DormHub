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
 * 403, and with a duty officer of another building's token and expect 403» —
 * and it is the check §4.7.2 makes of every module: the horizontal boundary
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
        $this->officer = $this->staff(RoleCode::DutyOfficer, $this->building, 'duty@example.test');
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
     * The check §4.7.2 singles out, half two.
     */
    public function test_the_duty_officer_of_another_building_may_not_approve(): void
    {
        $request = $this->pendingRequest();

        Sanctum::actingAs($this->staff(RoleCode::DutyOfficer, $this->otherBuilding, 'duty-b@example.test'));

        $this->postJson("/api/v1/guest-requests/{$request->id}/approve")->assertStatus(403);

        $this->assertSame(GuestRequestStatus::PendingReview, $request->fresh()->status);
    }

    /**
     * «Заявку согласует дежурный, не комендант» — the agreement of 13.09.2026,
     * stated once in `RoleCode::permissions()` and asserted here on all three
     * of the roles that read the queue and do not decide it.
     */
    public function test_the_warden_the_manager_and_the_administrator_do_not_decide_a_guest_request(): void
    {
        foreach ([
            [RoleCode::Warden, $this->building, 'warden@example.test'],
            [RoleCode::Manager, $this->building, 'manager@example.test'],
            [RoleCode::Administrator, null, 'admin@example.test'],
        ] as [$code, $scope, $email]) {
            $request = $this->pendingRequest();

            Sanctum::actingAs($this->staff($code, $scope, $email));

            $this->postJson("/api/v1/guest-requests/{$request->id}/approve")
                ->assertStatus(403);

            // They read the queue all the same: the request names a room, and
            // the rooms are the warden's and the manager's work.
            if ($code !== RoleCode::Administrator) {
                $this->getJson('/api/v1/guest-requests?building_id='.$this->building->getKey())
                    ->assertOk();
            }
        }
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
     * FR-23, second criterion: «a request with a foreign document cannot be
     * approved with an interval extending beyond one day without a mark by the
     * responsible officer».
     *
     * An interval that runs past midnight is only possible where the
     * dormitory's own regime admits one, so the building is configured for it
     * — which is NFR-09 again, and the reason this criterion is reachable at
     * all rather than forbidden earlier by the window check of FR-16.
     */
    public function test_an_overnight_interval_on_a_foreign_document_needs_the_responsible_officers_mark(): void
    {
        $this->building->update([
            'visiting_from' => '08:00:00',
            'visiting_to' => '08:00:00',
            'curfew_at' => '08:00:00',
        ]);

        $request = $this->pendingRequest([
            'planned_from' => '20:00:00',
            'planned_to' => '07:00:00',
        ], foreign: true);

        $this->assertTrue($request->spansMoreThanOneDay());

        Sanctum::actingAs($this->officer);

        $this->postJson("/api/v1/guest-requests/{$request->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('required_field', 'responsible_officer_mark');

        $this->assertSame(GuestRequestStatus::PendingReview, $request->fresh()->status);

        $this->postJson("/api/v1/guest-requests/{$request->id}/approve", [
            'responsible_officer_mark' => 'Migration officer S. Nechaeva informed, 14.09.2026.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', GuestRequestStatus::Approved->value)
            ->assertJsonPath(
                'data.responsible_officer_mark',
                'Migration officer S. Nechaeva informed, 14.09.2026.',
            );
    }

    /**
     * The other half of §2.7.4, and the error it opens by warning against: a
     * foreign document on a visit that ends the same evening creates no place
     * of stay, so no mark is demanded.
     */
    public function test_a_foreign_document_inside_one_day_is_approved_without_a_mark(): void
    {
        $request = $this->pendingRequest([], foreign: true);

        Sanctum::actingAs($this->officer);

        $this->postJson("/api/v1/guest-requests/{$request->id}/approve")->assertOk();
    }

    /**
     * And the mirror: an overnight interval on an internal passport raises no
     * migration question at all.
     */
    public function test_an_overnight_interval_on_a_russian_passport_needs_no_mark(): void
    {
        $this->building->update([
            'visiting_to' => '08:00:00',
            'curfew_at' => '08:00:00',
        ]);

        $request = $this->pendingRequest([
            'planned_from' => '20:00:00',
            'planned_to' => '07:00:00',
        ]);

        Sanctum::actingAs($this->officer);

        $this->postJson("/api/v1/guest-requests/{$request->id}/approve")->assertOk();
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
    private function pendingRequest(array $overrides = [], bool $foreign = false): GuestRequest
    {
        $factory = GuestRequest::factory()
            ->forBuilding($this->building)
            ->from($this->resident);

        if ($foreign) {
            $factory = $factory->withForeignDocument();
        }

        return $factory->create($overrides + [
            'visit_date' => '2026-09-14',
            'planned_from' => '14:00:00',
            'planned_to' => '18:00:00',
        ]);
    }
}

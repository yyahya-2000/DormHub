<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Enums\AuditAction;
use App\Enums\MaintenanceRequestStatus;
use App\Enums\MaintenanceUrgency;
use App\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceWorkLog;
use App\Models\User;
use App\Notifications\MaintenanceRequestStatusChanged;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsAMaintenanceScenario;
use Tests\TestCase;

/**
 * FR-37, «Triage and assignment», one test per acceptance criterion, and the
 * second and third Gherkin scenarios of §2.4.3 carried over verbatim.
 *
 * Both of FR-37's impossibilities are asserted as 422 and not as 409, and the
 * distinction is the requirement's own: «an impossibility belongs in
 * validation» (§4.6.3). A body without a reason, or without a planned date, is
 * a malformed body — the state of the request has nothing to do with it.
 */
final class MaintenanceTriageTest extends TestCase
{
    use BuildsAMaintenanceScenario, RefreshDatabase;

    private Building $building;

    private User $resident;

    private User $warden;

    private MaintenanceRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 09:30:00'));

        $this->building = $this->dormitory('Block A');
        $this->resident = $this->residentInRoom412($this->building);
        $this->warden = $this->staff(RoleCode::Warden, $this->building, 'warden@example.test');

        $this->request = MaintenanceRequest::factory()
            ->forBuilding($this->building)
            ->from($this->resident)
            ->inRoom($this->roomOf($this->resident))
            ->create();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * §2.4.3, second scenario, word for word:
     *
     *   Given a request in status "submitted"
     *   When the warden sets status "accepted" and a planned completion date
     *   Then the resident receives a notification containing the planned date
     *    And the request appears in the building queue with its age counted
     *        from submission
     */
    public function test_the_warden_accepts_with_a_planned_date_the_resident_is_told_the_date_and_the_request_appears_in_the_queue_with_its_age(): void
    {
        Notification::fake();

        // Filed three days ago, so that «age counted from submission» is a
        // number a test can tell apart from «age counted from acceptance».
        $this->request->forceFill(['created_at' => CarbonImmutable::now()->subDays(3)])->save();

        Sanctum::actingAs($this->warden);

        $this->postJson("/api/v1/maintenance-requests/{$this->request->id}/accept", [
            'target_date' => '2026-09-18',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', MaintenanceRequestStatus::Accepted->value)
            ->assertJsonPath('data.target_date', '2026-09-18');

        Notification::assertSentTo(
            $this->resident,
            MaintenanceRequestStatusChanged::class,
            function (MaintenanceRequestStatusChanged $message): bool {
                return $message->toStatus === MaintenanceRequestStatus::Accepted->value
                    && str_contains((string) $message->comment, '2026-09-18');
            },
        );

        $this->getJson("/api/v1/buildings/{$this->building->id}/maintenance-queue")
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->request->id)
            ->assertJsonPath('data.0.age_days', 3)
            ->assertJsonPath('data.0.status', MaintenanceRequestStatus::Accepted->value)
            ->assertJsonPath('data.0.target_date', '2026-09-18');
    }

    /**
     * §2.4.3, third scenario, word for word:
     *
     *   Given a request in status "submitted"
     *   When the warden sets status "rejected" with an empty reason field
     *   Then the transition is refused and the reason field is marked mandatory
     */
    public function test_the_warden_rejecting_with_an_empty_reason_is_refused_and_the_reason_field_is_marked_mandatory(): void
    {
        Sanctum::actingAs($this->warden);

        $this->postJson("/api/v1/maintenance-requests/{$this->request->id}/reject", ['reason' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        // The refusal did not happen: the request is where it was.
        $this->assertSame(
            MaintenanceRequestStatus::Submitted,
            $this->request->fresh()?->status,
        );

        // And no work-log row was written, because nothing moved.
        $this->assertSame(0, MaintenanceWorkLog::query()
            ->where('maintenance_request_id', $this->request->id)
            ->whereNotNull('from_status')
            ->count());
    }

    /**
     * The same rule with no field at all rather than an empty one — a client
     * that omits the key is refused exactly as one that sends a blank.
     */
    public function test_a_rejection_with_no_reason_field_at_all_is_refused(): void
    {
        Sanctum::actingAs($this->warden);

        $this->postJson("/api/v1/maintenance-requests/{$this->request->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    /**
     * FR-37, second criterion: «acceptance without a planned completion date
     * is impossible». 422, and for the same reason.
     */
    public function test_an_acceptance_without_a_planned_completion_date_is_refused(): void
    {
        Sanctum::actingAs($this->warden);

        $this->postJson("/api/v1/maintenance-requests/{$this->request->id}/accept", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target_date');

        $this->assertSame(MaintenanceRequestStatus::Submitted, $this->request->fresh()?->status);
    }

    /**
     * A planned date already in the past is not a plan. The resident is told a
     * date the moment the acceptance succeeds, and a date behind them tells
     * them nothing.
     */
    public function test_a_planned_completion_date_in_the_past_is_refused(): void
    {
        Sanctum::actingAs($this->warden);

        $this->postJson("/api/v1/maintenance-requests/{$this->request->id}/accept", [
            'target_date' => '2026-09-01',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target_date');
    }

    /**
     * FR-37, third criterion: «every transition is stored with the acting user
     * and the time», and §4.6.3's «every transition writes a
     * maintenance_work_logs row in the same transaction».
     */
    public function test_every_transition_is_stored_with_the_acting_user_and_the_time(): void
    {
        Sanctum::actingAs($this->warden);

        $this->postJson("/api/v1/maintenance-requests/{$this->request->id}/accept", [
            'target_date' => '2026-09-18',
            'comment' => 'The plumber comes on Friday.',
        ])->assertOk();

        $this->postJson("/api/v1/maintenance-requests/{$this->request->id}/start")->assertOk();

        // The request came from the factory rather than from the route, so it
        // carries no submission row — which is what makes this assertion about
        // the transitions and nothing else. The submission's own row is
        // asserted in `MaintenanceSubmissionTest`.
        $this->assertSame([
            ['from' => 'submitted', 'to' => 'accepted'],
            ['from' => 'accepted', 'to' => 'in_progress'],
        ], array_map(
            static fn (array $row): array => ['from' => $row['from'], 'to' => $row['to']],
            $this->workLogOf($this->request->fresh()),
        ));

        $accepted = MaintenanceWorkLog::query()
            ->where('maintenance_request_id', $this->request->id)
            ->where('to_status', MaintenanceRequestStatus::Accepted->value)
            ->sole();

        $this->assertSame($this->warden->getKey(), $accepted->actor_id);
        $this->assertSame('The plumber comes on Friday.', $accepted->comment);
        $this->assertSame(
            CarbonImmutable::now()->toIso8601String(),
            $accepted->created_at?->toIso8601String(),
        );
    }

    /**
     * The reason a refusal carries lives in the work log and nowhere else
     * (§3.4.1, decision 6). A copy on the request itself would be the field
     * that quietly disagrees with the history.
     */
    public function test_the_reason_for_a_refusal_is_kept_in_the_work_log(): void
    {
        Sanctum::actingAs($this->warden);

        $this->postJson("/api/v1/maintenance-requests/{$this->request->id}/reject", [
            'reason' => 'The washing machine is the resident’s own and not the dormitory’s.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', MaintenanceRequestStatus::Rejected->value);

        $rejection = MaintenanceWorkLog::query()
            ->where('maintenance_request_id', $this->request->id)
            ->where('to_status', MaintenanceRequestStatus::Rejected->value)
            ->sole();

        $this->assertSame(
            'The washing machine is the resident’s own and not the dormitory’s.',
            $rejection->comment,
        );

        $this->assertNotNull(AuditLog::query()
            ->where('action', AuditAction::MaintenanceRequestRejected->value)
            ->where('subject_id', $this->request->id)
            ->first());
    }

    /**
     * FR-37 names a responsible party and a priority beside the date. Both are
     * optional, and both are recorded when given.
     */
    public function test_the_acceptance_may_name_a_responsible_party_and_change_the_priority(): void
    {
        $manager = $this->staff(RoleCode::Manager, $this->building, 'manager@example.test');

        Sanctum::actingAs($this->warden);

        $this->postJson("/api/v1/maintenance-requests/{$this->request->id}/accept", [
            'target_date' => '2026-09-18',
            'assigned_to' => $manager->getKey(),
            'urgency' => MaintenanceUrgency::Emergency->value,
        ])
            ->assertOk()
            ->assertJsonPath('data.assigned_to', $manager->getKey())
            ->assertJsonPath('data.urgency', MaintenanceUrgency::Emergency->value);

        $stored = $this->request->fresh();

        $this->assertNotNull($stored?->assigned_at);
    }

    /**
     * The acceptance finding of 15.09.2026: `assigned_to` took any identifier
     * in the table.
     *
     * `exists:users,id` was the whole of the check, and identifiers are
     * sequential — so «any account in the system» is a range starting at one,
     * and the card the acceptance answers with carries the assignee's full
     * name. A warden counting upwards read the staff of every other dormitory
     * and could make a stranger responsible for a repair he would never hear
     * about.
     *
     * 422 and not 403: the warden may triage this request perfectly well, and
     * what the body got wrong is the account it named.
     */
    public function test_the_responsible_party_must_belong_to_the_dormitory_of_the_request(): void
    {
        $elsewhere = $this->dormitory('Block B');
        $strangers = [
            'the manager of another dormitory' => $this->staff(RoleCode::Manager, $elsewhere, 'manager-b@example.test'),
            'a resident of this one' => $this->resident,
            'a resident of another one' => $this->residentOf($elsewhere, 'resident-b@example.test', '101'),
        ];

        Sanctum::actingAs($this->warden);

        foreach ($strangers as $who => $stranger) {
            $this->postJson("/api/v1/maintenance-requests/{$this->request->id}/accept", [
                'target_date' => '2026-09-18',
                'assigned_to' => $stranger->getKey(),
            ])
                ->assertStatus(422, sprintf('%s was accepted as the responsible party.', $who))
                ->assertJsonValidationErrors('assigned_to');
        }

        // Nothing was written: the request is still waiting to be triaged.
        $this->assertSame(MaintenanceRequestStatus::Submitted, $this->request->fresh()?->status);
        $this->assertNull($this->request->fresh()?->assigned_to);
    }

    /**
     * The other side of the same rule. Every member of staff of this dormitory
     * is nameable — the warden himself included, which is FR-37's «a dormitory
     * whose warden does the work himself has nobody else to name».
     */
    public function test_any_member_of_staff_of_this_dormitory_may_be_made_responsible(): void
    {
        $officer = $this->staff(RoleCode::SecurityOfficer, $this->building, 'post@example.test');

        Sanctum::actingAs($this->warden);

        foreach ([$this->warden, $officer] as $assignee) {
            $request = MaintenanceRequest::factory()
                ->forBuilding($this->building)
                ->from($this->resident)
                ->inRoom($this->roomOf($this->resident))
                ->create();

            $this->postJson("/api/v1/maintenance-requests/{$request->id}/accept", [
                'target_date' => '2026-09-18',
                'assigned_to' => $assignee->getKey(),
            ])->assertOk()->assertJsonPath('data.assigned_to', $assignee->getKey());
        }
    }

    /**
     * The manager of the same building triages as the warden does: §1.1.4's
     * revision 2 puts the maintenance queue with the register work, and the
     * manager relieves the warden rather than replacing him.
     */
    public function test_the_manager_of_the_building_triages_as_the_warden_does(): void
    {
        $manager = $this->staff(RoleCode::Manager, $this->building, 'manager@example.test');

        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/maintenance-requests/{$this->request->id}/accept", [
            'target_date' => '2026-09-18',
        ])->assertOk();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Enums\AuditAction;
use App\Enums\MaintenanceRequestStatus;
use App\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\MaintenanceRequest;
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
 * FR-38, «Status lifecycle», over HTTP.
 *
 * The exhaustive check of the transition table is a unit test
 * (`MaintenanceRequestStateMachineTest`), because the interesting property is
 * that thirty pairs are refused and thirty round trips would prove it slowly.
 * What is asserted here is the three things the unit test cannot see: that an
 * illegal transition reaches the client as 409 through the domain-exception
 * handler, that a role outside the graph's actor is 403, and that each change
 * enqueues a notification to the submitter.
 */
final class MaintenanceLifecycleTest extends TestCase
{
    use BuildsAMaintenanceScenario, RefreshDatabase;

    private Building $building;

    private User $resident;

    private User $warden;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 09:30:00'));

        $this->building = $this->dormitory('Block A');
        $this->resident = $this->residentInRoom412($this->building);
        $this->warden = $this->staff(RoleCode::Warden, $this->building, 'warden@example.test');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * FR-38, first criterion, walked end to end over the routes: submitted →
     * accepted → in progress → completed → closed.
     */
    public function test_the_request_walks_the_whole_graph_from_submitted_to_closed(): void
    {
        $request = $this->requestInStatus();

        Sanctum::actingAs($this->warden);

        $this->postJson("/api/v1/maintenance-requests/{$request->id}/accept", [
            'target_date' => '2026-09-18',
        ])->assertJsonPath('data.status', MaintenanceRequestStatus::Accepted->value);

        $this->postJson("/api/v1/maintenance-requests/{$request->id}/start")
            ->assertJsonPath('data.status', MaintenanceRequestStatus::InProgress->value);

        $this->postJson("/api/v1/maintenance-requests/{$request->id}/completion")
            ->assertJsonPath('data.status', MaintenanceRequestStatus::Completed->value);

        // The closing is the reporter's, never the warden's (§3.5.2).
        Sanctum::actingAs($this->resident);

        $this->postJson("/api/v1/maintenance-requests/{$request->id}/confirmation")
            ->assertJsonPath('data.status', MaintenanceRequestStatus::Closed->value)
            ->assertJsonPath('data.auto_closed', false);

        $this->assertNotNull($request->fresh()?->confirmed_at);
    }

    /**
     * FR-38, second criterion, at the protocol: «an attempt at any other
     * transition is rejected» — 409 through the handler that maps the domain
     * exception, with both ends of the attempted move in the body.
     */
    public function test_an_illegal_transition_is_refused_with_409_and_names_both_of_its_ends(): void
    {
        $request = $this->requestInStatus(MaintenanceRequestStatus::Completed);

        Sanctum::actingAs($this->warden);

        $this->postJson("/api/v1/maintenance-requests/{$request->id}/start")
            ->assertStatus(409)
            ->assertJsonPath('status', MaintenanceRequestStatus::Completed->value)
            ->assertJsonPath('attempted_status', MaintenanceRequestStatus::InProgress->value);

        $this->assertSame(MaintenanceRequestStatus::Completed, $request->fresh()?->status);
    }

    /**
     * The same, on the move a second warden with a stale screen would make: a
     * request already refused cannot then be accepted.
     */
    public function test_a_request_already_rejected_cannot_be_accepted(): void
    {
        $request = $this->requestInStatus(MaintenanceRequestStatus::Rejected);

        Sanctum::actingAs($this->warden);

        $this->postJson("/api/v1/maintenance-requests/{$request->id}/accept", [
            'target_date' => '2026-09-18',
        ])->assertStatus(409);
    }

    /**
     * FR-38, third criterion: «transitions are restricted by role».
     *
     * The security officer works the entrance and triages nothing; the
     * administrator reads every queue and promises no dates; the resident who
     * filed it moves it only at the two ends that are a matter of identity.
     * All three are the capability map of 13.09.2026 stated once and checked
     * here.
     */
    public function test_a_role_outside_the_graphs_actor_for_a_transition_is_refused(): void
    {
        $request = $this->requestInStatus();

        foreach ([
            'the security officer' => $this->staff(RoleCode::SecurityOfficer, $this->building, 'post@example.test'),
            'the administrator' => $this->staff(RoleCode::Administrator, null, 'admin@example.test'),
            'the resident who filed it' => $this->resident,
        ] as $who => $account) {
            Sanctum::actingAs($account);

            $this->postJson("/api/v1/maintenance-requests/{$request->id}/accept", [
                'target_date' => '2026-09-18',
            ])->assertStatus(403, sprintf('%s was allowed to accept a maintenance request.', $who));
        }

        $this->assertSame(MaintenanceRequestStatus::Submitted, $request->fresh()?->status);
    }

    /**
     * The horizontal boundary of FR-07 on a transition: a warden holds the
     * capability in his own dormitory and in no other.
     */
    public function test_a_warden_of_another_building_cannot_triage_this_request(): void
    {
        $other = $this->dormitory('Block B');
        $stranger = $this->staff(RoleCode::Warden, $other, 'warden-b@example.test');

        $request = $this->requestInStatus();

        Sanctum::actingAs($stranger);

        $this->postJson("/api/v1/maintenance-requests/{$request->id}/accept", [
            'target_date' => '2026-09-18',
        ])->assertStatus(403);

        // §3.9.6: the refusal is an event of the log in its own right.
        $this->assertNotNull(AuditLog::query()
            ->where('action', AuditAction::AccessDenied->value)
            ->where('user_id', $stranger->getKey())
            ->first());
    }

    /**
     * FR-38, fourth criterion: «the submitter is notified on every change».
     */
    public function test_each_change_enqueues_a_notification_to_the_submitter(): void
    {
        Notification::fake();

        $request = $this->requestInStatus();

        Sanctum::actingAs($this->warden);

        $this->postJson("/api/v1/maintenance-requests/{$request->id}/accept", [
            'target_date' => '2026-09-18',
        ])->assertOk();
        $this->postJson("/api/v1/maintenance-requests/{$request->id}/start")->assertOk();
        $this->postJson("/api/v1/maintenance-requests/{$request->id}/completion")->assertOk();

        foreach ([
            MaintenanceRequestStatus::Accepted,
            MaintenanceRequestStatus::InProgress,
            MaintenanceRequestStatus::Completed,
        ] as $status) {
            Notification::assertSentTo(
                $this->resident,
                MaintenanceRequestStatusChanged::class,
                fn (MaintenanceRequestStatusChanged $message): bool => $message->toStatus === $status->value
                    && $message->requestId === $request->id,
            );
        }

        Notification::assertSentToTimes($this->resident, MaintenanceRequestStatusChanged::class, 3);
    }

    /**
     * The submitter is not told what they themselves have just done. FR-34's
     * subject is which messages are worth a person's attention, and an echo of
     * one's own button is not one of them; the movement is on their own screen
     * either way.
     */
    public function test_the_submitter_is_not_told_about_their_own_act(): void
    {
        Notification::fake();

        $request = $this->requestInStatus(MaintenanceRequestStatus::Completed);

        Sanctum::actingAs($this->resident);

        $this->postJson("/api/v1/maintenance-requests/{$request->id}/confirmation")->assertOk();

        Notification::assertNotSentTo($this->resident, MaintenanceRequestStatusChanged::class);
    }

    /**
     * The reporter reads their own request and a stranger does not. The
     * dormitory's staff read it through the capability; another resident of
     * the same dormitory has no business with a defect in somebody's room.
     */
    public function test_one_resident_does_not_read_anothers_request(): void
    {
        $request = $this->requestInStatus();
        $neighbour = $this->residentOf($this->building, 'neighbour@example.test', '413');

        Sanctum::actingAs($neighbour);
        $this->getJson("/api/v1/maintenance-requests/{$request->id}")->assertStatus(403);

        Sanctum::actingAs($this->resident);
        $this->getJson("/api/v1/maintenance-requests/{$request->id}")->assertOk();

        Sanctum::actingAs($this->warden);
        $this->getJson("/api/v1/maintenance-requests/{$request->id}")->assertOk();
    }

    private function requestInStatus(
        MaintenanceRequestStatus $status = MaintenanceRequestStatus::Submitted,
    ): MaintenanceRequest {
        $factory = MaintenanceRequest::factory()
            ->forBuilding($this->building)
            ->from($this->resident)
            ->inRoom($this->roomOf($this->resident));

        return match ($status) {
            MaintenanceRequestStatus::Submitted => $factory->create(),
            MaintenanceRequestStatus::Accepted => $factory->accepted()->create(),
            MaintenanceRequestStatus::InProgress => $factory->inProgress()->create(),
            MaintenanceRequestStatus::Completed => $factory->completed()->create(),
            MaintenanceRequestStatus::Closed => $factory->confirmed()->create(),
            MaintenanceRequestStatus::Rejected => $factory->rejected()->create(),
        };
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Enums\AuditAction;
use App\Enums\MaintenanceRequestStatus;
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
 * FR-39, «Confirmation and reopening», and the fourth Gherkin scenario of
 * §2.4.3 carried over verbatim.
 *
 * This is the requirement the module is built for (§3.5.2): the request is
 * closed by the person who reported it and not by the person who fixed it,
 * because a status set by whoever did the work proves nothing. Every test
 * below is one half of that — who may close it, and what happens when the
 * person who may never answers.
 */
final class MaintenanceConfirmationTest extends TestCase
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
        $this->warden = $this->consentingStaff(RoleCode::Warden, $this->building, 'warden@example.test');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * §2.4.3, fourth scenario, word for word:
     *
     *   Given a request in status "completed" and a confirmation window of 3 days
     *   When the resident selects "not fixed" within the window
     *   Then the request returns to status "accepted", the reopen count is
     *        incremented, and the warden is notified
     *    And a request not confirmed within the window closes automatically
     *
     * The last line is a separate test below, because it is a separate
     * mechanism: a scheduled pass rather than an act of the resident.
     */
    public function test_the_resident_selects_not_fixed_within_a_three_day_window_the_request_returns_to_accepted_the_reopen_count_rises_and_the_warden_is_notified(): void
    {
        config(['dormitory.maintenance.confirmation_window_days' => 3]);
        Notification::fake();

        $request = $this->completedRequest(daysAgo: 1);

        Sanctum::actingAs($this->resident);

        $this->postJson("/api/v1/maintenance-requests/{$request->id}/reopening", [
            'comment' => 'The tap still drips.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', MaintenanceRequestStatus::Accepted->value)
            ->assertJsonPath('data.reopen_count', 1);

        $reopened = $request->fresh();

        $this->assertSame(1, $reopened?->reopen_count);
        // The clock of the next confirmation window starts fresh when the work
        // is reported done again, so the old completion is cleared.
        $this->assertNull($reopened?->completed_at);

        Notification::assertSentTo(
            $this->warden,
            MaintenanceRequestStatusChanged::class,
            fn (MaintenanceRequestStatusChanged $message): bool => $message->requestId === $request->id
                && $message->toStatus === MaintenanceRequestStatus::Accepted->value,
        );

        $this->assertNotNull(AuditLog::query()
            ->where('action', AuditAction::MaintenanceRequestReopened->value)
            ->where('subject_id', $request->id)
            ->first());
    }

    /**
     * The reopening is still the reporter's act and nobody else's — a warden
     * who thinks the resident unreasonable cannot reopen on their behalf, and
     * cannot close over their head either.
     */
    public function test_only_the_reporter_confirms_or_reopens(): void
    {
        $request = $this->completedRequest();

        foreach ([
            'the warden who accepted it' => $this->warden,
            'the manager' => $this->consentingStaff(RoleCode::Manager, $this->building, 'manager@example.test'),
            'the administrator' => $this->staff(RoleCode::Administrator, null, 'admin@example.test'),
            'a neighbour' => $this->residentOf($this->building, 'neighbour@example.test', '413'),
        ] as $who => $account) {
            Sanctum::actingAs($account);

            $this->postJson("/api/v1/maintenance-requests/{$request->id}/confirmation")
                ->assertStatus(403, sprintf('%s was allowed to close somebody else’s request.', $who));

            $this->postJson("/api/v1/maintenance-requests/{$request->id}/reopening")
                ->assertStatus(403, sprintf('%s was allowed to reopen somebody else’s request.', $who));
        }

        $this->assertSame(MaintenanceRequestStatus::Completed, $request->fresh()?->status);
    }

    /**
     * FR-39, second criterion, driven by the clock-travel helper: «a request
     * not confirmed within the window closes automatically», **marked as
     * automatically closed and not as confirmed**.
     *
     * The distinction is the whole value of the record. A request the resident
     * confirmed is evidence that the defect was fixed; one that closed because
     * nobody answered is evidence of nothing but that the dormitory stopped
     * waiting, and a system that wrote the second down as the first would be
     * manufacturing the two-sided record the module exists to produce.
     */
    public function test_a_request_not_confirmed_within_the_window_closes_automatically_and_not_as_confirmed(): void
    {
        config(['dormitory.maintenance.confirmation_window_days' => 3]);

        $request = $this->completedRequest();

        // Inside the window the pass leaves it alone.
        $this->travelTo(CarbonImmutable::now()->addDays(2));
        $this->artisan('maintenance:auto-close-confirmed-work')->assertSuccessful();
        $this->assertSame(MaintenanceRequestStatus::Completed, $request->fresh()?->status);

        // Past it, the pass closes it.
        $this->travelTo(CarbonImmutable::now()->addDays(2));
        $this->artisan('maintenance:auto-close-confirmed-work')->assertSuccessful();

        $closed = $request->fresh();

        $this->assertSame(MaintenanceRequestStatus::Closed, $closed?->status);
        $this->assertTrue($closed?->auto_closed);
        $this->assertNull($closed?->confirmed_at);
        $this->assertNotNull($closed?->closed_at);

        // §3.4.1, decision 6: the closure is a row like any other, and its
        // actor is null because nobody decided — the calendar did.
        $entry = MaintenanceWorkLog::query()
            ->where('maintenance_request_id', $request->id)
            ->where('to_status', MaintenanceRequestStatus::Closed->value)
            ->sole();

        $this->assertNull($entry->actor_id);
        $this->assertStringContainsString('Closed automatically', (string) $entry->comment);

        $this->assertNotNull(AuditLog::query()
            ->where('action', AuditAction::MaintenanceRequestAutoClosed->value)
            ->where('subject_id', $request->id)
            ->first());
    }

    /**
     * FR-39, first criterion: «within the **configurable** window». The window
     * is a setting, so moving the setting moves which requests the pass
     * closes, with no code edit.
     */
    public function test_the_confirmation_window_is_configuration_and_not_a_constant(): void
    {
        $request = $this->completedRequest(daysAgo: 5);

        config(['dormitory.maintenance.confirmation_window_days' => 10]);
        $this->artisan('maintenance:auto-close-confirmed-work')->assertSuccessful();
        $this->assertSame(MaintenanceRequestStatus::Completed, $request->fresh()?->status);

        config(['dormitory.maintenance.confirmation_window_days' => 3]);
        $this->artisan('maintenance:auto-close-confirmed-work')->assertSuccessful();
        $this->assertSame(MaintenanceRequestStatus::Closed, $request->fresh()?->status);
    }

    /**
     * The same setting seen from the client's side: the card says how long the
     * window is and whether it is still open, so the screen can offer the two
     * buttons only while they would work.
     */
    public function test_the_card_says_how_long_the_window_is_and_whether_it_is_still_open(): void
    {
        config(['dormitory.maintenance.confirmation_window_days' => 3]);

        $request = $this->completedRequest(daysAgo: 1);

        Sanctum::actingAs($this->resident);

        $this->getJson("/api/v1/maintenance-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.confirmation_window_days', 3)
            ->assertJsonPath('data.confirmation_window_open', true);
    }

    /**
     * A reopening offered after the window ran out, before the nightly pass
     * has got to the row. The state machine still admits `completed →
     * accepted` — that is the move FR-39 is about — so the refusal is a
     * separate domain exception, and it is 409 because the same call a day
     * earlier would have succeeded.
     */
    public function test_a_reopening_after_the_window_has_closed_is_refused_with_409(): void
    {
        config(['dormitory.maintenance.confirmation_window_days' => 3]);

        $request = $this->completedRequest(daysAgo: 5);

        Sanctum::actingAs($this->resident);

        $this->postJson("/api/v1/maintenance-requests/{$request->id}/reopening")
            ->assertStatus(409)
            ->assertJsonPath('confirmation_window_days', 3);

        $this->assertSame(MaintenanceRequestStatus::Completed, $request->fresh()?->status);
        $this->assertSame(0, (int) $request->fresh()?->reopen_count);

        // §3.9.6: a refusal is an event, and it survives the transaction it
        // refused because it is written outside it.
        $this->assertNotNull(AuditLog::query()
            ->where('action', AuditAction::MaintenanceReopeningRefused->value)
            ->where('subject_id', $request->id)
            ->first());
    }

    /**
     * A request already closed is closed. Whichever of the two ways it got
     * there, nothing reopens it — the reopening is the answer to «completed»,
     * not to «closed».
     */
    public function test_a_closed_request_cannot_be_reopened(): void
    {
        $request = MaintenanceRequest::factory()
            ->forBuilding($this->building)
            ->from($this->resident)
            ->inRoom($this->roomOf($this->resident))
            ->autoClosed()
            ->create();

        Sanctum::actingAs($this->resident);

        $this->postJson("/api/v1/maintenance-requests/{$request->id}/reopening")
            ->assertStatus(409);
    }

    /**
     * The reopening may carry no reason at all: FR-37 makes a refusal without
     * one impossible and FR-39 makes no such demand, and the Gherkin has the
     * resident «select “not fixed”» rather than type. The history still gets a
     * sentence, so no row of the log is blank.
     */
    public function test_a_reopening_without_a_reason_is_accepted_and_the_log_still_says_something(): void
    {
        config(['dormitory.maintenance.confirmation_window_days' => 3]);

        $request = $this->completedRequest(daysAgo: 1);

        Sanctum::actingAs($this->resident);

        $this->postJson("/api/v1/maintenance-requests/{$request->id}/reopening")->assertOk();

        $entry = MaintenanceWorkLog::query()
            ->where('maintenance_request_id', $request->id)
            ->where('from_status', MaintenanceRequestStatus::Completed->value)
            ->where('to_status', MaintenanceRequestStatus::Accepted->value)
            ->sole();

        $this->assertSame('The reporter says the defect is not fixed.', $entry->comment);
    }

    /**
     * The counter counts. A defect reported fixed twice and disputed twice
     * shows two on the card, which is the number a warden reads as «this one
     * is not going well».
     */
    public function test_the_reopen_counter_rises_with_each_dispute(): void
    {
        config(['dormitory.maintenance.confirmation_window_days' => 3]);

        $request = $this->completedRequest(daysAgo: 1);

        Sanctum::actingAs($this->resident);
        $this->postJson("/api/v1/maintenance-requests/{$request->id}/reopening")->assertOk();

        Sanctum::actingAs($this->warden);
        $this->postJson("/api/v1/maintenance-requests/{$request->id}/start")->assertOk();
        $this->postJson("/api/v1/maintenance-requests/{$request->id}/completion")->assertOk();

        Sanctum::actingAs($this->resident);
        $this->postJson("/api/v1/maintenance-requests/{$request->id}/reopening")
            ->assertOk()
            ->assertJsonPath('data.reopen_count', 2);
    }

    private function completedRequest(int $daysAgo = 0): MaintenanceRequest
    {
        return MaintenanceRequest::factory()
            ->forBuilding($this->building)
            ->from($this->resident)
            ->inRoom($this->roomOf($this->resident))
            ->completed(daysAgo: $daysAgo)
            ->create();
    }
}

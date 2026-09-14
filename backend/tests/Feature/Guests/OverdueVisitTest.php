<?php

declare(strict_types=1);

namespace Tests\Feature\Guests;

use App\Enums\AuditAction;
use App\Enums\GuestRequestStatus;
use App\Enums\GuestVisitStatus;
use App\Enums\NotificationCategory;
use App\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\GuestRequest;
use App\Models\GuestVisit;
use App\Models\User;
use App\Notifications\GuestVisitOverdue;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsAGuestScenario;
use Tests\TestCase;

/**
 * FR-20, «Departure-deadline control», and the third Gherkin scenario of
 * §2.4.2, carried over word for word:
 *
 * ```gherkin
 * Scenario: departure control
 *   Given an entry recorded at 18:00 and no exit recorded
 *   When the control time of 23:00 is reached
 *   Then the visit is marked "overdue"
 *    And a notification is sent to the inviting resident and to the security post workstation
 * ```
 *
 * The clock is travelled rather than waited on, which is what makes the
 * criterion testable at all: nothing here sleeps, and the sweep is a command
 * the test invokes at the moment it wants.
 */
final class OverdueVisitTest extends TestCase
{
    use BuildsAGuestScenario, RefreshDatabase;

    private Building $building;

    private User $resident;

    private User $guard;

    private GuestRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 18:00:00'));

        $this->building = $this->dormitory('Block A');
        $this->resident = $this->residentOf($this->building, 'resident@example.test', '305');
        $this->guard = $this->staff(RoleCode::SecurityOfficer, $this->building, 'security@example.test');

        $this->request = GuestRequest::factory()
            ->forBuilding($this->building)
            ->from($this->resident)
            ->approved()
            ->create([
                'guest_full_name' => 'Ostap Verigin',
                'visit_date' => '2026-09-14',
                'planned_from' => '14:00:00',
                'planned_to' => '23:00:00',
                'status' => GuestRequestStatus::InProgress,
            ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * §2.4.2, third scenario, and FR-20's second criterion: «an overdue visit
     * creates one event and two notifications, sent to the inviting resident
     * and to the security post».
     */
    public function test_an_entry_at_18_00_with_no_exit_is_overdue_at_the_control_time_and_two_people_are_told(): void
    {
        Notification::fake();

        // «Given an entry recorded at 18:00 and no exit recorded».
        $visit = $this->openVisitEnteredAt('2026-09-14 18:00:00');

        // Nothing yet: the control time has not been reached.
        $this->artisan('guests:sweep-overdue-visits')->assertSuccessful();
        $this->assertSame(GuestVisitStatus::InBuilding, $visit->fresh()->status);

        // «When the control time of 23:00 is reached».
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 23:00:00'));

        $this->artisan('guests:sweep-overdue-visits')->assertSuccessful();

        // «Then the visit is marked "overdue"».
        $swept = $visit->fresh();

        $this->assertSame(GuestVisitStatus::Overdue, $swept->status);
        $this->assertNotNull($swept->overdue_notified_at);
        $this->assertSame(GuestRequestStatus::Overdue, $this->request->fresh()->status);

        // One event.
        $this->assertSame(
            1,
            AuditLog::query()->where('action', AuditAction::GuestVisitOverdue->value)->count(),
        );

        // «And a notification is sent to the inviting resident and to the
        // security post workstation».
        Notification::assertSentTo($this->resident, GuestVisitOverdue::class);
        Notification::assertSentTo($this->guard, GuestVisitOverdue::class);
        Notification::assertSentTimes(GuestVisitOverdue::class, 2);
    }

    /**
     * The notice rests on the rules of internal order and not on the
     * recipient's preference, so neither party can switch it off.
     */
    public function test_the_overdue_notice_is_mandatory_and_cannot_be_switched_off(): void
    {
        $this->assertTrue(NotificationCategory::VisitOverdue->isMandatory());

        Sanctum::actingAs($this->resident);

        // The body is the one the route takes. An earlier version of this test
        // sent a shape the form rejects outright, so the 422 it asserted came
        // from a missing field and the criterion was never exercised at all.
        $this->putJson('/api/v1/notification-settings', [
            'categories' => [NotificationCategory::VisitOverdue->value => false],
        ])
            ->assertStatus(422)
            ->assertJsonPath('category', NotificationCategory::VisitOverdue->value);

        // And an optional category on the same account still switches off, so
        // the refusal is about the category and not about the route.
        $this->putJson('/api/v1/notification-settings', [
            'categories' => [NotificationCategory::RequestDecision->value => false],
        ])->assertOk();
    }

    /**
     * FR-20, first criterion: «the control time is set in configuration
     * without code changes (23:00 by default, configurable per building)».
     *
     * Changing `visiting_to` in the BUILDING row to 22:00 moves the sweep
     * without a code change.
     */
    public function test_changing_the_closing_hour_on_the_building_row_moves_the_sweep(): void
    {
        Notification::fake();

        $this->building->update(['visiting_to' => '22:00:00']);

        $visit = $this->openVisitEnteredAt('2026-09-14 18:00:00');

        $this->assertSame(
            '22:00',
            $visit->due_at->format('H:i'),
            'The deadline frozen on the visit is the earlier of the interval and the control time.',
        );

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 22:00:00'));

        $this->artisan('guests:sweep-overdue-visits')->assertSuccessful();

        $this->assertSame(GuestVisitStatus::Overdue, $visit->fresh()->status);
    }

    /**
     * Two dormitories on two regimes, swept by one command that knows neither
     * hour — and the visit each of them is judged by is its own.
     *
     * Block A closes at 21:00 and the guest was approved to 23:00, so the
     * closing hour caps the deadline at 21:00. Block B closes at 23:00 and the
     * guest was approved only to 20:00, so the **interval** is the deadline:
     * the duty officer approved until eight and the resident answers for that
     * hour however late the building closes. At 21:15 both are past their own
     * deadline and both are reported.
     */
    public function test_each_dormitory_is_swept_by_the_deadline_of_its_own_visits(): void
    {
        Notification::fake();

        $quiet = $this->dormitory('Block B', ['visiting_to' => '23:00:00']);
        $theirResident = $this->residentOf($quiet, 'other@example.test', '101');

        $theirRequest = GuestRequest::factory()
            ->forBuilding($quiet)
            ->from($theirResident)
            ->approved()
            ->create([
                'visit_date' => '2026-09-14',
                'planned_from' => '14:00:00',
                'planned_to' => '20:00:00',
                'status' => GuestRequestStatus::InProgress,
            ]);

        $theirVisit = GuestVisit::factory()
            ->forRequest($theirRequest, $this->guard)
            ->enteredAt('2026-09-14 15:00:00')
            ->create(['due_at' => $theirRequest->dueAt($quiet)]);

        $this->assertSame(
            '20:00',
            $theirVisit->due_at->format('H:i'),
            'The end of the approved interval is a deadline in its own right.',
        );

        $this->building->update(['visiting_to' => '21:00:00']);
        $mine = $this->openVisitEnteredAt('2026-09-14 18:00:00');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 21:15:00'));

        $this->artisan('guests:sweep-overdue-visits')->assertSuccessful();

        $this->assertSame(GuestVisitStatus::Overdue, $mine->fresh()->status);
        $this->assertSame(GuestVisitStatus::Overdue, $theirVisit->fresh()->status);
    }

    /**
     * A visit still inside its deadline is left alone, whatever hour the sweep
     * runs at. The negative half of the criterion, and the one the condition
     * `due_at <= now` has to keep on its own now that no building filter
     * stands in front of it.
     */
    public function test_a_visit_still_inside_its_deadline_is_left_alone(): void
    {
        Notification::fake();

        $visit = $this->openVisitEnteredAt('2026-09-14 18:00:00');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 22:59:00'));

        $this->artisan('guests:sweep-overdue-visits')->assertSuccessful();

        $this->assertSame(GuestVisitStatus::InBuilding, $visit->fresh()->status);
        $this->assertNull($visit->fresh()->overdue_notified_at);
        Notification::assertNothingSent();
    }

    /**
     * The defect the acceptance run on the live stand found, and the reason
     * FR-20 was not met.
     *
     * A guest approved until 14:00 in a dormitory that closes at 23:00 is
     * overdue at 14:00. `GuestRequest::dueAt()` says so — it takes the earlier
     * of the interval and the closing hour, which makes the end of the
     * interval a deadline of its own — and `CheckpointService::checkOut()`
     * agrees, since it closes such a visit `closed_late`. The sweep used to
     * pick the dormitories past their closing hour first and look inside only
     * those, so the resident answerable under clause 2.2 heard nothing for
     * nine hours.
     */
    public function test_a_visit_past_the_end_of_its_interval_is_reported_long_before_the_closing_hour(): void
    {
        Notification::fake();

        $short = GuestRequest::factory()
            ->forBuilding($this->building)
            ->from($this->resident)
            ->approved()
            ->create([
                'guest_full_name' => 'Ivan Lyzhin',
                'visit_date' => '2026-09-14',
                'planned_from' => '10:00:00',
                'planned_to' => '14:00:00',
                'status' => GuestRequestStatus::InProgress,
            ]);

        $visit = GuestVisit::factory()
            ->forRequest($short, $this->guard)
            ->enteredAt('2026-09-14 10:05:00')
            ->create(['due_at' => $short->dueAt($this->building)]);

        $this->assertSame('14:00', $visit->due_at->format('H:i'));
        $this->assertSame('23:00:00', (string) $this->building->visiting_to);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 14:15:00'));

        $this->artisan('guests:sweep-overdue-visits')->assertSuccessful();

        $swept = $visit->fresh();

        $this->assertSame(GuestVisitStatus::Overdue, $swept->status);
        $this->assertSame('2026-09-14 14:15:00', $swept->overdue_notified_at->format('Y-m-d H:i:s'));
        $this->assertSame(GuestRequestStatus::Overdue, $short->fresh()->status);

        Notification::assertSentTo($this->resident, GuestVisitOverdue::class);
        Notification::assertSentTo($this->guard, GuestVisitOverdue::class);
    }

    /**
     * The verification asks for it by name: «overdue_notified_at prevents a
     * second notification on a repeat run».
     */
    public function test_a_repeat_run_reports_nothing_a_second_time(): void
    {
        Notification::fake();

        $visit = $this->openVisitEnteredAt('2026-09-14 18:00:00');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 23:00:00'));
        $this->artisan('guests:sweep-overdue-visits')->assertSuccessful();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 23:15:00'));
        $this->artisan('guests:sweep-overdue-visits')->assertSuccessful();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 23:30:00'));
        $this->artisan('guests:sweep-overdue-visits')->assertSuccessful();

        Notification::assertSentTimes(GuestVisitOverdue::class, 2);

        $this->assertSame(
            1,
            AuditLog::query()->where('action', AuditAction::GuestVisitOverdue->value)->count(),
        );

        $this->assertSame(
            '2026-09-14 23:00:00',
            $visit->fresh()->overdue_notified_at->format('Y-m-d H:i:s'),
        );
    }

    /**
     * FR-21's immutability, at the point where it protects FR-20: the moment
     * of the report is written once, and the database refuses to move it even
     * from outside the application.
     */
    public function test_the_database_refuses_to_rewrite_the_moment_the_visit_was_reported_overdue(): void
    {
        Notification::fake();

        $visit = $this->openVisitEnteredAt('2026-09-14 18:00:00');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 23:00:00'));
        $this->artisan('guests:sweep-overdue-visits')->assertSuccessful();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/write-once/');

        DB::table('guest_visits')
            ->where('id', $visit->getKey())
            ->update(['overdue_notified_at' => '2026-09-15 01:00:00']);
    }

    /**
     * FR-20, third criterion: «the fact is visible on the inviting resident's
     * card».
     */
    public function test_the_overdue_visit_shows_on_the_inviting_residents_card(): void
    {
        Notification::fake();

        $visit = $this->openVisitEnteredAt('2026-09-14 18:00:00');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 23:00:00'));
        $this->artisan('guests:sweep-overdue-visits')->assertSuccessful();

        Sanctum::actingAs($this->staff(RoleCode::Warden, $this->building, 'warden@example.test'));

        $card = $this->getJson("/api/v1/residents/{$this->resident->id}")->assertOk();

        $card
            ->assertJsonCount(1, 'data.overdue_guest_visits')
            ->assertJsonPath('data.overdue_guest_visits.0.guest_visit_id', $visit->getKey())
            ->assertJsonPath('data.overdue_guest_visits.0.guest_full_name', 'Ostap Verigin');
    }

    /**
     * The card keeps the fact after the guest finally leaves. The condition is
     * `overdue_notified_at` and not the status, so a visit closed late still
     * shows — what happened does not stop having happened.
     */
    public function test_the_card_keeps_the_fact_after_the_guest_has_left(): void
    {
        Notification::fake();

        $visit = $this->openVisitEnteredAt('2026-09-14 18:00:00');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 23:00:00'));
        $this->artisan('guests:sweep-overdue-visits')->assertSuccessful();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 23:40:00'));

        Sanctum::actingAs($this->guard);
        $this->postJson('/api/v1/checkpoint/check-out', [
            'guest_visit_id' => $visit->getKey(),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', GuestVisitStatus::ClosedLate->value);

        Sanctum::actingAs($this->staff(RoleCode::Warden, $this->building, 'warden@example.test'));

        $this->getJson("/api/v1/residents/{$this->resident->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.overdue_guest_visits')
            ->assertJsonPath('data.overdue_guest_visits.0.status', GuestVisitStatus::ClosedLate->value);
    }

    public function test_a_card_with_no_overdue_visits_says_so_rather_than_omitting_the_field(): void
    {
        Sanctum::actingAs($this->staff(RoleCode::Warden, $this->building, 'warden@example.test'));

        $this->getJson("/api/v1/residents/{$this->resident->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.overdue_guest_visits');
    }

    private function openVisitEnteredAt(string $moment): GuestVisit
    {
        return GuestVisit::factory()
            ->forRequest($this->request, $this->guard)
            ->enteredAt($moment)
            ->create(['due_at' => $this->request->dueAt($this->building)]);
    }
}

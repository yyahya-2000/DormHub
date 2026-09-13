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

        $this->putJson('/api/v1/notification-settings', [
            'settings' => [
                ['category' => NotificationCategory::VisitOverdue->value, 'enabled' => false],
            ],
        ])->assertStatus(422);
    }

    /**
     * FR-20, first criterion: «the control time is set in configuration
     * without code changes (23:00 by default, configurable per building)».
     *
     * The verification asks for exactly this: «changing `curfew_at` in the
     * BUILDING row to 22:00 moves the sweep without a code change».
     */
    public function test_changing_the_curfew_on_the_building_row_moves_the_sweep(): void
    {
        Notification::fake();

        $this->building->update(['curfew_at' => '22:00:00']);

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

    public function test_a_dormitory_whose_control_time_has_not_come_round_is_left_alone(): void
    {
        Notification::fake();

        $quiet = $this->dormitory('Block B', ['curfew_at' => '23:00:00']);
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
            ->create();

        $this->building->update(['curfew_at' => '21:00:00']);
        $mine = $this->openVisitEnteredAt('2026-09-14 18:00:00');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 21:15:00'));

        $this->artisan('guests:sweep-overdue-visits')->assertSuccessful();

        // Block A closes at 21:00 and is swept; Block B closes at 23:00 and is
        // not. One command, two regimes, no code that knows either hour.
        $this->assertSame(GuestVisitStatus::Overdue, $mine->fresh()->status);
        $this->assertSame(GuestVisitStatus::InBuilding, $theirVisit->fresh()->status);
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

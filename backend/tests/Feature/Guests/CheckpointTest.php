<?php

declare(strict_types=1);

namespace Tests\Feature\Guests;

use App\Enums\AuditAction;
use App\Enums\GuestRequestStatus;
use App\Enums\GuestVisitStatus;
use App\Enums\RoleCode;
use App\Exceptions\EntryNotPermittedException;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\GuestRequest;
use App\Models\GuestVisit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsAGuestScenario;
use Tests\TestCase;

/**
 * FR-18, «Guest verification at the security post», and FR-19, «Recording
 * entry and exit» — with the two scenarios §2.4.2 states in Gherkin carried
 * over word for word.
 *
 * ```gherkin
 * Scenario: a guest passes on an approved request
 *   Given a guest request in status "approved", visit interval 14:00-23:00 on the current date
 *   When the security officer enters the request code at 14:20
 *   Then the system shows the guest's name, the inviting resident's name, the room number
 *        and the departure deadline 23:00
 *    And the "record entry" action is enabled
 *
 * Scenario: attempted entry outside the interval
 *   Given the same request
 *   When the security officer enters the code at 23:40
 *   Then the system shows the status "outside the permitted interval" and blocks the entry record
 *    And an "admit on the responsible officer's decision" action is available with a mandatory
 *        reason field
 * ```
 *
 * The third scenario, departure control, is a scheduled sweep rather than a
 * request and lives in `OverdueVisitTest`.
 */
final class CheckpointTest extends TestCase
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

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 13:00:00'));

        $this->building = $this->dormitory('Block A');
        $this->resident = $this->residentOf($this->building, 'resident@example.test', '305');
        $this->guard = $this->staff(RoleCode::SecurityOfficer, $this->building, 'security@example.test');

        // «Given a guest request in status "approved", visit interval
        // 14:00-23:00 on the current date».
        $this->request = GuestRequest::factory()
            ->forBuilding($this->building)
            ->from($this->resident)
            ->approved($this->staff(RoleCode::DutyOfficer, $this->building, 'duty@example.test'))
            ->create([
                'guest_full_name' => 'Ostap Verigin',
                'visit_date' => '2026-09-14',
                'planned_from' => '14:00:00',
                'planned_to' => '23:00:00',
            ]);
    }

    /**
     * The acceptance finding of 15.09.2026: the dormitory's day and the
     * server's day are not the same day.
     *
     * Half past nine in the evening UTC is half past midnight in Moscow — the
     * dormitory is already on the sixteenth and a server keeping UTC is still
     * on the fifteenth. The post then read a request approved for the
     * sixteenth, compared it with its own date and answered «the request is
     * for another day», with the guest standing at the desk and the resident
     * holding a message that said the visit was approved. The window was three
     * hours out for the same reason: `planned_from` and `planned_to` are the
     * dormitory's wall-clock times and were being compared against a clock
     * running somewhere else.
     *
     * The dormitory here admits guests around the clock, which is the regime
     * NFR-09 makes a property of the building and `TimeWindow` documents — a
     * window from 08:00 to 08:00 is twenty-four hours. It is the only regime
     * under which a guest is legitimately at the desk at half past midnight,
     * which is what makes it the case that shows the defect.
     */
    public function test_a_guest_is_admitted_on_the_dormitorys_day_and_not_the_servers(): void
    {
        // The dormitory's clock reads 00:30 on the sixteenth; a server keeping
        // UTC reads 21:30 on the fifteenth.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 21:30:00', 'UTC'));

        $roundTheClock = $this->dormitory('Block C', [
            'visiting_from' => '08:00:00',
            'visiting_to' => '08:00:00',
        ]);

        $resident = $this->residentOf($roundTheClock, 'night-owl@example.test', '118');
        $guard = $this->staff(RoleCode::SecurityOfficer, $roundTheClock, 'night-post@example.test');

        $request = GuestRequest::factory()
            ->forBuilding($roundTheClock)
            ->from($resident)
            ->approved($this->staff(RoleCode::DutyOfficer, $roundTheClock, 'night-duty@example.test'))
            ->create([
                'guest_full_name' => 'Ostap Verigin',
                // The sixteenth: today, as anybody in the building would say.
                'visit_date' => '2026-09-16',
                'planned_from' => '00:00:00',
                'planned_to' => '02:00:00',
            ]);

        Sanctum::actingAs($guard);

        $this->postJson('/api/v1/checkpoint/verify', [
            'building_id' => $roundTheClock->getKey(),
            'code' => $request->access_code,
        ])
            ->assertOk()
            ->assertJsonPath('data.0.admission.allowed', true)
            ->assertJsonPath('data.0.admission.reason_code', null);

        $this->postJson('/api/v1/checkpoint/check-in', [
            'guest_request_id' => $request->getKey(),
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.admitted_on_decision', false);

        $this->assertSame(
            GuestVisitStatus::InBuilding,
            GuestVisit::query()->where('guest_request_id', $request->getKey())->sole()->status,
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * §2.4.2, first scenario: «a guest passes on an approved request».
     */
    public function test_the_code_entered_at_14_20_shows_the_five_fields_and_enables_the_entry(): void
    {
        $this->atTwentyPastTwo();

        Sanctum::actingAs($this->guard);

        $card = $this->postJson('/api/v1/checkpoint/verify', [
            'building_id' => $this->building->getKey(),
            'code' => $this->request->access_code,
        ])->assertOk();

        // «Then the system shows the guest's name, the inviting resident's
        // name, the room number and the departure deadline 23:00».
        $card
            ->assertJsonPath('data.0.guest.full_name', 'Ostap Verigin')
            ->assertJsonPath('data.0.inviting_resident.full_name', $this->resident->full_name)
            ->assertJsonPath('data.0.room', '305')
            ->assertJsonPath('data.0.status', GuestRequestStatus::Approved->value);

        $this->assertSame(
            '23:00',
            CarbonImmutable::parse((string) $card->json('data.0.due_at'))->format('H:i'),
            'The departure deadline of clause 2.2 is what the officer reads off the card.',
        );

        // «And the "record entry" action is enabled».
        $card
            ->assertJsonPath('data.0.admission.allowed', true)
            ->assertJsonPath('data.0.admission.reason_code', null);
    }

    /**
     * §2.4.2, second scenario: «attempted entry outside the interval».
     */
    public function test_the_same_code_at_23_40_is_outside_the_interval_and_offers_the_officers_decision(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 23:40:00'));

        Sanctum::actingAs($this->guard);

        // «Then the system shows the status "outside the permitted interval"
        // and blocks the entry record».
        $card = $this->postJson('/api/v1/checkpoint/verify', [
            'building_id' => $this->building->getKey(),
            'code' => $this->request->access_code,
        ])->assertOk();

        $card
            ->assertJsonPath('data.0.admission.allowed', false)
            ->assertJsonPath('data.0.admission.reason_code', EntryNotPermittedException::OUTSIDE_INTERVAL)
            // «And an "admit on the responsible officer's decision" action is
            // available with a mandatory reason field».
            ->assertJsonPath('data.0.admission.override_available', true)
            ->assertJsonPath('data.0.admission.override_requires_reason', true);

        // Blocked: the entry record is refused while no reason is given.
        $this->postJson('/api/v1/checkpoint/check-in', [
            'guest_request_id' => $this->request->getKey(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('reason_code', EntryNotPermittedException::OUTSIDE_INTERVAL)
            ->assertJsonPath('override_available', true);

        $this->assertSame(0, GuestVisit::query()->count());

        // §3.9.6 names the refusal of entry among the events the log must
        // hold: the guest turned up and left no visit row, so without this the
        // evening would have no record of them at all.
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::GuestEntryRefused->value]);

        // With the reason, the entry is recorded and marked as an admission on
        // a decision — an exception somebody has to answer for, so it is a
        // query of its own in the log rather than a field inside a payload.
        $this->postJson('/api/v1/checkpoint/check-in', [
            'guest_request_id' => $this->request->getKey(),
            'override_reason' => 'Admitted by the responsible officer on duty; the guest missed the last bus.',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.admitted_on_decision', true);

        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::GuestAdmittedOnDecision->value]);
    }

    /**
     * FR-18, second criterion, from the other search: by surname, as the post
     * falls back to when the guest has lost the code.
     */
    public function test_the_post_finds_a_guest_by_surname_as_well_as_by_code(): void
    {
        $this->atTwentyPastTwo();

        Sanctum::actingAs($this->guard);

        $this->postJson('/api/v1/checkpoint/verify', [
            'building_id' => $this->building->getKey(),
            'surname' => 'Verigin',
        ])
            ->assertOk()
            ->assertJsonPath('data.0.guest.full_name', 'Ostap Verigin')
            ->assertJsonPath('meta.matches', 1);
    }

    /**
     * §3.5.1: «verification is split in two deliberately: verify only reads
     * and renders the card, and check-in changes state». The split is what
     * lets the officer refuse entry without leaving a false record.
     */
    public function test_verification_changes_nothing(): void
    {
        $this->atTwentyPastTwo();

        Sanctum::actingAs($this->guard);

        $this->postJson('/api/v1/checkpoint/verify', [
            'building_id' => $this->building->getKey(),
            'code' => $this->request->access_code,
        ])->assertOk()->assertJsonPath('meta.read_only', true);

        $this->assertSame(GuestRequestStatus::Approved, $this->request->fresh()->status);
        $this->assertSame(0, GuestVisit::query()->count());
    }

    public function test_a_code_of_another_dormitory_is_not_found_at_this_post(): void
    {
        $this->atTwentyPastTwo();

        $elsewhere = $this->dormitory('Block B');
        $theirResident = $this->residentOf($elsewhere, 'other@example.test', '101');

        $theirRequest = GuestRequest::factory()
            ->forBuilding($elsewhere)
            ->from($theirResident)
            ->approved()
            ->create(['visit_date' => '2026-09-14']);

        Sanctum::actingAs($this->guard);

        // The contract declares a message on this answer, and the body used to
        // carry none: a terminal typed against it received an empty array and
        // a field that was never sent. The two misses read differently at the
        // desk, so they say different things.
        $this->postJson('/api/v1/checkpoint/verify', [
            'building_id' => $this->building->getKey(),
            'code' => $theirRequest->access_code,
        ])
            ->assertStatus(404)
            ->assertJsonPath('message', 'No visit in this dormitory answers to that code.')
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.matches', 0);

        $this->postJson('/api/v1/checkpoint/verify', [
            'building_id' => $this->building->getKey(),
            'surname' => 'Nobodyhere',
        ])
            ->assertStatus(404)
            ->assertJsonPath('message', 'No guest of that name is expected in this dormitory today.');

        // And the officer of this post cannot simply name the other building.
        $this->postJson('/api/v1/checkpoint/verify', [
            'building_id' => $elsewhere->getKey(),
            'code' => $theirRequest->access_code,
        ])->assertStatus(403);
    }

    /**
     * The code outlives the withdrawal on purpose; the card must not.
     *
     * A resident may withdraw an approved request, and the access code stays
     * on the row so that a guest who turns up anyway is met with «this visit
     * was cancelled» rather than «no such code» — the migration says so in as
     * many words. The entry was refused correctly all along. What the card
     * still did was hand whoever held the withdrawn code the guest's name, the
     * host's name and the host's room number, and none of those has a ground
     * any more (§2.7.1, NFR-06): the ground was the visit that is now not
     * going to happen.
     */
    public function test_a_withdrawn_request_still_answers_to_its_code_and_names_nobody(): void
    {
        $this->atTwentyPastTwo();

        Sanctum::actingAs($this->resident);

        $this->postJson("/api/v1/guest-requests/{$this->request->id}/cancellation")->assertOk();

        Sanctum::actingAs($this->guard);

        $card = $this->postJson('/api/v1/checkpoint/verify', [
            'building_id' => $this->building->getKey(),
            'code' => $this->request->access_code,
        ])->assertOk();

        // The officer is told what to say at the desk.
        $card
            ->assertJsonPath('data.0.disclosed', false)
            ->assertJsonPath('data.0.status', GuestRequestStatus::Cancelled->value)
            ->assertJsonPath('data.0.admission.allowed', false)
            ->assertJsonPath('data.0.admission.reason_code', EntryNotPermittedException::NOT_APPROVED)
            ->assertJsonPath('data.0.admission.override_available', false);

        // And nobody is named.
        $card
            ->assertJsonPath('data.0.guest', null)
            ->assertJsonPath('data.0.inviting_resident', null)
            ->assertJsonPath('data.0.room', null)
            ->assertJsonPath('data.0.access_code', null)
            ->assertJsonPath('data.0.due_at', null)
            ->assertJsonPath('data.0.permitted_interval', null);

        $body = (string) $card->getContent();

        $this->assertStringNotContainsString('Ostap Verigin', $body);
        $this->assertStringNotContainsString((string) $this->resident->full_name, $body);
        $this->assertStringNotContainsString('305', $body);

        // And the entry is refused on the state of the request, which is the
        // refusal that always mattered: a withdrawn request admits nobody, and
        // there is no reason the officer could give that would set it aside.
        $this->postJson('/api/v1/checkpoint/check-in', [
            'guest_request_id' => $this->request->getKey(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('reason_code', EntryNotPermittedException::NOT_APPROVED)
            ->assertJsonPath('override_available', false);
    }

    /**
     * The same for a refusal by the duty officer. A guest turned away at the
     * decision has no more claim on the register than a withdrawn one.
     */
    public function test_a_refused_request_names_nobody_at_the_post_either(): void
    {
        $this->atTwentyPastTwo();

        $refused = GuestRequest::factory()
            ->forBuilding($this->building)
            ->from($this->resident)
            ->rejected($this->staff(RoleCode::DutyOfficer, $this->building, 'duty2@example.test'))
            ->create([
                'guest_full_name' => 'Pyotr Nezvanov',
                'visit_date' => '2026-09-14',
            ]);

        Sanctum::actingAs($this->guard);

        $this->postJson('/api/v1/checkpoint/verify', [
            'building_id' => $this->building->getKey(),
            'surname' => 'Nezvanov',
        ])
            ->assertOk()
            ->assertJsonPath('data.0.guest_request_id', $refused->getKey())
            ->assertJsonPath('data.0.disclosed', false)
            ->assertJsonPath('data.0.guest', null);
    }

    /**
     * The completed visit is not an exception to the rule above but the rule
     * itself: the visit happened, the register of clause 2.1.2 holds it, and
     * the officer looking it up is reading the journal.
     */
    public function test_a_completed_visit_still_names_the_guest_it_recorded(): void
    {
        $this->atTwentyPastTwo();

        $this->guestIsInside();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 19:00:00'));

        Sanctum::actingAs($this->guard);

        $visit = GuestVisit::query()->where('guest_request_id', $this->request->getKey())->sole();

        $this->postJson('/api/v1/checkpoint/check-out', ['guest_visit_id' => $visit->getKey()])
            ->assertOk();

        $this->postJson('/api/v1/checkpoint/verify', [
            'building_id' => $this->building->getKey(),
            'code' => $this->request->access_code,
        ])
            ->assertOk()
            ->assertJsonPath('data.0.disclosed', true)
            ->assertJsonPath('data.0.guest.full_name', 'Ostap Verigin')
            ->assertJsonPath('data.0.status', GuestRequestStatus::Completed->value);
    }

    public function test_a_resident_may_not_work_the_security_post(): void
    {
        $this->atTwentyPastTwo();

        Sanctum::actingAs($this->resident);

        $this->postJson('/api/v1/checkpoint/verify', [
            'building_id' => $this->building->getKey(),
            'code' => $this->request->access_code,
        ])->assertStatus(403);
    }

    /**
     * FR-19, first criterion, and the state change it carries: the entry is
     * recorded, the request moves to `in_progress`, and the deadline is frozen
     * on the visit.
     */
    public function test_the_entry_is_recorded_and_the_request_moves_to_in_progress(): void
    {
        $this->atTwentyPastTwo();

        Sanctum::actingAs($this->guard);

        $response = $this->postJson('/api/v1/checkpoint/check-in', [
            'guest_request_id' => $this->request->getKey(),
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', GuestVisitStatus::InBuilding->value)
            ->assertJsonPath('data.checked_in_by', $this->guard->getKey());

        $visit = GuestVisit::query()->findOrFail($response->json('data.id'));

        $this->assertSame('2026-09-14 14:20:00', $visit->checked_in_at->format('Y-m-d H:i:s'));
        $this->assertSame('23:00', $visit->due_at->format('H:i'));
        $this->assertSame(GuestRequestStatus::InProgress, $this->request->fresh()->status);

        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::GuestEntryRecorded->value]);
    }

    public function test_a_second_entry_on_the_same_request_is_refused(): void
    {
        $this->atTwentyPastTwo();

        Sanctum::actingAs($this->guard);

        $this->postJson('/api/v1/checkpoint/check-in', [
            'guest_request_id' => $this->request->getKey(),
        ])->assertStatus(201);

        $this->postJson('/api/v1/checkpoint/check-in', [
            'guest_request_id' => $this->request->getKey(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('reason_code', EntryNotPermittedException::ALREADY_INSIDE)
            ->assertJsonPath('override_available', false);
    }

    public function test_a_request_that_was_never_approved_admits_nobody(): void
    {
        $this->atTwentyPastTwo();

        $pending = GuestRequest::factory()
            ->forBuilding($this->building)
            ->from($this->resident)
            ->create(['visit_date' => '2026-09-14']);

        Sanctum::actingAs($this->guard);

        $this->postJson('/api/v1/checkpoint/check-in', [
            'guest_request_id' => $pending->getKey(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('reason_code', EntryNotPermittedException::NOT_APPROVED)
            ->assertJsonPath('override_available', false);
    }

    /**
     * FR-19, second criterion: «an exit cannot be recorded earlier than the
     * entry». The exit closes the visit and completes the request.
     */
    public function test_the_exit_closes_the_visit_and_completes_the_request(): void
    {
        $visit = $this->guestIsInside();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 21:05:00'));

        Sanctum::actingAs($this->guard);

        $this->postJson('/api/v1/checkpoint/check-out', [
            'guest_visit_id' => $visit->getKey(),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', GuestVisitStatus::Closed->value)
            ->assertJsonPath('data.checked_out_by', $this->guard->getKey());

        $this->assertSame(GuestRequestStatus::Completed, $this->request->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::GuestExitRecorded->value]);
    }

    public function test_an_exit_after_the_deadline_closes_the_visit_as_late(): void
    {
        $visit = $this->guestIsInside();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 23:35:00'));

        Sanctum::actingAs($this->guard);

        $this->postJson('/api/v1/checkpoint/check-out', [
            'guest_visit_id' => $visit->getKey(),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', GuestVisitStatus::ClosedLate->value);

        $this->assertSame(GuestRequestStatus::Completed, $this->request->fresh()->status);
    }

    /**
     * FR-19's verification asks for this one at the database: «CHECK
     * (checked_out_at IS NULL OR checked_out_at >= checked_in_at) refuses an
     * exit before an entry on a direct INSERT».
     */
    public function test_the_database_refuses_an_exit_recorded_before_the_entry(): void
    {
        $visit = $this->guestIsInside();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/guest_visits_exit_after_entry/');

        DB::table('guest_visits')->insert([
            'guest_request_id' => GuestRequest::factory()
                ->forBuilding($this->building)
                ->from($this->resident)
                ->approved()
                ->create()
                ->getKey(),
            'checked_in_at' => '2026-09-14 18:00:00',
            'checked_in_by' => $this->guard->getKey(),
            'checked_out_at' => '2026-09-14 17:00:00',
            'checked_out_by' => $this->guard->getKey(),
            'status' => GuestVisitStatus::Closed->value,
            'due_at' => $visit->due_at,
            'admitted_on_decision' => false,
        ]);
    }

    public function test_a_lookup_at_the_post_is_recorded(): void
    {
        $this->atTwentyPastTwo();

        Sanctum::actingAs($this->guard);

        $this->postJson('/api/v1/checkpoint/verify', [
            'building_id' => $this->building->getKey(),
            'code' => $this->request->access_code,
        ])->assertOk();

        $entry = AuditLog::query()
            ->where('action', AuditAction::GuestVerifiedAtCheckpoint->value)
            ->sole();

        $this->assertSame($this->guard->getKey(), $entry->user_id);
        $this->assertSame('access_code', $entry->payload['by']);
    }

    private function atTwentyPastTwo(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 14:20:00'));
    }

    private function guestIsInside(): GuestVisit
    {
        $this->atTwentyPastTwo();

        Sanctum::actingAs($this->guard);

        $response = $this->postJson('/api/v1/checkpoint/check-in', [
            'guest_request_id' => $this->request->getKey(),
        ])->assertStatus(201);

        return GuestVisit::query()->findOrFail($response->json('data.id'));
    }
}

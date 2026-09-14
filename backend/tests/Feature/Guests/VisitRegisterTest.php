<?php

declare(strict_types=1);

namespace Tests\Feature\Guests;

use App\Enums\AuditAction;
use App\Enums\GuestRequestStatus;
use App\Enums\GuestVisitStatus;
use App\Enums\RoleCode;
use App\Exceptions\ImmutableRecordException;
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
 * FR-21, «Visitor register»: the journal of clause 2.1.2 of the HSE rules of
 * internal order with the pen removed.
 *
 * The clause makes the security service write down six things about every
 * outsider admitted — the guest, the time of arrival, the time of departure,
 * the premises, whom they are visiting, and the details of the document — and
 * those six are what the first test below asserts, one at a time. The seventh
 * column, the operator, is not in the clause: a paper journal is written in
 * somebody's handwriting on a numbered page, and an electronic one has to say
 * in words what the page said by existing.
 */
final class VisitRegisterTest extends TestCase
{
    use BuildsAGuestScenario, RefreshDatabase;

    private Building $building;

    private User $resident;

    private User $guard;

    private User $warden;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 12:00:00'));

        $this->building = $this->dormitory('Block A');
        $this->resident = $this->residentOf($this->building, 'resident@example.test', '305');
        $this->guard = $this->staff(RoleCode::SecurityOfficer, $this->building, 'security@example.test');
        $this->warden = $this->staff(RoleCode::Warden, $this->building, 'warden@example.test');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * Third criterion: «the register holds guest, document data, inviting
     * resident, room, entry and exit time, operator».
     */
    public function test_the_register_holds_the_six_fields_of_clause_2_1_2_and_the_operator(): void
    {
        $this->visitOn('2026-09-10', '13:15:00', '16:40:00', 'Ostap Verigin');

        Sanctum::actingAs($this->warden);

        $row = $this->getJson(
            "/api/v1/buildings/{$this->building->id}/visit-register?from=2026-09-01&until=2026-09-30"
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');

        // 1. The guest.
        $this->assertSame('Ostap Verigin', $row['guest_full_name']);
        // 2. The details of the document — the type, and the number masked.
        $this->assertStringContainsString('Internal passport', $row['guest_document']);
        $this->assertStringContainsString('•', $row['guest_document']);
        // 3. Whom the guest is visiting.
        $this->assertSame($this->resident->full_name, $row['inviting_resident']);
        // 4. The premises.
        $this->assertSame('305', $row['room']);
        // 5 and 6. The time of arrival and the time of departure.
        $this->assertSame('2026-09-10 13:15', CarbonImmutable::parse($row['checked_in_at'])->format('Y-m-d H:i'));
        $this->assertSame('2026-09-10 16:40', CarbonImmutable::parse($row['checked_out_at'])->format('Y-m-d H:i'));
        // 7. The operator, which the clause does not ask for and an electronic
        // register cannot do without.
        $this->assertSame($this->guard->full_name, $row['operator']);
    }

    /**
     * First criterion: «the register exports over an arbitrary period».
     */
    public function test_the_register_exports_over_an_arbitrary_period(): void
    {
        $this->visitOn('2026-09-02', '10:00:00', '12:00:00', 'Guest of September the second');
        $this->visitOn('2026-09-10', '10:00:00', '12:00:00', 'Guest of September the tenth');
        $this->visitOn('2026-09-20', '10:00:00', '12:00:00', 'Guest of September the twentieth');

        Sanctum::actingAs($this->warden);

        $this->getJson("/api/v1/buildings/{$this->building->id}/visit-register?from=2026-09-01&until=2026-09-30")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3);

        $narrow = $this->getJson(
            "/api/v1/buildings/{$this->building->id}/visit-register?from=2026-09-05&until=2026-09-15"
        )->assertOk();

        $narrow
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.guest_full_name', 'Guest of September the tenth');

        // Both ends of the period are included: a day named is a day exported.
        $this->getJson("/api/v1/buildings/{$this->building->id}/visit-register?from=2026-09-10&until=2026-09-10")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_the_register_exports_as_csv_with_the_seven_columns_named(): void
    {
        $this->visitOn('2026-09-10', '13:15:00', '16:40:00', 'Ostap Verigin');

        Sanctum::actingAs($this->warden);

        $response = $this->get(
            "/api/v1/buildings/{$this->building->id}/visit-register?from=2026-09-01&until=2026-09-30&format=csv"
        )->assertOk();

        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));

        $csv = (string) $response->getContent();

        $this->assertStringContainsString('Guest,Document,Visiting,Premises,Entered,Left,"Recorded by"', $csv);
        $this->assertStringContainsString('Ostap Verigin', $csv);
        $this->assertStringContainsString('305', $csv);
        $this->assertStringContainsString($this->guard->full_name, $csv);
    }

    /**
     * §3.9.6: «it is itself an audited action».
     */
    public function test_the_export_is_itself_recorded(): void
    {
        $this->visitOn('2026-09-10', '13:15:00', '16:40:00', 'Ostap Verigin');

        Sanctum::actingAs($this->warden);

        $this->get(
            "/api/v1/buildings/{$this->building->id}/visit-register?from=2026-09-01&until=2026-09-30&format=csv"
        )->assertOk();

        $entry = AuditLog::query()
            ->where('action', AuditAction::VisitRegisterExported->value)
            ->sole();

        $this->assertSame($this->warden->getKey(), $entry->user_id);
        $this->assertSame('2026-09-01', $entry->payload['from']);
        $this->assertSame('2026-09-30', $entry->payload['until']);
    }

    /**
     * §3.9.6 draws the circle: «available to the administrator and to the
     * warden of the building concerned».
     */
    public function test_the_register_belongs_to_the_warden_and_the_administrator_and_to_nobody_else(): void
    {
        $url = "/api/v1/buildings/{$this->building->id}/visit-register";

        Sanctum::actingAs($this->warden);
        $this->getJson($url)->assertOk();

        Sanctum::actingAs($this->staff(RoleCode::Administrator, null, 'admin@example.test'));
        $this->getJson($url)->assertOk();

        foreach ([
            [RoleCode::Manager, $this->building, 'manager@example.test'],
            [RoleCode::DutyOfficer, $this->building, 'duty@example.test'],
            [RoleCode::SecurityOfficer, $this->building, 'guard2@example.test'],
        ] as [$code, $scope, $email]) {
            Sanctum::actingAs($this->staff($code, $scope, $email));
            $this->getJson($url)->assertStatus(403);
        }

        Sanctum::actingAs($this->resident);
        $this->getJson($url)->assertStatus(403);
    }

    public function test_the_warden_of_another_dormitory_reads_no_register_here(): void
    {
        $elsewhere = $this->dormitory('Block B');

        Sanctum::actingAs($this->staff(RoleCode::Warden, $elsewhere, 'warden-b@example.test'));

        $this->getJson("/api/v1/buildings/{$this->building->id}/visit-register")->assertStatus(403);
    }

    /**
     * Second criterion: «entries are immutable; a correction is made as a
     * correcting entry».
     */
    public function test_a_correction_is_a_new_entry_and_the_visit_itself_is_untouched(): void
    {
        $visit = $this->visitOn('2026-09-10', '13:15:00', '16:40:00', 'Ostap Verigin');

        Sanctum::actingAs($this->warden);

        $this->postJson("/api/v1/guest-visits/{$visit->id}/correction", [
            'correction' => 'The exit was recorded at 16:40 by the clock at the desk; '
                .'the officer reports the guest left at about 16:25.',
        ])->assertStatus(201);

        $unchanged = $visit->fresh();

        $this->assertSame('16:40', $unchanged->checked_out_at->format('H:i'));
        $this->assertSame(GuestVisitStatus::Closed, $unchanged->status);

        $correction = AuditLog::query()
            ->where('action', AuditAction::GuestVisitCorrected->value)
            ->sole();

        $this->assertSame($visit->getKey(), $correction->subject_id);
        $this->assertSame($this->warden->getKey(), $correction->user_id);

        // And the export shows the two side by side, which is what a numbered
        // paper journal gives and an editable row does not.
        $row = $this->getJson(
            "/api/v1/buildings/{$this->building->id}/visit-register?from=2026-09-01&until=2026-09-30"
        )->assertOk()->json('data.0');

        $this->assertCount(1, $row['corrections']);
        $this->assertStringContainsString('about 16:25', $row['corrections'][0]['correction']);
    }

    public function test_a_correction_without_text_is_refused(): void
    {
        $visit = $this->visitOn('2026-09-10', '13:15:00', '16:40:00', 'Ostap Verigin');

        Sanctum::actingAs($this->warden);

        $this->postJson("/api/v1/guest-visits/{$visit->id}/correction", ['correction' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('correction');
    }

    /**
     * The post records the exit; it does not correct the register. An officer
     * who mistyped a time reports it, and the correcting entry carries the
     * name of whoever wrote it.
     */
    public function test_the_security_post_does_not_write_corrections(): void
    {
        $visit = $this->visitOn('2026-09-10', '13:15:00', '16:40:00', 'Ostap Verigin');

        Sanctum::actingAs($this->guard);

        $this->postJson("/api/v1/guest-visits/{$visit->id}/correction", ['correction' => 'Mistyped.'])
            ->assertStatus(403);
    }

    /**
     * The verification asks for it directly: «no row is deleted, and
     * checked_out_at, checked_out_by and overdue_notified_at are written
     * exactly once, guarded by the CHECK and by a service assertion».
     */
    public function test_a_second_exit_is_refused_by_the_service_with_a_reason(): void
    {
        $visit = $this->visitOn('2026-09-10', '13:15:00', '16:40:00', 'Ostap Verigin');

        Sanctum::actingAs($this->guard);

        $this->postJson('/api/v1/checkpoint/check-out', ['guest_visit_id' => $visit->getKey()])
            ->assertStatus(409)
            ->assertJsonPath('guest_visit_id', $visit->getKey());
    }

    public function test_the_database_refuses_to_rewrite_an_exit_that_is_already_recorded(): void
    {
        $visit = $this->visitOn('2026-09-10', '13:15:00', '16:40:00', 'Ostap Verigin');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/write-once/');

        DB::table('guest_visits')
            ->where('id', $visit->getKey())
            ->update(['checked_out_at' => '2026-09-10 18:00:00']);
    }

    public function test_the_database_refuses_to_delete_a_visit(): void
    {
        $visit = $this->visitOn('2026-09-10', '13:15:00', '16:40:00', 'Ostap Verigin');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/append-only/');

        DB::table('guest_visits')->where('id', $visit->getKey())->delete();
    }

    public function test_the_model_refuses_to_delete_a_visit(): void
    {
        $visit = $this->visitOn('2026-09-10', '13:15:00', '16:40:00', 'Ostap Verigin');

        $this->expectException(ImmutableRecordException::class);

        $visit->delete();
    }

    /**
     * The half of the register the guarantee used to miss.
     *
     * Three of clause 2.1.2's six fields — the guest, the document, and through
     * the host the premises — are columns of `guest_requests`, and the
     * write-once trigger created with `guest_visits` never covered them. The
     * export then showed a substituted name and said nothing about the
     * substitution, which is the one thing a register may not do.
     */
    public function test_the_database_refuses_to_substitute_the_guest_named_on_a_decided_request(): void
    {
        $visit = $this->visitOn('2026-09-10', '13:15:00', '16:40:00', 'Ostap Verigin');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/write-once/');

        DB::table('guest_requests')
            ->where('id', $visit->guest_request_id)
            ->update(['guest_full_name' => 'Somebody Else']);
    }

    public function test_the_database_refuses_to_move_the_interval_a_visit_was_judged_by(): void
    {
        $visit = $this->visitOn('2026-09-10', '13:15:00', '16:40:00', 'Ostap Verigin');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/write-once/');

        DB::table('guest_requests')
            ->where('id', $visit->guest_request_id)
            ->update(['planned_to' => '23:30:00']);
    }

    /**
     * The decision is evidence in its own right: FR-17's first criterion keeps
     * the deciding user and the moment, and a row that can be re-decided after
     * the fact attributes an approval to whoever edited it last.
     */
    public function test_the_database_refuses_to_re_decide_a_request(): void
    {
        $visit = $this->visitOn('2026-09-10', '13:15:00', '16:40:00', 'Ostap Verigin');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/decision on request/');

        DB::table('guest_requests')
            ->where('id', $visit->guest_request_id)
            ->update(['decided_by' => $this->warden->getKey()]);
    }

    public function test_the_database_refuses_to_delete_a_decided_request(): void
    {
        $visit = $this->visitOn('2026-09-10', '13:15:00', '16:40:00', 'Ostap Verigin');

        // The foreign key holds only while a visit exists; the refusal has to
        // hold for a request the guest never turned up on either.
        $refused = GuestRequest::factory()
            ->forBuilding($this->building)
            ->from($this->resident)
            ->rejected($this->warden)
            ->create(['visit_date' => '2026-09-10']);

        $this->assertSame(0, GuestVisit::query()->where('guest_request_id', $refused->getKey())->count());
        $this->assertNotNull($visit->guest_request_id);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/append-only/');

        DB::table('guest_requests')->where('id', $refused->getKey())->delete();
    }

    public function test_the_model_refuses_to_delete_a_decided_request(): void
    {
        $visit = $this->visitOn('2026-09-10', '13:15:00', '16:40:00', 'Ostap Verigin');

        $this->expectException(ImmutableRecordException::class);

        GuestRequest::query()->findOrFail($visit->guest_request_id)->delete();
    }

    /**
     * The other side of the line, and the reason it is drawn at the decision
     * rather than at the entry: a request nobody has looked at is a form. The
     * resident may still correct a mistyped surname and withdraw it, and the
     * duty officer's decision is itself written onto the row from this state.
     */
    public function test_a_request_nobody_has_decided_on_is_still_a_draft(): void
    {
        $draft = GuestRequest::factory()
            ->forBuilding($this->building)
            ->from($this->resident)
            ->create([
                'guest_full_name' => 'Ostap Vergin',
                'visit_date' => '2026-09-20',
                'status' => GuestRequestStatus::PendingReview,
            ]);

        DB::table('guest_requests')
            ->where('id', $draft->getKey())
            ->update(['guest_full_name' => 'Ostap Verigin']);

        $this->assertSame('Ostap Verigin', $draft->fresh()->guest_full_name);

        $this->assertSame(1, DB::table('guest_requests')->where('id', $draft->getKey())->delete());
    }

    /**
     * §2.4.2, second scenario. The note is the written ground on which the
     * officer admitted a guest against the interval, and the only evidence
     * that the exception was reasoned at all — the CHECK refuses an override
     * without one. It was left out of the visit's own trigger, so it could be
     * rewritten afterwards into any ground at all.
     */
    public function test_the_database_refuses_to_rewrite_the_ground_a_guest_was_admitted_on(): void
    {
        $visit = $this->admittedOnDecision('The duty officer authorised the entry by telephone.');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/admitted/');

        DB::table('guest_visits')
            ->where('id', $visit->getKey())
            ->update(['admission_note' => 'The guest was within the interval after all.']);
    }

    /**
     * `status` was unguarded, so a closed evening could be set back to
     * `in_building` and the visit would reappear among those inside.
     */
    public function test_the_database_refuses_to_reopen_a_closed_visit(): void
    {
        $visit = $this->visitOn('2026-09-10', '13:15:00', '16:40:00', 'Ostap Verigin');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/cannot move from/');

        DB::table('guest_visits')
            ->where('id', $visit->getKey())
            ->update(['status' => GuestVisitStatus::InBuilding->value]);
    }

    private function admittedOnDecision(string $note): GuestVisit
    {
        $request = GuestRequest::factory()
            ->forBuilding($this->building)
            ->from($this->resident)
            ->approved($this->warden)
            ->create([
                'guest_full_name' => 'Ostap Verigin',
                'visit_date' => '2026-09-10',
                'planned_from' => '12:00:00',
                'planned_to' => '22:00:00',
                'status' => GuestRequestStatus::InProgress,
            ]);

        return GuestVisit::factory()
            ->forRequest($request, $this->guard)
            ->enteredAt('2026-09-10 23:40:00')
            ->create([
                'due_at' => $request->dueAt($this->building),
                'admitted_on_decision' => true,
                'admission_note' => $note,
            ]);
    }

    private function visitOn(string $date, string $in, string $out, string $guest): GuestVisit
    {
        $request = GuestRequest::factory()
            ->forBuilding($this->building)
            ->from($this->resident)
            ->approved()
            ->create([
                'guest_full_name' => $guest,
                'visit_date' => $date,
                'planned_from' => '12:00:00',
                'planned_to' => '22:00:00',
                'status' => GuestRequestStatus::Completed,
            ]);

        return GuestVisit::factory()
            ->forRequest($request, $this->guard)
            ->enteredAt($date.' '.$in)
            ->closed($this->guard, $date.' '.$out)
            ->create(['due_at' => $request->dueAt($this->building)]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Guests;

use App\Enums\AuditAction;
use App\Enums\GuestDocumentType;
use App\Enums\GuestRequestStatus;
use App\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\GuestRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsAGuestScenario;
use Tests\TestCase;

/**
 * FR-16, «Guest request submission», one test per acceptance criterion, and
 * FR-23's first criterion, which is decided at the same moment.
 *
 * The clock is pinned throughout. Every rule under test is a comparison
 * between a stated interval and the present moment, and a suite that ran at
 * 23:50 would otherwise disagree with the same suite run at noon — which is
 * the kind of failure that gets a test deleted rather than read.
 */
final class GuestRequestSubmissionTest extends TestCase
{
    use BuildsAGuestScenario, RefreshDatabase;

    private Building $building;

    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        // Half past nine in the morning: inside clause 2.2's window, with
        // room on either side of it for an interval to fall outside.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 09:30:00'));

        $this->building = $this->dormitory('Block A');
        $this->resident = $this->residentOf($this->building, 'resident@example.test');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * Third criterion: «the submission carries guest name, document type and
     * number, visit date, time interval and purpose».
     */
    public function test_the_submission_carries_the_guest_the_document_the_date_the_interval_and_the_purpose(): void
    {
        Sanctum::actingAs($this->resident);

        $response = $this->postJson('/api/v1/guest-requests', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.guest_full_name', 'Ostap Verigin')
            ->assertJsonPath('data.guest_doc_type', GuestDocumentType::InternalPassport->value)
            ->assertJsonPath('data.visit_date', '2026-09-14')
            ->assertJsonPath('data.planned_from', '14:00')
            ->assertJsonPath('data.planned_to', '18:00')
            ->assertJsonPath('data.purpose', 'A study group')
            ->assertJsonPath('data.status', GuestRequestStatus::PendingReview->value);

        $stored = GuestRequest::query()->findOrFail($response->json('data.id'));

        $this->assertSame('4509 123456', $stored->guest_doc_number);
        $this->assertSame($this->resident->getKey(), $stored->student_id);

        // NFR-06: the number is stored encrypted and shown masked, and there
        // is no path by which the response could carry it in the clear.
        // The mask keeps the length and the last four characters and hides
        // everything else, the separating space included — a mask that let the
        // shape of the number through would leak which document it is.
        $this->assertSame('•••••••3456', $response->json('data.guest_doc_number_masked'));
        $this->assertArrayNotHasKey('guest_doc_number', $response->json('data'));

        $this->assertNotSame(
            '4509 123456',
            AuditLog::query()->where('action', AuditAction::GuestRequestSubmitted->value)->sole()->payload['guest_doc_type'] ?? null,
        );
    }

    /**
     * Second criterion: «the time interval does not exceed the permitted
     * visiting window».
     */
    public function test_an_interval_outside_the_visiting_window_of_the_building_is_refused(): void
    {
        Sanctum::actingAs($this->resident);

        $this->postJson('/api/v1/guest-requests', $this->payload([
            'planned_from' => '06:00',
            'planned_to' => '09:00',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('planned_to');

        $this->assertSame(0, GuestRequest::query()->count());
    }

    /**
     * The same criterion from the other side, and the whole of NFR-09: the
     * window is a row and not a constant, so moving the row moves the rule.
     */
    public function test_changing_the_visiting_window_on_the_building_row_changes_what_is_accepted(): void
    {
        Sanctum::actingAs($this->resident);

        // 07:00–09:00 is outside clause 2.2's 08:00–23:00 …
        $this->postJson('/api/v1/guest-requests', $this->payload([
            'visit_date' => '2026-09-15',
            'planned_from' => '07:00',
            'planned_to' => '09:00',
        ]))->assertStatus(422);

        // … and inside the window of a dormitory that opens at six. No code
        // has changed between the two calls; one column has.
        $this->building->update(['visiting_from' => '06:00:00']);

        $this->postJson('/api/v1/guest-requests', $this->payload([
            'visit_date' => '2026-09-15',
            'planned_from' => '07:00',
            'planned_to' => '09:00',
        ]))->assertStatus(201);
    }

    public function test_an_interval_that_ends_before_it_begins_is_refused(): void
    {
        Sanctum::actingAs($this->resident);

        $this->postJson('/api/v1/guest-requests', $this->payload([
            'planned_from' => '18:00',
            'planned_to' => '14:00',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('planned_to');
    }

    public function test_a_visit_date_in_the_past_is_refused(): void
    {
        Sanctum::actingAs($this->resident);

        $this->postJson('/api/v1/guest-requests', $this->payload([
            'visit_date' => '2026-09-13',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('visit_date');
    }

    /**
     * First criterion: «the request is submitted no later than the lead time
     * configured for the building».
     *
     * Configured for the building, so the test configures it on the building.
     * The default is zero — the HSE rules of internal order ask for no notice
     * — and zero has to keep meaning «none», which the second half asserts.
     */
    public function test_a_request_inside_the_lead_time_the_building_asks_for_is_refused(): void
    {
        Sanctum::actingAs($this->resident);

        $this->building->update(['guest_lead_time_hours' => 24]);

        $this->postJson('/api/v1/guest-requests', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('visit_date');

        $this->postJson('/api/v1/guest-requests', $this->payload([
            'visit_date' => '2026-09-16',
        ]))->assertStatus(201);
    }

    public function test_a_dormitory_that_asks_for_no_notice_accepts_a_visit_later_the_same_day(): void
    {
        Sanctum::actingAs($this->resident);

        $this->assertSame(0, $this->building->guest_lead_time_hours);

        $this->postJson('/api/v1/guest-requests', $this->payload())->assertStatus(201);
    }

    /**
     * FR-16's verification asks for this one by name: «GuestRequestPolicy::create
     * refuses a user without an active residency in that building».
     */
    public function test_a_person_who_does_not_live_in_the_dormitory_may_not_invite_a_guest_into_it(): void
    {
        $other = $this->dormitory('Block B');
        $strangerHere = $this->residentOf($other, 'stranger@example.test', '101');

        Sanctum::actingAs($strangerHere);

        $this->postJson('/api/v1/guest-requests', $this->payload())->assertStatus(403);

        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::AccessDenied->value]);
    }

    public function test_a_member_of_staff_with_no_residency_may_not_invite_a_guest(): void
    {
        Sanctum::actingAs($this->staff(RoleCode::DutyOfficer, $this->building, 'duty@example.test'));

        $this->postJson('/api/v1/guest-requests', $this->payload())->assertStatus(403);
    }

    /**
     * FR-23, first criterion: «selecting document type "foreign passport"
     * flags the request and attaches the warning about the procedure in force
     * at the university».
     */
    public function test_a_foreign_passport_flags_the_request_and_attaches_the_warning(): void
    {
        Sanctum::actingAs($this->resident);

        $response = $this->postJson('/api/v1/guest-requests', $this->payload([
            'guest_doc_type' => GuestDocumentType::ForeignPassport->value,
            'guest_doc_number' => 'AB1234567',
        ]))
            ->assertStatus(201)
            ->assertJsonPath('data.is_foreign_document', true);

        $warning = (string) $response->json('data.foreign_guest_warning');

        $this->assertStringContainsString('109-FZ', $warning);
        $this->assertStringContainsString(
            'The system does not submit that notification',
            $warning,
            'FR-W2 keeps the migration notification outside the perimeter, and the warning has to say so.',
        );
    }

    public function test_an_internal_passport_carries_no_foreign_flag_and_no_warning(): void
    {
        Sanctum::actingAs($this->resident);

        $this->postJson('/api/v1/guest-requests', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.is_foreign_document', false)
            ->assertJsonPath('data.foreign_guest_warning', null);
    }

    /**
     * The flag is derived from the document type and never accepted from the
     * client — otherwise the warning of FR-23 would be attached at the
     * client's discretion.
     */
    public function test_the_foreign_flag_cannot_be_set_or_cleared_from_the_request_body(): void
    {
        Sanctum::actingAs($this->resident);

        $response = $this->postJson('/api/v1/guest-requests', $this->payload([
            'guest_doc_type' => GuestDocumentType::ForeignPassport->value,
            'guest_doc_number' => 'AB1234567',
            'is_foreign_document' => false,
        ]))->assertStatus(201);

        $this->assertTrue(GuestRequest::query()->findOrFail($response->json('data.id'))->is_foreign_document);
    }

    public function test_a_request_needs_a_session(): void
    {
        $this->postJson('/api/v1/guest-requests', $this->payload())->assertStatus(401);
    }

    public function test_the_submission_is_recorded_in_the_audit_log(): void
    {
        Sanctum::actingAs($this->resident);

        $response = $this->postJson('/api/v1/guest-requests', $this->payload())->assertStatus(201);

        $entry = AuditLog::query()
            ->where('action', AuditAction::GuestRequestSubmitted->value)
            ->sole();

        $this->assertSame($this->resident->getKey(), $entry->user_id);
        $this->assertSame($response->json('data.id'), $entry->subject_id);
        $this->assertSame($this->building->getKey(), $entry->payload['building_id']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'building_id' => $this->building->getKey(),
            'guest_full_name' => 'Ostap Verigin',
            'guest_doc_type' => GuestDocumentType::InternalPassport->value,
            'guest_doc_number' => '4509 123456',
            'visit_date' => '2026-09-14',
            'planned_from' => '14:00',
            'planned_to' => '18:00',
            'purpose' => 'A study group',
        ];
    }
}

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
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsAGuestScenario;
use Tests\TestCase;

/**
 * FR-16, «Guest request submission», one test per acceptance criterion.
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
     * Third criterion: «the submission carries the guest's name, the visit
     * date and the time interval». The document and the purpose of the visit
     * were dropped with the MVP: the paper stays in the officer's hand.
     */
    public function test_the_submission_carries_the_guest_the_date_and_the_interval(): void
    {
        Sanctum::actingAs($this->resident);

        $response = $this->postJson('/api/v1/guest-requests', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.guest_full_name', 'Ostap Verigin')
            ->assertJsonPath('data.visit_date', '2026-09-14')
            ->assertJsonPath('data.planned_from', '14:00')
            ->assertJsonPath('data.planned_to', '18:00')
            ->assertJsonPath('data.status', GuestRequestStatus::PendingReview->value);

        $stored = GuestRequest::query()->findOrFail($response->json('data.id'));

        $this->assertSame($this->resident->getKey(), $stored->student_id);

        $body = $response->json('data');

        foreach (['guest_doc_type', 'guest_doc_number', 'guest_doc_number_masked', 'purpose'] as $gone) {
            $this->assertArrayNotHasKey($gone, $body);
        }
    }

    /**
     * The table carries no document of the guest, and the schema says so.
     *
     * **The acceptance finding of 15.09.2026, and the reason it is a schema
     * test rather than another submission test.** Withdrawing FR-23 stopped
     * the application filling in `guest_doc_type` and the four columns beside
     * it; the migration that drops them is `2026_09_14_180000`. On a stand
     * where that migration had not been run the columns were still there and
     * still NOT NULL, so **every** guest request answered 500 with
     * `SQLSTATE[23502]` and the module could not be used at all — while the
     * submission tests, which run against a freshly migrated database, passed
     * throughout.
     *
     * A test of the shape of the table is the one that would have caught it:
     * it fails wherever the schema and the code disagree, whether the cause is
     * a column somebody forgot to drop or a migration somebody forgot to run.
     */
    public function test_the_register_keeps_no_document_of_the_guest(): void
    {
        $withdrawn = [
            'guest_doc_type',
            'guest_doc_number',
            'is_foreign_document',
            'purpose',
            'responsible_officer_mark',
            'responsible_officer_mark_by',
            'responsible_officer_mark_at',
        ];

        foreach ($withdrawn as $column) {
            $this->assertFalse(
                Schema::hasColumn('guest_requests', $column),
                sprintf('guest_requests still carries «%s», which nothing fills in any more.', $column),
            );

            $this->assertFalse(
                Schema::hasColumn('guest_visits', $column),
                sprintf('guest_visits carries «%s», which belongs to no requirement of the MVP.', $column),
            );
        }

        // And the register still takes a request, which is the half of the
        // statement a schema assertion cannot make on its own.
        Sanctum::actingAs($this->resident);

        $this->postJson('/api/v1/guest-requests', $this->payload())->assertStatus(201);
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

    /**
     * The same criterion, configured the way an administrator has to configure
     * it: over the API rather than with a direct write to the column.
     *
     * The setting existed and could not be reached — it was in neither the
     * form rules of `PATCH /buildings/{id}` nor `BuildingResource` nor the
     * contract — so the validator above was reading a column nobody could
     * change. NFR-09 asks for a setting, and a column only psql can move is
     * not one.
     */
    public function test_the_lead_time_set_over_the_api_is_the_one_the_submission_is_judged_by(): void
    {
        Sanctum::actingAs($this->staff(RoleCode::Administrator, null, 'admin@example.test'));

        $this->patchJson("/api/v1/buildings/{$this->building->id}", ['guest_lead_time_hours' => 24])
            ->assertOk()
            ->assertJsonPath('data.guest_lead_time_hours', 24);

        Sanctum::actingAs($this->resident);

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
        Sanctum::actingAs($this->staff(RoleCode::Manager, $this->building, 'manager@example.test'));

        $this->postJson('/api/v1/guest-requests', $this->payload())->assertStatus(403);
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
            'visit_date' => '2026-09-14',
            'planned_from' => '14:00',
            'planned_to' => '18:00',
        ];
    }
}

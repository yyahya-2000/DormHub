<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Enums\MaintenanceCategory;
use App\Enums\MaintenanceLocation;
use App\Enums\MaintenanceRequestStatus;
use App\Enums\MaintenanceUrgency;
use App\Enums\RoleCode;
use App\Models\Building;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Notifications\MaintenanceRequestFiled;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsAMaintenanceScenario;
use Tests\TestCase;

/**
 * FR-36, «Submitting a maintenance request», one test per acceptance
 * criterion, and the first Gherkin scenario of §2.4.3 carried over verbatim.
 *
 * The clock is pinned throughout: the first scenario asserts «the current
 * timestamp» and a suite that ran across midnight would otherwise disagree
 * with itself about which day that was.
 */
final class MaintenanceSubmissionTest extends TestCase
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
        Storage::fake('local');

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
     * §2.4.3, first scenario, word for word:
     *
     *   Given a resident with an active residency record in room 412
     *   When the resident submits a request with category "plumbing",
     *        location "own room", a description and one photograph
     *   Then the request is created in status "submitted" with the current
     *        timestamp, bound to room 412 and to the building of that
     *        residency record
     *    And the warden of that building receives a notification
     */
    public function test_a_resident_of_room_412_files_a_plumbing_request_and_the_warden_of_that_building_is_notified(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->resident);

        $response = $this->post('/api/v1/maintenance-requests', $this->submission($this->building, [
            'category' => MaintenanceCategory::Plumbing->value,
            'location' => MaintenanceLocation::OwnRoom->value,
            'photos' => [$this->photograph()],
        ]))->assertStatus(201);

        $filed = MaintenanceRequest::query()->findOrFail($response->json('data.id'));

        $this->assertSame(MaintenanceRequestStatus::Submitted, $filed->status);
        $this->assertSame(CarbonImmutable::now()->toIso8601String(), $filed->created_at?->toIso8601String());

        // «bound to room 412 and to the building of that residency record» —
        // and the room comes from the register, because the body never named
        // one.
        $this->assertSame($this->roomOf($this->resident)->getKey(), $filed->room_id);
        $this->assertSame('412', $response->json('data.room_number'));
        $this->assertSame($this->building->getKey(), $filed->building_id);

        $this->assertCount(1, $filed->photoPaths());

        Notification::assertSentTo($this->warden, MaintenanceRequestFiled::class);
        Notification::assertNotSentTo($this->resident, MaintenanceRequestFiled::class);
    }

    /**
     * FR-36, second criterion, and the reason the check is where it is: «the
     * residency check sits in the Policy, so the negative case is a policy
     * test, not a form test». A body that is perfectly well formed, and the
     * answer is 403 rather than 422 — nothing about the input is wrong.
     */
    public function test_a_resident_without_an_active_residency_record_cannot_submit(): void
    {
        $stranger = $this->staff(RoleCode::Resident, $this->building, 'moved-out@example.test');

        Sanctum::actingAs($stranger);

        $this->post('/api/v1/maintenance-requests', $this->submission($this->building))
            ->assertStatus(403);

        $this->assertSame(0, MaintenanceRequest::query()->count());
    }

    /**
     * The same criterion from the other side: a resident of block A may not
     * report a defect in block B, because they do not live there. The register
     * answers, not the role.
     */
    public function test_a_resident_cannot_file_against_a_dormitory_they_do_not_live_in(): void
    {
        $other = $this->dormitory('Block B');

        Sanctum::actingAs($this->resident);

        $this->post('/api/v1/maintenance-requests', $this->submission($other))
            ->assertStatus(403);
    }

    /**
     * FR-36, third criterion: «the submission carries category, location,
     * description, urgency and up to three photographs».
     */
    public function test_the_submission_carries_category_location_description_urgency_and_photographs(): void
    {
        Sanctum::actingAs($this->resident);

        $response = $this->post('/api/v1/maintenance-requests', $this->submission($this->building, [
            'category' => MaintenanceCategory::Heating->value,
            'urgency' => MaintenanceUrgency::Emergency->value,
            'photos' => [$this->photograph('a.png'), $this->photograph('b.png'), $this->photograph('c.png')],
        ]))
            ->assertStatus(201)
            ->assertJsonPath('data.category', MaintenanceCategory::Heating->value)
            ->assertJsonPath('data.location', MaintenanceLocation::OwnRoom->value)
            ->assertJsonPath('data.urgency', MaintenanceUrgency::Emergency->value)
            ->assertJsonPath('data.photo_count', 3)
            ->assertJsonPath('data.status', MaintenanceRequestStatus::Submitted->value);

        $this->assertSame(
            'The mixer tap in the washbasin drips and the washer does not hold any more.',
            $response->json('data.description'),
        );

        foreach ($response->json('data.photo_paths') as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }

    /**
     * «Up to three» read as a ceiling rather than as a suggestion. A fourth
     * photograph is a 422 naming the field, and the request is not written.
     */
    public function test_a_fourth_photograph_is_refused(): void
    {
        Sanctum::actingAs($this->resident);

        $this->post('/api/v1/maintenance-requests', $this->submission($this->building, [
            'photos' => [
                $this->photograph('a.png'),
                $this->photograph('b.png'),
                $this->photograph('c.png'),
                $this->photograph('d.png'),
            ],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('photos');

        $this->assertSame(0, MaintenanceRequest::query()->count());
    }

    /**
     * The ceiling is configuration and the database enforces the same figure,
     * so a deployment that lowered it would be refused by the CHECK as well as
     * by the rule. Here the rule is what answers, and it reads the setting.
     */
    public function test_the_ceiling_on_photographs_is_read_from_configuration(): void
    {
        config(['dormitory.maintenance.max_photos' => 1]);

        Sanctum::actingAs($this->resident);

        $this->post('/api/v1/maintenance-requests', $this->submission($this->building, [
            'photos' => [$this->photograph('a.png'), $this->photograph('b.png')],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('photos');
    }

    /**
     * FR-36's other location: a common area is named in words and binds to no
     * room, because the register holds rooms and beds and knows nothing about
     * the shower block on the third floor.
     */
    public function test_a_common_area_request_names_the_place_and_binds_to_no_room(): void
    {
        Sanctum::actingAs($this->resident);

        $this->post('/api/v1/maintenance-requests', $this->submission($this->building, [
            'location' => MaintenanceLocation::CommonArea->value,
            'location_note' => 'The shower block on the third floor',
        ]))
            ->assertStatus(201)
            ->assertJsonPath('data.room_id', null)
            ->assertJsonPath('data.place', 'The shower block on the third floor');
    }

    public function test_a_common_area_without_a_name_is_refused(): void
    {
        Sanctum::actingAs($this->resident);

        $this->post('/api/v1/maintenance-requests', $this->submission($this->building, [
            'location' => MaintenanceLocation::CommonArea->value,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_note');
    }

    /**
     * §3.4.1, decision 6: the history begins with the submission. The first
     * row has no `from_status`, because the request did not move — it came
     * into being.
     */
    public function test_the_submission_opens_the_work_log_with_a_row_that_has_no_earlier_state(): void
    {
        Sanctum::actingAs($this->resident);

        $response = $this->post('/api/v1/maintenance-requests', $this->submission($this->building))
            ->assertStatus(201);

        $filed = MaintenanceRequest::query()->findOrFail($response->json('data.id'));

        $this->assertSame([[
            'from' => null,
            'to' => MaintenanceRequestStatus::Submitted->value,
            'actor' => $this->resident->getKey(),
            'comment' => 'The mixer tap in the washbasin drips and the washer does not hold any more.',
        ]], $this->workLogOf($filed));
    }

    public function test_filing_a_request_needs_a_session(): void
    {
        $this->postJson('/api/v1/maintenance-requests', $this->submission($this->building))
            ->assertStatus(401);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Enums\AuditAction;
use App\Enums\MaintenanceCategory;
use App\Enums\MaintenanceUrgency;
use App\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Notifications\MaintenanceOverdueDigest;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsAMaintenanceScenario;
use Tests\TestCase;

/**
 * FR-40, «Building maintenance queue»: the two lists, the page, the scoping,
 * and the threshold that is configuration rather than code.
 *
 * The scoping test is the module's row in the horizontal-access matrix of
 * §4.7.2, and it is asserted from both ends — the warden of block 1 gets 403
 * on block 2's queue, and block 2's requests do not appear in block 1's
 * answer. Either assertion alone would pass against a defect the other one
 * catches.
 */
final class MaintenanceQueueTest extends TestCase
{
    use BuildsAMaintenanceScenario, RefreshDatabase;

    private Building $building;

    private Building $other;

    private User $resident;

    private User $warden;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 09:30:00'));

        $this->building = $this->dormitory('Block A');
        $this->other = $this->dormitory('Block B');
        $this->resident = $this->residentInRoom412($this->building);
        $this->warden = $this->staff(RoleCode::Warden, $this->building, 'warden@example.test');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * FR-40, fourth criterion: «the warden sees all open requests for their
     * own building with age since submission, category and urgency».
     */
    public function test_the_warden_sees_every_open_request_of_their_building_with_its_age_category_and_urgency(): void
    {
        // Age and urgency point opposite ways on purpose: sorted by age alone
        // the three come back in exactly the reverse order, so the assertion
        // below fails the moment the urgency key is dropped.
        $this->request(daysAgo: 1, category: MaintenanceCategory::Heating, urgency: MaintenanceUrgency::Emergency);
        $this->request(daysAgo: 3, category: MaintenanceCategory::Electrical, urgency: MaintenanceUrgency::Urgent);
        $this->request(daysAgo: 6, category: MaintenanceCategory::Plumbing);
        // Closed, so outside «all open requests».
        MaintenanceRequest::factory()
            ->forBuilding($this->building)
            ->from($this->resident)
            ->confirmed()
            ->create();

        Sanctum::actingAs($this->warden);

        $response = $this->getJson("/api/v1/buildings/{$this->building->id}/maintenance-queue")
            ->assertOk()
            ->assertJsonCount(3, 'data');

        // Ordered by urgency first — the order a queue is worked in, and an
        // emergency reported this morning is worked before a wobbly chair
        // reported last week.
        $response
            ->assertJsonPath('data.0.urgency', MaintenanceUrgency::Emergency->label())
            ->assertJsonPath('data.0.age_days', 1)
            ->assertJsonPath('data.0.category', MaintenanceCategory::Heating->label())
            ->assertJsonPath('data.1.urgency', MaintenanceUrgency::Urgent->label())
            ->assertJsonPath('data.1.age_days', 3)
            ->assertJsonPath('data.1.category', MaintenanceCategory::Electrical->label())
            ->assertJsonPath('data.2.urgency', MaintenanceUrgency::Routine->label())
            ->assertJsonPath('data.2.age_days', 6)
            ->assertJsonPath('data.2.category', MaintenanceCategory::Plumbing->label());
    }

    /**
     * The tie-break beneath the urgency: inside one urgency the oldest is
     * worked first. Asserted apart from the test above, whose data is chosen
     * so that the urgency key alone decides it.
     */
    public function test_inside_one_urgency_the_oldest_request_comes_first(): void
    {
        $older = $this->request(daysAgo: 5, urgency: MaintenanceUrgency::Urgent);
        $newer = $this->request(daysAgo: 2, urgency: MaintenanceUrgency::Urgent);

        Sanctum::actingAs($this->warden);

        $this->getJson("/api/v1/buildings/{$this->building->id}/maintenance-queue")
            ->assertOk()
            ->assertJsonPath('data.0.id', $older->id)
            ->assertJsonPath('data.1.id', $newer->id);
    }

    /**
     * The archive, which is the other half of the same route: the finished
     * requests, and only those. A request is in exactly one of the two lists,
     * so the warden cannot lose one between them.
     */
    public function test_the_archive_holds_the_finished_requests_and_the_queue_holds_the_rest(): void
    {
        $open = $this->request(daysAgo: 2);

        $closed = MaintenanceRequest::factory()
            ->forBuilding($this->building)
            ->from($this->resident)
            ->confirmed()
            ->create();

        $rejected = MaintenanceRequest::factory()
            ->forBuilding($this->building)
            ->from($this->resident)
            ->rejected()
            ->create();

        Sanctum::actingAs($this->warden);

        $base = "/api/v1/buildings/{$this->building->id}/maintenance-queue";

        $this->getJson($base)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $open->id);

        $archived = $this->getJson($base.'?scope=archive')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertEqualsCanonicalizing(
            [$closed->id, $rejected->id],
            collect($archived->json('data'))->pluck('id')->all(),
        );
    }

    /**
     * The page, which is the only parameter beside the scope: a dormitory
     * accumulates thousands of requests over an academic year and none of the
     * screens asks for all of them.
     */
    public function test_the_queue_is_read_a_page_at_a_time(): void
    {
        foreach (range(1, 5) as $daysAgo) {
            $this->request(daysAgo: $daysAgo);
        }

        Sanctum::actingAs($this->warden);

        $base = "/api/v1/buildings/{$this->building->id}/maintenance-queue";

        $first = $this->getJson($base.'?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.total', 5);

        $second = $this->getJson($base.'?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 2);

        // The two pages are two different slices of one ordering.
        $this->assertEmpty(array_intersect(
            collect($first->json('data'))->pluck('id')->all(),
            collect($second->json('data'))->pluck('id')->all(),
        ));

        // And a client asking for the whole table gets the ceiling instead.
        $this->getJson($base.'?per_page=5000')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    /**
     * The horizontal-access matrix of §4.7.2: a warden of building 1 sees no
     * request of building 2, asserted from both ends.
     */
    public function test_a_warden_of_building_1_sees_no_request_of_building_2(): void
    {
        $mine = $this->request(daysAgo: 1);

        $neighbour = $this->residentOf($this->other, 'b-resident@example.test', '101');
        MaintenanceRequest::factory()
            ->forBuilding($this->other)
            ->from($neighbour)
            ->create();

        Sanctum::actingAs($this->warden);

        $this->getJson("/api/v1/buildings/{$this->building->id}/maintenance-queue")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        $this->getJson("/api/v1/buildings/{$this->other->id}/maintenance-queue")
            ->assertStatus(403);
    }

    /**
     * The resident's own list is a different route with no parameter by which
     * one person could name another — the boundary is the missing parameter
     * rather than a check somebody has to remember.
     */
    public function test_a_resident_reads_their_own_requests_and_not_the_queue(): void
    {
        $mine = $this->request(daysAgo: 1);

        $neighbour = $this->residentOf($this->building, 'neighbour@example.test', '413');
        MaintenanceRequest::factory()
            ->forBuilding($this->building)
            ->from($neighbour)
            ->create();

        Sanctum::actingAs($this->resident);

        $this->getJson('/api/v1/maintenance-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        $this->getJson("/api/v1/buildings/{$this->building->id}/maintenance-queue")
            ->assertStatus(403);
    }

    /**
     * FR-40, second criterion: «the overdue threshold is configuration, not
     * code». Changing the setting changes which requests are flagged, with no
     * code edit — asserted on the scheduled pass, which is where the flag is
     * acted on.
     */
    public function test_changing_the_overdue_threshold_in_configuration_changes_which_requests_are_flagged(): void
    {
        Notification::fake();

        $request = $this->request(daysAgo: 5);

        config(['dormitory.maintenance.overdue_after_days' => 10]);
        $this->artisan('maintenance:scan-overdue')->assertSuccessful();

        $this->assertSame(0, AuditLog::query()
            ->where('action', AuditAction::MaintenanceRequestOverdue->value)
            ->count());

        config(['dormitory.maintenance.overdue_after_days' => 3]);
        $this->artisan('maintenance:scan-overdue')->assertSuccessful();

        $this->assertNotNull(AuditLog::query()
            ->where('action', AuditAction::MaintenanceRequestOverdue->value)
            ->where('subject_id', $request->id)
            ->first());

        Notification::assertSentTo($this->warden, MaintenanceOverdueDigest::class);
    }

    /**
     * The same setting read by the queue and by the card, so the number the
     * warden sees and the number the nightly pass acts on are one number.
     */
    public function test_the_same_threshold_decides_the_flag_on_the_queue(): void
    {
        $this->request(daysAgo: 5);

        Sanctum::actingAs($this->warden);

        config(['dormitory.maintenance.overdue_after_days' => 10]);
        $this->getJson("/api/v1/buildings/{$this->building->id}/maintenance-queue")
            ->assertOk()
            ->assertJsonPath('data.0.overdue', false);

        config(['dormitory.maintenance.overdue_after_days' => 3]);
        $this->getJson("/api/v1/buildings/{$this->building->id}/maintenance-queue")
            ->assertOk()
            ->assertJsonPath('data.0.overdue', true);
    }

    /**
     * The other way of being late, which the threshold cannot express: a
     * request whose planned completion date has passed has broken a promise
     * made to a named resident, however young it is.
     */
    public function test_a_request_past_its_planned_date_is_overdue_however_young_it_is(): void
    {
        config(['dormitory.maintenance.overdue_after_days' => 30]);

        MaintenanceRequest::factory()
            ->forBuilding($this->building)
            ->from($this->resident)
            ->accepted(targetDate: CarbonImmutable::now()->subDay()->toDateString())
            ->create();

        Sanctum::actingAs($this->warden);

        $this->getJson("/api/v1/buildings/{$this->building->id}/maintenance-queue")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.overdue', true);
    }

    /**
     * The acceptance finding of 15.09.2026: the nightly pass chased work that
     * had already been done.
     *
     * `scopeOpen()` is every status short of a final one and `completed` is
     * one of them — the work is reported done and the reporter has a week to
     * confirm it (FR-39). A planned date falling inside that week made the
     * request overdue, so the digest told the warden he was late with a repair
     * he had finished and was waiting to have signed off. §3.5.2 draws the
     * selection as «past target_date and **not done**», and §4.5 indexes it as
     * `completed_at IS NULL`.
     *
     * The request stays in the queue all the same — it is still his until the
     * resident answers — which is why the two questions are two scopes.
     */
    public function test_work_reported_done_is_not_chased_as_overdue(): void
    {
        Notification::fake();

        $done = MaintenanceRequest::factory()
            ->forBuilding($this->building)
            ->from($this->resident)
            ->completed(daysAgo: 1)
            ->create(['target_date' => CarbonImmutable::now()->subDays(2)->toDateString()]);

        $this->artisan('maintenance:scan-overdue')->assertSuccessful();

        $this->assertSame(
            0,
            AuditLog::query()
                ->where('action', AuditAction::MaintenanceRequestOverdue->value)
                ->where('subject_id', $done->id)
                ->count(),
            'A request whose work is done was flagged overdue by the nightly pass.',
        );

        Notification::assertNothingSent();

        // The same answer on the screen, and the request is still in the queue
        // — it is finished with only when the reporter says so.
        Sanctum::actingAs($this->warden);

        $this->getJson("/api/v1/buildings/{$this->building->id}/maintenance-queue")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $done->id)
            ->assertJsonPath('data.0.overdue', false);
    }

    /**
     * The mirror of it: a request reopened out of `completed` is chased again,
     * because reopening clears `completed_at` and the work is once more
     * outstanding.
     */
    public function test_a_reopened_request_is_chased_again(): void
    {
        Notification::fake();

        $reopened = MaintenanceRequest::factory()
            ->forBuilding($this->building)
            ->from($this->resident)
            ->completed(daysAgo: 1)
            ->create(['target_date' => CarbonImmutable::now()->subDays(2)->toDateString()]);

        Sanctum::actingAs($this->resident);

        $this->postJson("/api/v1/maintenance-requests/{$reopened->id}/reopening", [
            'comment' => 'The tap drips again as soon as it is turned off.',
        ])->assertOk();

        $this->artisan('maintenance:scan-overdue')->assertSuccessful();

        $this->assertNotNull(AuditLog::query()
            ->where('action', AuditAction::MaintenanceRequestOverdue->value)
            ->where('subject_id', $reopened->id)
            ->first());
    }

    /**
     * The archive is read newest first, and the tie-break follows the sort it
     * breaks.
     *
     * The acceptance of 15.09.2026: the archive ordered `created_at`
     * descending and then broke ties by `id` **ascending**, so two requests
     * filed in the same second came back against the order of everything else
     * on the page — and a tie-break pointing the wrong way is a page boundary
     * that moves between one request and the next.
     */
    public function test_the_archive_breaks_a_tie_in_the_direction_it_sorts(): void
    {
        $filedAt = CarbonImmutable::now()->subDays(4);

        $first = MaintenanceRequest::factory()
            ->forBuilding($this->building)
            ->from($this->resident)
            ->confirmed()
            ->create(['created_at' => $filedAt, 'updated_at' => $filedAt]);

        $second = MaintenanceRequest::factory()
            ->forBuilding($this->building)
            ->from($this->resident)
            ->confirmed()
            ->create(['created_at' => $filedAt, 'updated_at' => $filedAt]);

        Sanctum::actingAs($this->warden);

        $this->getJson("/api/v1/buildings/{$this->building->id}/maintenance-queue?scope=archive")
            ->assertOk()
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $first->id);
    }

    private function request(
        int $daysAgo = 0,
        MaintenanceCategory $category = MaintenanceCategory::Plumbing,
        MaintenanceUrgency $urgency = MaintenanceUrgency::Routine,
    ): MaintenanceRequest {
        return MaintenanceRequest::factory()
            ->forBuilding($this->building)
            ->from($this->resident)
            ->ofCategory($category)
            ->withUrgency($urgency)
            ->submittedDaysAgo($daysAgo)
            ->create();
    }
}

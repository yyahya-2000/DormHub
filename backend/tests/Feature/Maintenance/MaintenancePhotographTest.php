<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Enums\RoleCode;
use App\Models\Building;
use App\Models\MaintenanceRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsAMaintenanceScenario;
use Tests\Support\RefusesEveryWrite;
use Tests\TestCase;

/**
 * FR-36's photographs on the two journeys the acceptance of 15.09.2026 found
 * broken: the one into the store, and the one back out of it.
 *
 * **Into the store.** A refused write used to be silent. The photograph disk
 * pointed at the object store, the bucket did not exist, `'throw' => false`
 * turned every refusal into a `false` and `PhotoStore` dropped the path — so
 * the route answered 201 with `photo_count: 0` and the evidence the warden was
 * supposed to triage on was nowhere. The submission is refused now, and the
 * first test here is the one that would have caught it.
 *
 * **Out of the store.** There was no route at all. The schema of
 * `MaintenanceRequest` has always said that `photo_paths` carries paths rather
 * than URLs because «the client asks for a link when it is about to show the
 * image», and there was nothing to ask: `PhotoStore::temporaryUrl()` was
 * called from nowhere in the application.
 */
final class MaintenancePhotographTest extends TestCase
{
    use BuildsAMaintenanceScenario, RefreshDatabase;

    private Building $building;

    private User $resident;

    private User $warden;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 09:30:00'));
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
     * The acceptance finding itself: a store that will not take the file must
     * not leave the client holding a 201.
     *
     * The disk is one that refuses every write, registered for this test
     * alone; `tests/TestCase.php` still pins the module's real setting to
     * `local`, so nothing here can reach the deployment's bucket. What is
     * asserted is the shape of the failure and not its cause — 503, and no row
     * in the table — because a submission that is recorded without its
     * photographs is the outcome the resident cannot see.
     */
    public function test_a_photograph_the_store_refuses_is_a_refusal_and_never_a_201(): void
    {
        config([
            'dormitory.maintenance.photo_disk' => RefusesEveryWrite::registerAs('refusing-maintenance'),
        ]);

        Sanctum::actingAs($this->resident);

        $this->post('/api/v1/maintenance-requests', $this->submission($this->building, [
            'photos' => [$this->photograph()],
        ]))->assertStatus(503);

        $this->assertSame(
            0,
            MaintenanceRequest::query()->count(),
            'A submission whose photograph was lost must not be recorded at all.',
        );
    }

    /**
     * A submission without photographs is untouched by any of this: there is
     * nothing to write, so there is nothing to refuse.
     */
    public function test_a_submission_with_no_photographs_is_unaffected_by_the_store(): void
    {
        config([
            'dormitory.maintenance.photo_disk' => RefusesEveryWrite::registerAs('refusing-maintenance'),
        ]);

        Sanctum::actingAs($this->resident);

        $this->post('/api/v1/maintenance-requests', $this->submission($this->building))
            ->assertStatus(201)
            ->assertJsonPath('data.photo_count', 0);
    }

    /**
     * The reader's end of it: the photograph itself, with its own content
     * type, for the client that is about to draw it.
     *
     * **Not a signed link, which is what the first fix handed out** (second
     * acceptance pass of 15.09.2026). The store is signed for as `minio:9000`,
     * a name that exists only inside the Compose network, and SigV4 covers the
     * Host header — so the browser could neither resolve the link nor be given
     * a rewritten one. The bytes work on both disks the project has.
     */
    public function test_the_warden_reads_a_photograph_of_a_request_of_his_own_dormitory(): void
    {
        $request = $this->filedWithAPhotograph();

        Sanctum::actingAs($this->warden);

        $response = $this->get('/api/v1/maintenance-requests/'.$request->getKey().'/photos/0')
            ->assertStatus(200);

        $this->assertSame('image/png', $response->headers->get('content-type'));
        $this->assertSame(
            Storage::disk('local')->get($request->photoPaths()[0]),
            $response->streamedContent(),
        );
    }

    /**
     * The same answer on a disk that could sign a link, so that the route has
     * one shape everywhere and a client never has to tell two apart. The faked
     * disk of this suite signs through `buildTemporaryUrlsUsing`, which is the
     * arrangement that made the earlier version of this test pass while the
     * browser could not open the link.
     */
    public function test_a_disk_that_could_sign_a_link_still_streams_the_file(): void
    {
        $request = $this->filedWithAPhotograph();

        Sanctum::actingAs($this->resident);

        $this->get('/api/v1/maintenance-requests/'.$request->getKey().'/photos/0')
            ->assertStatus(200)
            ->assertHeader('content-type', 'image/png');
    }

    /**
     * The person who filed it reads it too, by the policy that decides the
     * card: the photograph is part of the request and asks no question of its
     * own.
     */
    public function test_the_resident_reads_the_photograph_of_their_own_request(): void
    {
        $request = $this->filedWithAPhotograph();

        Sanctum::actingAs($this->resident);

        $this->get('/api/v1/maintenance-requests/'.$request->getKey().'/photos/0')->assertStatus(200);
    }

    /**
     * FR-07's horizontal boundary, asked of a photograph: the warden of block
     * B has no more business with a picture of block A's burst pipe than with
     * the request behind it.
     */
    public function test_the_warden_of_another_dormitory_is_refused(): void
    {
        $request = $this->filedWithAPhotograph();

        $elsewhere = $this->dormitory('Block B');
        $stranger = $this->staff(RoleCode::Warden, $elsewhere, 'warden-b@example.test');

        Sanctum::actingAs($stranger);

        $this->get('/api/v1/maintenance-requests/'.$request->getKey().'/photos/0')->assertStatus(403);
    }

    /**
     * An index past the end of the list is a 404 and not a 500: there is no
     * such photograph, and nothing about the request is malformed.
     */
    public function test_an_index_the_request_has_no_photograph_under_is_not_found(): void
    {
        $request = $this->filedWithAPhotograph();

        Sanctum::actingAs($this->resident);

        $this->getJson('/api/v1/maintenance-requests/'.$request->getKey().'/photos/3')->assertStatus(404);
    }

    private function filedWithAPhotograph(): MaintenanceRequest
    {
        Sanctum::actingAs($this->resident);

        $id = $this->post('/api/v1/maintenance-requests', $this->submission($this->building, [
            'photos' => [$this->photograph()],
        ]))->assertStatus(201)->json('data.id');

        return MaintenanceRequest::query()->findOrFail($id);
    }
}

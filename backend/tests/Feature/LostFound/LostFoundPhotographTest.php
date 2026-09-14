<?php

declare(strict_types=1);

namespace Tests\Feature\LostFound;

use App\Models\Building;
use App\Models\LostFoundItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsALostFoundScenario;
use Tests\Support\RefusesEveryWrite;
use Tests\TestCase;

/**
 * FR-24's photograph on the two journeys the acceptance of 15.09.2026 found
 * broken, told for the lost-and-found module: the one into the store and the
 * one back out of it.
 *
 * The finding was the same on both modules and so is the fix, but the two
 * modules keep their photographs on disks of their own (§4.6.2) and are wired
 * up separately — `LostFoundController` takes its store through a contextual
 * binding — so a test that covered only the maintenance side would leave the
 * half that is configured differently uncovered.
 */
final class LostFoundPhotographTest extends TestCase
{
    use BuildsALostFoundScenario, RefreshDatabase;

    private Building $building;

    private User $finder;

    private User $neighbour;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 09:30:00'));
        Storage::fake('local');

        $this->building = $this->dormitory('Block A');
        $this->finder = $this->residentOf($this->building, 'finder@example.test', '412');
        $this->neighbour = $this->residentOf($this->building, 'neighbour@example.test', '305');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * A find whose photograph the store refused used to be published with
     * `has_photograph: false` and a 201. The picture is most of what makes a
     * find recognisable in a feed, so the publication is refused instead.
     */
    public function test_a_photograph_the_store_refuses_is_a_refusal_and_never_a_201(): void
    {
        config([
            'dormitory.lost_found.photo_disk' => RefusesEveryWrite::registerAs('refusing-lost-found'),
        ]);

        Sanctum::actingAs($this->finder);

        $this->post('/api/v1/lost-found', $this->publication($this->building, [
            'photo' => $this->photographOfAFind(),
        ]))->assertStatus(503);

        $this->assertSame(
            0,
            LostFoundItem::query()->count(),
            'A publication whose photograph was lost must not be recorded at all.',
        );
    }

    /**
     * FR-24 makes the photograph optional, so a publication without one is
     * untouched by any of this: there is nothing to write and nothing to
     * refuse.
     */
    public function test_a_publication_with_no_photograph_is_unaffected_by_the_store(): void
    {
        config([
            'dormitory.lost_found.photo_disk' => RefusesEveryWrite::registerAs('refusing-lost-found'),
        ]);

        Sanctum::actingAs($this->finder);

        $this->post('/api/v1/lost-found', $this->publication($this->building))
            ->assertStatus(201)
            ->assertJsonPath('data.has_photograph', false);
    }

    /**
     * Anybody the feed reaches sees the picture, which is the point of
     * publishing one: FR-25's reader is the dormitory, and the photograph
     * carries no more than the card does.
     */
    public function test_a_resident_of_the_dormitory_reads_the_photograph(): void
    {
        $item = $this->publishedWithAPhotograph();

        Sanctum::actingAs($this->neighbour);

        $response = $this->get('/api/v1/lost-found/'.$item->getKey().'/photo')
            ->assertStatus(200);

        $this->assertSame('image/png', $response->headers->get('content-type'));
        $this->assertSame(
            Storage::disk('local')->get((string) $item->photo_path),
            $response->streamedContent(),
        );
    }

    /**
     * FR-25's «their own dormitory», asked of a photograph. The feed does not
     * reach block B, and neither does the picture.
     */
    public function test_a_resident_of_another_dormitory_is_refused(): void
    {
        $item = $this->publishedWithAPhotograph();

        $elsewhere = $this->dormitory('Block B');
        $stranger = $this->residentOf($elsewhere, 'stranger@example.test', '101');

        Sanctum::actingAs($stranger);

        $this->getJson('/api/v1/lost-found/'.$item->getKey().'/photo')->assertStatus(403);
    }

    /**
     * An entry published without a picture is a 404 and not an empty answer a
     * client would have to tell apart from a link.
     */
    public function test_an_entry_with_no_photograph_is_not_found(): void
    {
        Sanctum::actingAs($this->finder);

        $id = $this->post('/api/v1/lost-found', $this->publication($this->building))
            ->assertStatus(201)
            ->json('data.id');

        $this->getJson('/api/v1/lost-found/'.$id.'/photo')->assertStatus(404);
    }

    private function publishedWithAPhotograph(): LostFoundItem
    {
        Sanctum::actingAs($this->finder);

        $id = $this->post('/api/v1/lost-found', $this->publication($this->building, [
            'photo' => $this->photographOfAFind(),
        ]))->assertStatus(201)->json('data.id');

        return LostFoundItem::query()->findOrFail($id);
    }
}

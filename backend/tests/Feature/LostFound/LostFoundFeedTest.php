<?php

declare(strict_types=1);

namespace Tests\Feature\LostFound;

use App\Enums\ResidencyStatus;
use App\Enums\RoleCode;
use App\Models\Building;
use App\Models\LostFoundItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsALostFoundScenario;
use Tests\TestCase;

/**
 * FR-25, «List of finds»: one test per acceptance criterion, plus the one
 * §4.6.2 names by hand.
 */
final class LostFoundFeedTest extends TestCase
{
    use BuildsALostFoundScenario, RefreshDatabase;

    private Building $blockA;

    private Building $blockB;

    private User $finder;

    private User $neighbour;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 09:30:00'));

        $this->blockA = $this->dormitory('Block A');
        $this->blockB = $this->dormitory('Block B');

        $this->finder = $this->residentOf($this->blockA, 'finder@example.test', '412');
        $this->neighbour = $this->residentOf($this->blockA, 'neighbour@example.test', '305');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * FR-25, second criterion: «the find card displays neither the name nor
     * the contacts of the registering user».
     *
     * The test §4.6.2 names: «assert the absence of the reporter field in the
     * body of `GET /api/v1/lost-found/{id}`». The omission lives in the API
     * Resource and not in the query, so the service still holds `reporter_id`
     * for authorisation while the response cannot leak it — which the second
     * half of this test checks from the other side.
     */
    public function test_the_find_card_displays_neither_the_name_nor_the_contacts_of_the_registering_user(): void
    {
        $find = $this->findOf($this->finder, $this->blockA);

        Sanctum::actingAs($this->neighbour);

        $response = $this->getJson('/api/v1/lost-found/'.$find->getKey())->assertStatus(200);

        $card = $response->json('data');

        foreach (['reporter_id', 'reporter_name', 'reporter', 'finder', 'email', 'phone', 'contact'] as $field) {
            $this->assertArrayNotHasKey(
                $field,
                $card,
                sprintf('FR-25 keeps «%s» off the card and the response carries it.', $field),
            );
        }

        // Nowhere else in the body either: not under a different name, not
        // inside a nested object.
        $encoded = json_encode($card, JSON_UNESCAPED_UNICODE) ?: '';

        $this->assertStringNotContainsString((string) $this->finder->email, $encoded);
        $this->assertStringNotContainsString((string) $this->finder->full_name, $encoded);

        // And the column the response does not carry is still on the row,
        // which is what makes the module's authorisation work at all.
        $this->assertSame($this->finder->getKey(), $find->fresh()?->reporter_id);
    }

    /**
     * The same omission in the list, because a feed of two hundred entries is
     * where a leak would actually matter.
     */
    public function test_the_list_carries_no_registering_user_either(): void
    {
        $this->findOf($this->finder, $this->blockA);

        Sanctum::actingAs($this->neighbour);

        $rows = $this->getJson('/api/v1/lost-found')->assertStatus(200)->json('data');

        $this->assertCount(1, $rows);
        $this->assertArrayNotHasKey('reporter_id', $rows[0]);
        $this->assertArrayNotHasKey('reporter_name', $rows[0]);
    }

    /**
     * FR-25, first criterion: «residents see the list of finds for their own
     * dormitory».
     *
     * The route carries no building parameter, so the boundary is a missing
     * parameter rather than a policy somebody has to remember to call.
     */
    public function test_residents_see_the_list_of_finds_for_their_own_dormitory(): void
    {
        $here = $this->findOf($this->finder, $this->blockA);

        $strangerNextDoor = $this->residentOf($this->blockB, 'elsewhere@example.test', '101');
        $there = $this->findOf($strangerNextDoor, $this->blockB);

        Sanctum::actingAs($this->neighbour);

        $rows = $this->getJson('/api/v1/lost-found')->assertStatus(200)->json('data');

        $this->assertSame([$here->getKey()], array_column($rows, 'id'));
        $this->assertNotContains($there->getKey(), array_column($rows, 'id'));
    }

    /**
     * FR-07, the same boundary asked directly: a resident of block A cannot
     * open the card of a find in block B, at the API and not merely in the
     * interface.
     */
    public function test_a_resident_cannot_open_the_card_of_a_find_in_another_dormitory(): void
    {
        $stranger = $this->residentOf($this->blockB, 'elsewhere@example.test', '101');
        $there = $this->findOf($stranger, $this->blockB);

        Sanctum::actingAs($this->neighbour);

        $this->getJson('/api/v1/lost-found/'.$there->getKey())->assertStatus(403);
    }

    /**
     * FR-05: the feed is a building-bound function and closes on the stated
     * departure date. A person the register has never heard of keeps the
     * access their grant gives them; a person who has moved out does not.
     */
    public function test_a_resident_who_has_moved_out_no_longer_reads_that_dormitorys_feed(): void
    {
        $this->findOf($this->finder, $this->blockA);

        $this->neighbour->openResidency()->firstOrFail()->update([
            'moved_out_at' => CarbonImmutable::now()->subDay()->toDateString(),
            'moved_out_ground' => 'Graduation',
            'status' => ResidencyStatus::Ended,
        ]);

        Sanctum::actingAs($this->neighbour->fresh());

        $this->getJson('/api/v1/lost-found')->assertStatus(200)->assertJsonCount(0, 'data');
    }

    /**
     * FR-26, third criterion: «after closure the record disappears from the
     * public list». On the default filter and on every filter the route
     * admits — `resolved` is not a value the feed takes, so a client cannot
     * ask for one and cannot be answered with an empty page it would have to
     * interpret.
     */
    public function test_after_closure_the_record_disappears_from_the_public_list(): void
    {
        $open = $this->findOf($this->finder, $this->blockA);
        $closed = LostFoundItem::factory()
            ->forBuilding($this->blockA)
            ->from($this->finder)
            ->resolved()
            ->create();

        Sanctum::actingAs($this->neighbour);

        $rows = $this->getJson('/api/v1/lost-found')->assertStatus(200)->json('data');

        $this->assertSame([$open->getKey()], array_column($rows, 'id'));
        $this->assertNotContains($closed->getKey(), array_column($rows, 'id'));

        $this->getJson('/api/v1/lost-found?status=resolved')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    /**
     * The person who published an entry reads it after it has closed, because
     * it is theirs — it has left the feed and not the application.
     */
    public function test_the_person_who_published_an_entry_still_reads_it_after_closure(): void
    {
        $closed = LostFoundItem::factory()
            ->forBuilding($this->blockA)
            ->from($this->finder)
            ->resolved()
            ->create();

        Sanctum::actingAs($this->finder);

        $this->getJson('/api/v1/lost-found/'.$closed->getKey())
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'resolved');
    }

    /**
     * A claimed entry is not in «the published list», which is what makes
     * FR-26's second criterion — «a declined claim returns the find to the
     * published list» — say something. It is read by asking for it.
     */
    public function test_a_claimed_entry_leaves_the_default_list_and_is_read_by_asking_for_it(): void
    {
        $claimed = LostFoundItem::factory()
            ->forBuilding($this->blockA)
            ->from($this->finder)
            ->claimed()
            ->create();

        Sanctum::actingAs($this->neighbour);

        $this->getJson('/api/v1/lost-found')->assertStatus(200)->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/lost-found?status=claimed')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $claimed->getKey());
    }

    /**
     * Newest find first, by the day it was found rather than by the day it was
     * entered — that is the date the reader is shown, and a list sorted by one
     * and displaying the other would look shuffled.
     */
    public function test_the_list_is_ordered_by_the_day_of_the_finding_newest_first(): void
    {
        $older = LostFoundItem::factory()
            ->forBuilding($this->blockA)->from($this->finder)->foundDaysAgo(9)->create();
        $newer = LostFoundItem::factory()
            ->forBuilding($this->blockA)->from($this->finder)->foundDaysAgo(1)->create();

        Sanctum::actingAs($this->neighbour);

        $rows = $this->getJson('/api/v1/lost-found')->assertStatus(200)->json('data');

        $this->assertSame([$newer->getKey(), $older->getKey()], array_column($rows, 'id'));
    }

    public function test_the_list_is_searchable_by_the_thing_and_by_the_place(): void
    {
        $umbrella = LostFoundItem::factory()->forBuilding($this->blockA)->from($this->finder)
            ->of('A black umbrella with a wooden handle', 'The third-floor landing')->create();
        LostFoundItem::factory()->forBuilding($this->blockA)->from($this->finder)
            ->of('A pair of headphones in a grey case', 'The reading room')->create();

        Sanctum::actingAs($this->neighbour);

        $this->getJson('/api/v1/lost-found?search=umbrella')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $umbrella->getKey());

        $this->getJson('/api/v1/lost-found?search=landing')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $umbrella->getKey());
    }

    /**
     * The card says how many claims are on the entry and never what any of
     * them says: the identifying marks are the one thing that makes a claim
     * checkable, and a published list of them would tell the next claimant
     * exactly what to write.
     */
    public function test_the_card_carries_the_number_of_claims_and_not_their_marks(): void
    {
        $find = $this->findOf($this->finder, $this->blockA);
        $this->claimOn($find, $this->neighbour, 'A strip of blue tape near the tip.');

        Sanctum::actingAs($this->neighbour);

        $card = $this->getJson('/api/v1/lost-found/'.$find->getKey())
            ->assertStatus(200)
            ->assertJsonPath('data.claim_count', 1)
            ->json('data');

        $this->assertStringNotContainsString(
            'blue tape',
            json_encode($card, JSON_UNESCAPED_UNICODE) ?: '',
        );
    }

    /**
     * The list of claims on an entry is the holder's and the warden's. Another
     * resident of the same dormitory reads the card and not the claims.
     */
    public function test_another_resident_does_not_read_the_claims_made_against_an_entry(): void
    {
        $find = $this->findOf($this->finder, $this->blockA);
        $this->claimOn($find, $this->neighbour);

        $third = $this->residentOf($this->blockA, 'third@example.test', '210');

        Sanctum::actingAs($third);

        $this->getJson('/api/v1/lost-found/'.$find->getKey().'/claims')->assertStatus(403);

        Sanctum::actingAs($this->finder);

        $this->getJson('/api/v1/lost-found/'.$find->getKey().'/claims')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    /**
     * The administrator's grant names no dormitory, so the feed is not
     * confined to one — the same rule the announcement feed follows.
     */
    public function test_the_administrator_reads_the_finds_of_every_dormitory(): void
    {
        $this->findOf($this->finder, $this->blockA);
        $elsewhere = $this->residentOf($this->blockB, 'elsewhere@example.test', '101');
        $this->findOf($elsewhere, $this->blockB);

        Sanctum::actingAs($this->staff(RoleCode::Administrator, null, 'admin@example.test'));

        $this->getJson('/api/v1/lost-found')->assertStatus(200)->assertJsonCount(2, 'data');
    }

    public function test_the_list_needs_a_session(): void
    {
        $this->getJson('/api/v1/lost-found')->assertStatus(401);
    }
}

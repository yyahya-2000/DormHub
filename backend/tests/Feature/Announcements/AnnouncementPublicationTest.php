<?php

declare(strict_types=1);

namespace Tests\Feature\Announcements;

use App\Enums\AnnouncementCategory;
use App\Enums\AuditAction;
use App\Enums\NotificationCategory;
use App\Enums\RoleCode;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\User;
use App\Notifications\AnnouncementPublished;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsAGuestScenario;
use Tests\TestCase;

/**
 * FR-09, «Publishing an announcement», one test per acceptance criterion.
 *
 * **A note on the first criterion's parenthesis.** FR-09 states the audience as
 * «whole dormitory, floor, room». The MVP addressee is the dormitory and
 * nothing narrower: `ANNOUNCEMENT.building_id` is the only audience column the
 * ER model of §3.4.3 carries, with NULL meaning every building (§3.4.2). The
 * tests below assert what is implemented — an announcement reaches the
 * dormitory it names and no other, and one addressed to every dormitory
 * reaches them all. Narrowing to a floor or a room is not in the schema, is
 * not in the routes, and is recorded as a direction of development rather than
 * asserted here.
 *
 * The clock is pinned throughout: both criteria are comparisons against the
 * present moment, and the second one moves it on purpose.
 */
final class AnnouncementPublicationTest extends TestCase
{
    use BuildsAGuestScenario, RefreshDatabase;

    private Building $blockA;

    private Building $blockB;

    private User $wardenOfA;

    private User $managerOfA;

    private User $wardenOfB;

    private User $administrator;

    private User $residentOfA;

    private User $neighbourOfA;

    private User $residentOfB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 09:30:00'));

        $this->blockA = $this->dormitory('Block A');
        $this->blockB = $this->dormitory('Block B');

        $this->wardenOfA = $this->staff(RoleCode::Warden, $this->blockA, 'warden.a@example.test');
        $this->managerOfA = $this->staff(RoleCode::Manager, $this->blockA, 'manager.a@example.test');
        $this->wardenOfB = $this->staff(RoleCode::Warden, $this->blockB, 'warden.b@example.test');
        $this->administrator = $this->staff(RoleCode::Administrator, null, 'admin@example.test');

        $this->residentOfA = $this->residentOf($this->blockA, 'resident.a@example.test', '305');
        $this->neighbourOfA = $this->residentOf($this->blockA, 'neighbour.a@example.test', '306');
        $this->residentOfB = $this->residentOf($this->blockB, 'resident.b@example.test', '412');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * First criterion, the publishing half: the warden and the manager of the
     * dormitory publish into it.
     */
    public function test_the_warden_and_the_manager_of_the_dormitory_publish_into_it(): void
    {
        foreach ([$this->wardenOfA, $this->managerOfA] as $author) {
            Sanctum::actingAs($author);

            $this->postJson('/api/v1/announcements', $this->payload())
                ->assertStatus(201)
                ->assertJsonPath('data.building_id', $this->blockA->getKey())
                ->assertJsonPath('data.author_id', $author->getKey())
                ->assertJsonPath('data.category', AnnouncementCategory::Utilities->value)
                ->assertJsonPath('data.addresses_every_building', false);
        }

        $this->assertSame(2, Announcement::query()->count());
    }

    /**
     * First criterion, the other half of the publishing gate: the warden of
     * another dormitory is refused.
     */
    public function test_the_warden_of_another_dormitory_cannot_publish_into_this_one(): void
    {
        Sanctum::actingAs($this->wardenOfB);

        $this->postJson('/api/v1/announcements', $this->payload())
            ->assertStatus(403);

        $this->assertSame(0, Announcement::query()->count());

        // §3.9.6: the refusal is an event of the log in its own right.
        $this->assertTrue(
            AuditLog::query()->where('action', AuditAction::AccessDenied->value)->exists()
        );
    }

    public function test_a_resident_cannot_publish_an_announcement(): void
    {
        Sanctum::actingAs($this->residentOfA);

        $this->postJson('/api/v1/announcements', $this->payload())
            ->assertStatus(403);

        $this->assertSame(0, Announcement::query()->count());
    }

    /**
     * The addressee column read at its edge: NULL means every dormitory
     * (§3.4.2), so leaving `building_id` out is the administrator's right and
     * a warden attempting it would be addressing a building he holds no grant
     * in.
     */
    public function test_only_the_administrator_addresses_every_dormitory(): void
    {
        Sanctum::actingAs($this->wardenOfA);

        $this->postJson('/api/v1/announcements', $this->payload(['building_id' => null]))
            ->assertStatus(403);

        Sanctum::actingAs($this->administrator);

        $this->postJson('/api/v1/announcements', $this->payload([
            'building_id' => null,
            'title' => 'Passes for the winter holidays',
        ]))
            ->assertStatus(201)
            ->assertJsonPath('data.building_id', null)
            ->assertJsonPath('data.addresses_every_building', true);
    }

    /**
     * The other half of the administrator's addressee, and the half a form
     * that only ever offered him «all dormitories» would have hidden: he picks
     * one block by naming it, in either direction, and `building_id` is the
     * only field that says so.
     */
    public function test_the_administrator_addresses_one_dormitory_by_naming_it(): void
    {
        Sanctum::actingAs($this->administrator);

        foreach ([$this->blockA, $this->blockB] as $building) {
            $this->postJson('/api/v1/announcements', $this->payload([
                'building_id' => $building->getKey(),
                'title' => 'Cold water off in '.$building->name,
            ]))
                ->assertStatus(201)
                ->assertJsonPath('data.building_id', $building->getKey())
                ->assertJsonPath('data.addresses_every_building', false);
        }

        // And the notice reaches only the dormitory it names.
        Sanctum::actingAs($this->residentOfB);

        $this->getJson('/api/v1/announcements')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.building_id', $this->blockB->getKey());
    }

    /**
     * FR-09's category, which is now a label and not a choice from a list.
     *
     * Two properties of it are asserted together because they are one rule:
     * anything up to 32 characters is accepted and comes back as it was
     * stored, and the bound is enforced rather than truncated — the column is
     * `VARCHAR(32)` and a request the database would cut short is refused
     * before it reaches the database.
     */
    public function test_the_category_is_a_free_label_bounded_at_thirty_two_characters(): void
    {
        Sanctum::actingAs($this->wardenOfA);

        $this->postJson('/api/v1/announcements', $this->payload([
            'category' => '  Водоснабжение  ',
        ]))
            ->assertStatus(201)
            // Trimmed, so that a stray space does not make a second heading.
            ->assertJsonPath('data.category', 'Водоснабжение')
            ->assertJsonPath('data.category_label', 'Водоснабжение');

        $this->postJson('/api/v1/announcements', $this->payload([
            'category' => str_repeat('a', 33),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');

        $this->postJson('/api/v1/announcements', $this->payload(['category' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');

        $this->assertSame(1, Announcement::query()->count());
    }

    /**
     * The catalogue the form offers before it lets the author type one of
     * their own, shaped as `GET /citizenships` is.
     */
    public function test_the_catalogue_of_categories_is_offered_to_the_client(): void
    {
        Sanctum::actingAs($this->wardenOfA);

        $response = $this->getJson('/api/v1/announcement-categories')
            ->assertStatus(200)
            ->assertJsonPath('max_length', 32);

        $this->assertSame(
            AnnouncementCategory::values(),
            $response->json('data.*.value'),
        );

        $this->assertSame(
            AnnouncementCategory::General->label(),
            $response->json('data.4.label'),
        );
    }

    /**
     * First criterion: «the announcement is visible only to its audience»,
     * read from both sides of the boundary.
     */
    public function test_the_announcement_is_visible_to_its_audience_and_to_nobody_else(): void
    {
        $forBlockA = Announcement::factory()
            ->forBuilding($this->blockA)
            ->by($this->wardenOfA)
            ->create(['title' => 'Cold water off in block A']);

        $forEverybody = Announcement::factory()
            ->toEveryBuilding()
            ->by($this->administrator)
            ->create(['title' => 'Passes for the winter holidays']);

        Sanctum::actingAs($this->residentOfA);

        $seenByA = $this->getJson('/api/v1/announcements')
            ->assertStatus(200)
            ->json('data.*.id');

        $this->assertEqualsCanonicalizing(
            [$forBlockA->getKey(), $forEverybody->getKey()],
            $seenByA,
        );

        Sanctum::actingAs($this->residentOfB);

        $seenByB = $this->getJson('/api/v1/announcements')
            ->assertStatus(200)
            ->json('data.*.id');

        // The notice addressed to every dormitory reaches block B; the one
        // addressed to block A does not.
        $this->assertSame([$forEverybody->getKey()], $seenByB);
    }

    /**
     * Second criterion: «on expiry it moves to the archive and leaves the
     * feed».
     *
     * The clock is moved and nothing else is: no job runs, no status is set,
     * no row is touched. That is the whole design of §4.6.1 — the feed filters
     * on `expires_at`, so the announcement leaves it by itself and a system
     * that forgot to run an archiving sweep could not disagree with the feed
     * about which notices are current.
     *
     * **The flag is written `archived=true` and not `archived=1`, which is the
     * acceptance finding of 15.09.2026.** The contract declares the parameter
     * `type: boolean` and the generated client serialises it with
     * `String(value)`, so `true` is the spelling every real caller sends —
     * and Laravel's `boolean` rule admits `"1"` and not `"true"`, so the
     * Archive button was answered 422 while this test passed. A test that
     * writes a spelling nobody's client produces is a test of the wrong
     * route.
     */
    public function test_on_expiry_the_announcement_leaves_the_feed_and_moves_to_the_archive(): void
    {
        $expiring = Announcement::factory()
            ->forBuilding($this->blockA)
            ->by($this->wardenOfA)
            ->expiringAt(CarbonImmutable::parse('2026-09-20 12:00:00'))
            ->create(['title' => 'Lift out of service until the twentieth']);

        Sanctum::actingAs($this->residentOfA);

        $this->getJson('/api/v1/announcements')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $expiring->getKey());

        $this->getJson('/api/v1/announcements?archived=true')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-20 12:00:01'));

        $this->getJson('/api/v1/announcements')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/announcements?archived=true')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $expiring->getKey());

        // The row itself was never touched: the archive is a comparison and
        // not a state somebody moved it into.
        $this->assertSame(
            $expiring->updated_at?->toIso8601String(),
            $expiring->fresh()?->updated_at?->toIso8601String(),
        );
    }

    /**
     * The archive flag in every spelling a client actually sends, and one it
     * must still refuse.
     *
     * The acceptance finding of 15.09.2026 in one test. The generated client
     * turns a boolean query parameter into `"true"` or `"false"`; Laravel's
     * `boolean` rule admits `1`, `0`, `"1"` and `"0"` and nothing else, so the
     * Archive button answered 422 on a value the contract declares. The
     * normalisation admits the words and leaves everything else to the rule —
     * `archived=maybe` is not a boolean in any spelling and is still a 422
     * naming the field.
     */
    public function test_the_archive_flag_is_read_in_the_spelling_a_client_sends(): void
    {
        Sanctum::actingAs($this->residentOfA);

        foreach (['true', 'false', '1', '0'] as $spelling) {
            $this->getJson('/api/v1/announcements?archived='.$spelling)
                ->assertStatus(200, sprintf('archived=%s was refused.', $spelling));
        }

        $this->getJson('/api/v1/announcements?archived=maybe')
            ->assertStatus(422)
            ->assertJsonValidationErrors('archived');
    }

    /**
     * An announcement with no expiry does not expire. NULL is «for as long as
     * it stands» and not «expired at the beginning of time», which is the one
     * way a nullable date column is usually got wrong.
     */
    public function test_an_announcement_without_an_expiry_stays_in_the_feed(): void
    {
        Announcement::factory()
            ->forBuilding($this->blockA)
            ->by($this->wardenOfA)
            ->create(['expires_at' => null, 'title' => 'Board games on Thursdays']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-09-14 09:30:00'));

        Sanctum::actingAs($this->residentOfA);

        $this->getJson('/api/v1/announcements')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    /**
     * FR-09's validity period, refused at the boundary: an announcement that
     * expires before it is published would be in no feed and in no archive,
     * and the warden would have no way of telling that from a failure.
     */
    public function test_an_expiry_already_in_the_past_is_refused(): void
    {
        Sanctum::actingAs($this->wardenOfA);

        $this->postJson('/api/v1/announcements', $this->payload([
            'expires_at' => CarbonImmutable::now()->subDay()->toIso8601String(),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('expires_at');

        $this->assertSame(0, Announcement::query()->count());
    }

    /**
     * FR-33 and §3.9.6: what was announced, to which dormitory, by whom and
     * until when. The audience travels as it is stored, so the log can say
     * «every dormitory» and not merely leave the field out.
     */
    public function test_publishing_is_written_to_the_audit_log_with_its_addressee(): void
    {
        Sanctum::actingAs($this->administrator);

        $this->postJson('/api/v1/announcements', $this->payload([
            'building_id' => null,
        ]))->assertStatus(201);

        $entry = AuditLog::query()
            ->where('action', AuditAction::AnnouncementPublished->value)
            ->sole();

        $this->assertSame($this->administrator->getKey(), $entry->user_id);
        $this->assertNull($entry->payload['building_id']);
        $this->assertSame(AnnouncementCategory::Utilities->value, $entry->payload['category']);
    }

    /**
     * FR-09 reaching the resident, and the line FR-34 draws through the
     * module.
     *
     * Everybody the announcement was addressed to is written to, and nobody
     * else is: the audience is the dormitory, and a resident of another block
     * hears nothing. There is one announcement category now — the second,
     * unsilenceable one existed only for the notices FR-12 required a resident
     * to acknowledge.
     */
    public function test_the_audience_is_notified_and_nobody_outside_it_is(): void
    {
        Notification::fake();

        Sanctum::actingAs($this->wardenOfA);

        $this->postJson('/api/v1/announcements', $this->payload())->assertStatus(201);

        foreach ([$this->residentOfA, $this->neighbourOfA] as $resident) {
            Notification::assertSentTo(
                $resident,
                fn (AnnouncementPublished $notification): bool => $notification->category()
                    === NotificationCategory::Announcement,
            );
        }

        Notification::assertNotSentTo($this->residentOfB, AnnouncementPublished::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'building_id' => $this->blockA->getKey(),
            'title' => 'Cold water off on Wednesday, 09:00 to 17:00',
            'body' => 'The riser on floors three to five is being replaced.',
            'category' => AnnouncementCategory::Utilities->value,
        ], $overrides);
    }
}

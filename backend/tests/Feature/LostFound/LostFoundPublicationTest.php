<?php

declare(strict_types=1);

namespace Tests\Feature\LostFound;

use App\Enums\AuditAction;
use App\Enums\LostFoundCustody;
use App\Enums\LostFoundItemKind;
use App\Enums\LostFoundItemStatus;
use App\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\LostFoundItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsALostFoundScenario;
use Tests\TestCase;

/**
 * FR-24, «Publishing a find»: one test per acceptance criterion.
 *
 * The clock is pinned throughout, because two of the criteria are about dates
 * and a suite that ran across midnight would disagree with itself about which
 * day «today» was.
 */
final class LostFoundPublicationTest extends TestCase
{
    use BuildsALostFoundScenario, RefreshDatabase;

    private Building $building;

    private User $finder;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 09:30:00'));
        Storage::fake('local');

        $this->building = $this->dormitory('Block A');
        $this->finder = $this->residentOf($this->building, 'finder@example.test', '412');
        $this->officer = $this->staff(RoleCode::SecurityOfficer, $this->building, 'post@example.test');
    }

    /**
     * The acceptance finding of 15.09.2026: «today» was the server's and not
     * the dormitory's.
     *
     * The application ran in UTC and the dormitory stands in Moscow, three
     * hours ahead. After nine in the evening the server's day had not turned
     * over yet, so an umbrella picked up on the landing that evening — dated
     * today by the form the resident was filling in — came back as «a find
     * cannot have happened later than today». The date was right and the clock
     * comparing it was in another country.
     *
     * The rule reads the dormitory's day now, and reads it through Carbon:
     * Laravel's `before_or_equal:today` resolves the word with `strtotime()`,
     * which knows nothing of a pinned clock, so a rule phrased that way could
     * not be stood on a day boundary at all.
     */
    public function test_a_find_picked_up_this_evening_is_published_after_the_servers_day_has_turned(): void
    {
        // The dormitory's clock reads 00:30 on the sixteenth; a server keeping
        // UTC reads 21:30 on the fifteenth.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 21:30:00', 'UTC'));

        // The date the resident's own calendar shows, written out rather than
        // computed: a date derived from the application's own setting would
        // move with it and the test would pass in any zone at all.
        $today = '2026-09-16';

        Sanctum::actingAs($this->finder);

        $this->postJson('/api/v1/lost-found', $this->publication($this->building, [
            'happened_on' => $today,
        ]))
            ->assertStatus(201)
            ->assertJsonPath('data.happened_on', $today);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * FR-24, first criterion: «the record is created with mandatory fields
     * category, place and date of finding».
     */
    public function test_the_record_is_created_with_mandatory_fields_category_place_and_date_of_finding(): void
    {
        Sanctum::actingAs($this->finder);

        $response = $this->post('/api/v1/lost-found', $this->publication($this->building, [
            'title' => 'A black umbrella with a wooden handle',
            'place' => 'The landing between the third and fourth floors',
            'happened_on' => '2026-09-13',
        ]))->assertStatus(201);

        $published = LostFoundItem::query()->findOrFail($response->json('data.id'));

        $this->assertSame('A black umbrella with a wooden handle', $published->title);
        $this->assertSame('The landing between the third and fourth floors', $published->place);
        $this->assertSame('2026-09-13', $published->happened_on?->toDateString());
        $this->assertSame($this->building->getKey(), $published->building_id);
    }

    /**
     * FR-24, first criterion, from the other side: each of the three is
     * mandatory, and the refusal names the field.
     */
    public function test_a_publication_without_a_category_a_place_or_a_date_of_finding_is_refused(): void
    {
        Sanctum::actingAs($this->finder);

        foreach (['title', 'place', 'happened_on'] as $field) {
            $body = $this->publication($this->building);
            unset($body[$field]);

            $this->postJson('/api/v1/lost-found', $body)
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }

        $this->assertSame(0, LostFoundItem::query()->count());
    }

    /**
     * FR-24, second criterion: «the record is bound to the publishing user as
     * the finder».
     *
     * The body never names a reporter and could not: the token is the finder,
     * and a client that could name one could publish in somebody else's name.
     */
    public function test_the_record_is_bound_to_the_publishing_user_as_the_finder(): void
    {
        Sanctum::actingAs($this->finder);

        $response = $this->post('/api/v1/lost-found', $this->publication($this->building))
            ->assertStatus(201);

        $published = LostFoundItem::query()->findOrFail($response->json('data.id'));

        $this->assertSame($this->finder->getKey(), $published->reporter_id);
        // §2.5.4's default path: the object stays with the person who found
        // it, and the decision on a claim is theirs.
        $this->assertSame(LostFoundCustody::Finder, $published->custody);
        $this->assertTrue($published->isDecidedBy($this->finder));
    }

    /**
     * FR-24, third criterion: «the photograph is optional» — without one the
     * record is still created.
     */
    public function test_the_photograph_is_optional_and_the_record_is_created_without_one(): void
    {
        Sanctum::actingAs($this->finder);

        $response = $this->post('/api/v1/lost-found', $this->publication($this->building))
            ->assertStatus(201)
            ->assertJsonPath('data.has_photograph', false);

        $published = LostFoundItem::query()->findOrFail($response->json('data.id'));

        $this->assertNull($published->photo_path);
    }

    /**
     * FR-24, third criterion, the other half: with a photograph the file is
     * validated for type and size, stored in object storage and referenced by
     * path.
     */
    public function test_a_photograph_is_stored_in_object_storage_and_referenced_by_path(): void
    {
        Sanctum::actingAs($this->finder);

        $response = $this->post('/api/v1/lost-found', $this->publication($this->building, [
            'photo' => $this->photographOfAFind(),
        ]))->assertStatus(201);

        $published = LostFoundItem::query()->findOrFail($response->json('data.id'));

        $this->assertNotNull($published->photo_path);
        $this->assertStringStartsWith('lost-found/', (string) $published->photo_path);
        Storage::disk('local')->assertExists((string) $published->photo_path);

        // The name is generated and never the client's: a file name from a
        // browser can be a path traversal or somebody else's upload.
        $this->assertStringNotContainsString('umbrella.png', (string) $published->photo_path);
    }

    public function test_a_file_that_is_not_a_photograph_is_refused(): void
    {
        Sanctum::actingAs($this->finder);

        $this->post('/api/v1/lost-found', $this->publication($this->building, [
            'photo' => UploadedFile::fake()->create('inventory.pdf', 12, 'application/pdf'),
        ]))->assertStatus(422)->assertJsonValidationErrors('photo');
    }

    public function test_a_photograph_larger_than_the_configured_ceiling_is_refused(): void
    {
        config(['dormitory.lost_found.max_photo_kilobytes' => 1]);

        Sanctum::actingAs($this->finder);

        $this->post('/api/v1/lost-found', $this->publication($this->building, [
            'photo' => $this->oversizedPhotograph(),
        ]))->assertStatus(422)->assertJsonValidationErrors('photo');
    }

    /**
     * FR-24, fourth criterion: «publication passes through no staff approval
     * step».
     *
     * The test that holds §2.5.4's peer-to-peer decision in place. The entry
     * comes back in `published` — there is no pending state for it to be in,
     * no other value the vocabulary admits between the publication and the
     * feed — and the very next unauthenticated-by-nobody read of the feed
     * finds it there.
     */
    public function test_publication_passes_through_no_staff_approval_step(): void
    {
        Sanctum::actingAs($this->finder);

        $response = $this->post('/api/v1/lost-found', $this->publication($this->building))
            ->assertStatus(201)
            ->assertJsonPath('data.status', LostFoundItemStatus::Published->value);

        $published = LostFoundItem::query()->findOrFail($response->json('data.id'));

        $this->assertSame(LostFoundItemStatus::Published, $published->status);

        // The absence asserted directly: no status this module has ever heard
        // of stands for «waiting for a member of staff».
        $this->assertSame(
            ['published', 'claimed', 'resolved'],
            LostFoundItemStatus::values(),
            'A moderation state has appeared in the vocabulary, which FR-24 refuses.',
        );

        // And the entry is in the feed of another resident straight away,
        // which is what «no approval step» means to the dormitory.
        $neighbour = $this->residentOf($this->building, 'neighbour@example.test', '305');

        Sanctum::actingAs($neighbour);

        $this->getJson('/api/v1/lost-found')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $published->getKey());
    }

    /**
     * FR-24: «staff — security officer or warden — publish on the same form
     * for items handed in at the post or deposited with the administration».
     *
     * The second of §2.5.4's two paths. The object is the administration's to
     * keep, so the decision on a claim leaves the publisher and goes to the
     * staff of that dormitory as a circle — and `declared_on` is populated,
     * which is the date the six-month period of Civil Code art. 228 cl. 1 runs
     * from where a declaration has actually been made.
     */
    public function test_staff_publish_on_the_same_form_for_an_item_deposited_with_the_administration(): void
    {
        Sanctum::actingAs($this->officer);

        $response = $this->post('/api/v1/lost-found', $this->publication($this->building, [
            'title' => 'A student card in a blue sleeve',
            'place' => 'Handed in at the security post',
            'happened_on' => '2026-09-12',
            'custody' => LostFoundCustody::Administration->value,
            'declared_on' => '2026-09-13',
        ]))->assertStatus(201);

        $deposited = LostFoundItem::query()->findOrFail($response->json('data.id'));

        $this->assertSame(LostFoundCustody::Administration, $deposited->custody);
        $this->assertSame('2026-09-13', $deposited->declared_on?->toDateString());
        $this->assertSame('2026-09-12', $deposited->happened_on?->toDateString());

        // §2.5.4: on this path the decision belongs to the staff of the
        // dormitory and to no one account.
        $this->assertNull($deposited->decisionRestsWith());
        $this->assertFalse($deposited->isDecidedBy($this->officer));
    }

    /**
     * §2.5.4 and §2.7.5: a resident may publish, and may not publish an entry
     * asserting that the administration is holding something. The record is
     * the university's statement about an object in its keeping — Civil Code
     * art. 227 cl. 1 para. 2 — and not the finder's about one in theirs.
     */
    public function test_a_resident_cannot_publish_an_entry_saying_the_administration_is_holding_the_object(): void
    {
        Sanctum::actingAs($this->finder);

        $this->postJson('/api/v1/lost-found', $this->publication($this->building, [
            'custody' => LostFoundCustody::Administration->value,
        ]))->assertStatus(403);

        $this->assertSame(0, LostFoundItem::query()->count());
    }

    /**
     * §2.7.5 and §3.4.2: the two dates are not the same fact, and the
     * declaration cannot precede the finding it is about. FR-27 counts the six
     * months from the second of them and is outside the MVP; the column is
     * here because it cannot be retrofitted (§2.5.4).
     */
    public function test_a_declaration_dated_before_the_finding_is_refused(): void
    {
        Sanctum::actingAs($this->officer);

        $this->postJson('/api/v1/lost-found', $this->publication($this->building, [
            'happened_on' => '2026-09-13',
            'declared_on' => '2026-09-11',
            'custody' => LostFoundCustody::Administration->value,
        ]))->assertStatus(422)->assertJsonValidationErrors('declared_on');
    }

    /**
     * The default is the honest one: most finds are never declared to
     * anybody, and the column says so rather than guessing a date from the
     * registration. The six-month clock simply does not start.
     */
    public function test_a_find_nobody_declared_carries_no_declaration_date(): void
    {
        Sanctum::actingAs($this->finder);

        $response = $this->post('/api/v1/lost-found', $this->publication($this->building))
            ->assertStatus(201)
            ->assertJsonPath('data.declared_on', null);

        $this->assertNull(
            LostFoundItem::query()->findOrFail($response->json('data.id'))->declared_on
        );
    }

    public function test_a_find_cannot_have_happened_later_than_today(): void
    {
        Sanctum::actingAs($this->finder);

        $this->postJson('/api/v1/lost-found', $this->publication($this->building, [
            'happened_on' => CarbonImmutable::now()->addDay()->toDateString(),
        ]))->assertStatus(422)->assertJsonValidationErrors('happened_on');
    }

    /**
     * FR-07: a resident of block A does not publish into block B, and a
     * security officer of block A does not either. Living or working somewhere
     * is what the register answers, not the role alone.
     */
    public function test_a_resident_cannot_publish_into_a_dormitory_they_do_not_live_in(): void
    {
        $other = $this->dormitory('Block B');

        Sanctum::actingAs($this->finder);

        $this->postJson('/api/v1/lost-found', $this->publication($other))->assertStatus(403);
    }

    /**
     * The ER model carries both directions of the notice (§3.4.3, «lost or
     * found») and the form publishes either. A loss is claimed by nobody,
     * which is asserted where claims are.
     */
    public function test_a_resident_publishes_a_loss_on_the_same_form(): void
    {
        Sanctum::actingAs($this->finder);

        $response = $this->post('/api/v1/lost-found', $this->publication($this->building, [
            'kind' => LostFoundItemKind::Lost->value,
            'title' => 'A steel water bottle with a dented lid',
        ]))->assertStatus(201)->assertJsonPath('data.kind', 'lost');

        $this->assertSame(
            LostFoundItemKind::Lost,
            LostFoundItem::query()->findOrFail($response->json('data.id'))->kind,
        );
    }

    /**
     * §3.9.6: the publication is an event of the log, and the payload carries
     * the two facts that cannot be reconstructed from the row if somebody
     * edits it — which of §2.5.4's paths the find took, and whether a
     * declaration was recorded against it.
     */
    public function test_the_publication_is_recorded_with_the_path_it_took_and_the_declaration(): void
    {
        Sanctum::actingAs($this->officer);

        $response = $this->post('/api/v1/lost-found', $this->publication($this->building, [
            'custody' => LostFoundCustody::Administration->value,
            'declared_on' => '2026-09-13',
        ]))->assertStatus(201);

        $entry = AuditLog::query()
            ->where('action', AuditAction::LostFoundItemPublished->value)
            ->where('subject_id', $response->json('data.id'))
            ->sole();

        $this->assertSame($this->officer->getKey(), $entry->user_id);
        $this->assertSame('administration', $entry->payload['custody']);
        $this->assertSame('2026-09-13', $entry->payload['declared_on']);
    }

    public function test_publishing_a_find_needs_a_session(): void
    {
        $this->postJson('/api/v1/lost-found', $this->publication($this->building))
            ->assertStatus(401);
    }
}

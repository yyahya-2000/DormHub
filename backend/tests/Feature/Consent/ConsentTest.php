<?php

declare(strict_types=1);

namespace Tests\Feature\Consent;

use App\Enums\AuditAction;
use App\Enums\Citizenship;
use App\Enums\ConsentDocument;
use App\Enums\NotificationCategory;
use App\Enums\RoleCode;
use App\Exceptions\ConsentRequiredException;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\ConsentRecord;
use App\Models\User;
use App\Notifications\GuestVisitOverdue;
use App\Notifications\MaintenanceRequestStatusChanged;
use App\Services\ConsentRegistry;
use App\Services\ConsentTexts;
use App\Services\Notifier;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * FR-35, «Consent to personal-data processing». One test per acceptance
 * criterion, plus the tests that pin down what the criteria leave open.
 *
 * The second criterion is the one that is easiest to satisfy on paper and
 * hardest to keep true: «consent is executed separately from other documents»
 * is art. 9 part 1 of Federal Law No. 152-FZ, and it is a rule about the act
 * and not about the layout of a page. A checkbox on the sign-in form would
 * break it while looking perfectly reasonable, so the test below asserts the
 * absence — that the sign-in route, the password route and the account route
 * have no field that records a consent, and that the only thing which does is
 * a request whose entire subject is one document.
 *
 * The fourth criterion says withdrawal must be available and stops there. What
 * the system does afterwards was decided rather than discovered, and the
 * decision is asserted in three tests at the end of this file: the record
 * survives, the contract-based functions survive, and the processing that
 * rested on consent stops.
 */
final class ConsentTest extends TestCase
{
    use RefreshDatabase;

    private Building $building;

    private User $resident;

    private string $currentRevision;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->building = Building::factory()->create(['name' => 'Block A']);

        $this->resident = User::factory()
            ->withRole(RoleCode::Resident, $this->building)
            ->withPassword('a-password-of-my-own')
            ->create(['email' => 'resident@example.test']);

        $this->currentRevision = app(ConsentTexts::class)
            ->currentRevision(ConsentDocument::ResidentPersonalData);
    }

    /**
     * First criterion, the resident's half: «consent is displayed and recorded
     * on first login».
     *
     * Displayed: the sign-in answer says a document is outstanding, and the
     * route that carries the text hands over the wording itself — art. 9
     * part 1 wants consent informed, and a title with a checkbox is not that.
     * Recorded: the acceptance leaves a row, and the account stops being
     * pending.
     */
    public function test_consent_is_displayed_and_recorded_on_first_login(): void
    {
        $signIn = $this->postJson('/api/v1/auth/login', [
            'email' => 'resident@example.test',
            'password' => 'a-password-of-my-own',
        ])->assertOk();

        $signIn->assertJsonPath('data.user.consent_required', [
            ConsentDocument::ResidentPersonalData->value,
        ]);

        Sanctum::actingAs($this->resident);

        $pending = $this->getJson('/api/v1/consents/pending')->assertOk();

        $pending
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.document', ConsentDocument::ResidentPersonalData->value)
            ->assertJsonPath('data.0.revision', $this->currentRevision);

        $this->assertStringContainsString(
            'Federal Law No. 152-FZ',
            (string) $pending->json('data.0.body'),
            'The screen that asks for consent has to show the wording, not a title.',
        );

        $this->postJson('/api/v1/consents', [
            'document' => ConsentDocument::ResidentPersonalData->value,
            'revision' => $this->currentRevision,
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.document', ConsentDocument::ResidentPersonalData->value)
            ->assertJsonPath('data.revision', $this->currentRevision)
            ->assertJsonPath('data.in_force', true);

        $this->getJson('/api/v1/consents/pending')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.consent_required', []);
    }

    /**
     * First criterion, the guest's half: «for the guest, before entry is
     * recorded at the security post».
     *
     * The post itself is increment 1, so what is asserted here is the gate the
     * post will call — `ConsentRegistry::requireGranted()` — and the shape of
     * its refusal. `CheckpointService::checkIn()` calls it before it writes
     * anything into the visitor register, and the criterion is then one line
     * at the top of that method.
     *
     * The refusal is 409 and not 403 on purpose: the security officer's role
     * covers the checkpoint perfectly well, and what stands in the way is a
     * missing document. A 403 would also be written to the audit log as
     * `access.denied`, which this is not.
     */
    public function test_for_the_guest_consent_is_required_before_entry_is_recorded_at_the_security_post(): void
    {
        $registry = app(ConsentRegistry::class);
        $guestAccount = User::factory()->create(['email' => 'guest@example.test']);

        // Nobody has consented, and the person before the officer has no
        // account at all — the same refusal, because there is nothing on
        // record either way.
        foreach ([null, $guestAccount] as $subject) {
            try {
                $registry->requireGranted($subject, ConsentDocument::GuestPersonalData);
                $this->fail('An entry was admitted with no consent to processing the guest\'s data.');
            } catch (ConsentRequiredException $refusal) {
                $this->assertSame(ConsentDocument::GuestPersonalData, $refusal->document);
                $this->assertSame(
                    app(ConsentTexts::class)->currentRevision(ConsentDocument::GuestPersonalData),
                    $refusal->revision,
                );

                $response = app(ExceptionHandler::class)->render(
                    Request::create('/api/v1/checkpoint/check-in', 'POST'),
                    $refusal,
                );

                $this->assertSame(409, $response->getStatusCode());
                $this->assertSame(
                    ConsentDocument::GuestPersonalData->value,
                    json_decode((string) $response->getContent(), true)['document'],
                );
            }
        }

        // With consent on record the gate lets the entry through, and the row
        // carries the fact, the date and the revision.
        $registry->record(
            user: $guestAccount,
            document: ConsentDocument::GuestPersonalData,
            revision: app(ConsentTexts::class)->currentRevision(ConsentDocument::GuestPersonalData),
            ipAddress: '192.0.2.7',
        );

        $registry->requireGranted($guestAccount, ConsentDocument::GuestPersonalData);

        $record = ConsentRecord::query()
            ->where('user_id', $guestAccount->getKey())
            ->sole();

        $this->assertSame(ConsentDocument::GuestPersonalData, $record->document_code);
        $this->assertNotNull($record->accepted_at);
        $this->assertSame('192.0.2.7', $record->ip_address);
    }

    /**
     * Second criterion: «consent is executed separately from other documents».
     *
     * Stated as an absence, because that is what the article requires. No
     * other route in the API has a field that could record a consent, and one
     * that arrives with the payload all the same changes nothing.
     */
    public function test_consent_is_executed_separately_from_other_documents(): void
    {
        Notification::fake();

        // Sign-in: the consent field is not there, and inventing it records
        // nothing.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'resident@example.test',
            'password' => 'a-password-of-my-own',
            'consent' => true,
            'consent_revision' => $this->currentRevision,
        ])->assertOk();

        $this->assertDatabaseCount('consent_records', 0);

        // Issuing an account: the same.
        $manager = User::factory()
            ->withRole(RoleCode::Manager, $this->building)
            ->create(['email' => 'manager@example.test']);

        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/buildings/{$this->building->id}/residents", [
            'full_name' => 'Zinaida Ryzhova',
            'email' => 'incoming@example.test',
            'phone' => '+79001112233',
            'citizenship' => Citizenship::Russia->value,
            'consent' => true,
            'personal_data_consent' => true,
        ])->assertStatus(201);

        $this->assertDatabaseCount('consent_records', 0);

        // Changing a password: the same.
        Sanctum::actingAs($this->resident);

        $this->postJson('/api/v1/auth/password', [
            'current_password' => 'a-password-of-my-own',
            'password' => 'a-different-password',
            'password_confirmation' => 'a-different-password',
            'consent' => true,
        ])->assertStatus(204);

        $this->assertDatabaseCount('consent_records', 0);

        // The only act that records one names exactly one document, and it
        // names it explicitly.
        Sanctum::actingAs($this->resident);

        $this->postJson('/api/v1/consents', ['revision' => $this->currentRevision])
            ->assertStatus(422)
            ->assertJsonValidationErrors('document');

        $this->postJson('/api/v1/consents', [
            'document' => ConsentDocument::ResidentPersonalData->value,
            'revision' => $this->currentRevision,
        ])->assertStatus(201);

        $this->assertDatabaseCount('consent_records', 1);

        // And the second document is a second act: consenting to one leaves
        // the other exactly where it was.
        $this->assertFalse(
            app(ConsentRegistry::class)->hasInForce($this->resident, ConsentDocument::GuestPersonalData)
        );
    }

    /**
     * Third criterion: «the fact, date and text revision are stored».
     *
     * The revision is asserted twice over: that the row carries it, and that
     * the repository can still produce the wording it names. A record pointing
     * at a text nobody can show would satisfy the column and fail the
     * requirement — art. 9 part 3 puts the burden of proof on the operator.
     */
    public function test_the_fact_the_date_and_the_text_revision_are_stored(): void
    {
        Sanctum::actingAs($this->resident);

        $before = now()->subSecond();

        $this->postJson('/api/v1/consents', [
            'document' => ConsentDocument::ResidentPersonalData->value,
            'revision' => $this->currentRevision,
        ])->assertStatus(201);

        $record = ConsentRecord::query()->sole();

        // The fact.
        $this->assertSame($this->resident->id, $record->user_id);
        $this->assertSame(ConsentDocument::ResidentPersonalData, $record->document_code);
        $this->assertTrue($record->isInForce());

        // The date.
        $this->assertNotNull($record->accepted_at);
        $this->assertTrue($record->accepted_at->greaterThanOrEqualTo($before));
        $this->assertNull($record->revoked_at);

        // The address it was given from.
        $this->assertNotNull($record->ip_address);

        // The text revision — and the text itself, still producible.
        $this->assertSame($this->currentRevision, $record->document_revision);

        $text = app(ConsentTexts::class)->revision(
            ConsentDocument::ResidentPersonalData,
            $record->document_revision,
        );

        $this->assertSame($this->currentRevision, $text->revision);
        $this->assertNotSame('', $text->body);
    }

    /**
     * Fourth criterion: «withdrawal is available from the personal account».
     */
    public function test_withdrawal_is_available_from_the_personal_account(): void
    {
        Sanctum::actingAs($this->resident);

        $this->postJson('/api/v1/consents', [
            'document' => ConsentDocument::ResidentPersonalData->value,
            'revision' => $this->currentRevision,
        ])->assertStatus(201);

        $document = ConsentDocument::ResidentPersonalData->value;

        $this->postJson("/api/v1/consents/{$document}/withdrawal")
            ->assertOk()
            ->assertJsonPath('data.in_force', false)
            ->assertJsonPath('data.revision', $this->currentRevision);

        $record = ConsentRecord::query()->sole();

        $this->assertNotNull($record->revoked_at);
        $this->assertNotNull($record->accepted_at, 'The date of the consent survives its withdrawal.');

        // The person is pending again: the document is offered once more, and
        // nothing forces them to take it.
        $this->getJson('/api/v1/consents/pending')->assertOk()->assertJsonCount(1, 'data');

        // A second withdrawal is not an error. The caller asked for a state
        // and the state is what they get.
        $this->postJson("/api/v1/consents/{$document}/withdrawal")->assertNoContent();
        $this->assertDatabaseCount('consent_records', 1);
    }

    /**
     * The decision the criterion leaves open, part one: **the record survives
     * the withdrawal**.
     *
     * Art. 9 part 3 puts on the operator the burden of proving that consent
     * was given. Deleting the row on withdrawal would destroy the proof that
     * the processing which happened *before* it was lawful, so the row stays
     * and the history is readable from the personal account.
     */
    public function test_a_withdrawal_keeps_the_record_of_the_consent_that_was_given(): void
    {
        Sanctum::actingAs($this->resident);

        $this->postJson('/api/v1/consents', [
            'document' => ConsentDocument::ResidentPersonalData->value,
            'revision' => $this->currentRevision,
        ])->assertStatus(201);

        $document = ConsentDocument::ResidentPersonalData->value;

        $this->postJson("/api/v1/consents/{$document}/withdrawal")->assertOk();

        // Given again, and the history is now two rows rather than one row
        // flipped back.
        $this->postJson('/api/v1/consents', [
            'document' => $document,
            'revision' => $this->currentRevision,
        ])->assertStatus(201);

        $history = $this->getJson('/api/v1/consents')->assertOk();

        $this->assertCount(2, $history->json('data'));
        $this->assertSame([true, false], array_column($history->json('data'), 'in_force'));
    }

    /**
     * The decision the criterion leaves open, part two: **the withdrawal
     * silences the processing that rested on consent, and only that**.
     *
     * The optional notification categories rest on consent (§2.7.1) and stop;
     * the mandatory ones rest on the accommodation contract and on the rules of
     * internal order, and continue.
     */
    public function test_a_withdrawal_silences_the_notifications_that_rested_on_it_and_no_others(): void
    {
        $registry = app(ConsentRegistry::class);
        $notifier = app(Notifier::class);

        $registry->record($this->resident, ConsentDocument::ResidentPersonalData, $this->currentRevision);

        Notification::fake();

        $notifier->send($this->resident->fresh(), new MaintenanceRequestStatusChanged(
            requestId: 73,
            fromStatus: 'assigned',
            toStatus: 'done',
        ));

        Notification::assertSentTo($this->resident, MaintenanceRequestStatusChanged::class);

        $registry->withdraw($this->resident, ConsentDocument::ResidentPersonalData);

        Notification::fake();

        $notifier->send($this->resident->fresh(), new MaintenanceRequestStatusChanged(
            requestId: 74,
            fromStatus: 'new',
            toStatus: 'assigned',
        ));

        $notifier->send($this->resident->fresh(), new GuestVisitOverdue(
            visitId: 41,
            guestName: 'Agafya Sviridova',
            buildingName: $this->building->name,
            dueAt: now()->setTime(23, 0),
        ));

        Notification::assertNotSentTo($this->resident, MaintenanceRequestStatusChanged::class);
        Notification::assertSentTo($this->resident, GuestVisitOverdue::class);

        $this->assertFalse(
            $this->resident->fresh()->receivesNotificationsOf(NotificationCategory::MaintenanceStatus)
        );
        $this->assertTrue(
            $this->resident->fresh()->receivesNotificationsOf(NotificationCategory::VisitOverdue)
        );
    }

    /**
     * The decision the criterion leaves open, part three: **a withdrawal does
     * not close the account**.
     *
     * Art. 9 part 1 requires consent to be free. A system that answered a
     * withdrawal by locking the resident out would be extracting consent
     * rather than receiving it, and the housing register does not rest on
     * consent in the first place — it rests on the accommodation contract
     * (art. 6 part 1 cl. 5).
     */
    public function test_a_withdrawal_closes_neither_the_account_nor_the_housing_register(): void
    {
        $registry = app(ConsentRegistry::class);

        $registry->record($this->resident, ConsentDocument::ResidentPersonalData, $this->currentRevision);
        $registry->withdraw($this->resident, ConsentDocument::ResidentPersonalData);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'resident@example.test',
            'password' => 'a-password-of-my-own',
        ])->assertOk();

        Sanctum::actingAs($this->resident->fresh());

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.consent_required', [ConsentDocument::ResidentPersonalData->value]);

        $this->getJson("/api/v1/residents/{$this->resident->id}")->assertOk();
    }

    /**
     * A text that has been superseded leaves the person pending again, and no
     * rule anywhere says so: it falls out of comparing the revision on the
     * record with the revision in force. A person agreed to a wording, not to
     * a document code.
     */
    public function test_a_consent_given_to_a_superseded_revision_is_pending_again(): void
    {
        ConsentRecord::factory()
            ->for($this->resident)
            ->revision('2026-06-01')
            ->create();

        Sanctum::actingAs($this->resident);

        $this->getJson('/api/v1/consents/pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.revision', $this->currentRevision);

        // And consenting to the new text supersedes the old record rather than
        // standing beside it: one consent of a document is in force at a time.
        $this->postJson('/api/v1/consents', [
            'document' => ConsentDocument::ResidentPersonalData->value,
            'revision' => $this->currentRevision,
        ])->assertStatus(201);

        $this->assertSame(2, ConsentRecord::query()->count());
        $this->assertSame(1, ConsentRecord::query()->inForce()->count());
    }

    public function test_a_revision_that_is_not_the_one_in_force_is_refused(): void
    {
        Sanctum::actingAs($this->resident);

        $this->postJson('/api/v1/consents', [
            'document' => ConsentDocument::ResidentPersonalData->value,
            'revision' => '2026-06-01',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('revision');

        $this->assertDatabaseCount('consent_records', 0);
    }

    /**
     * The guest's document is given at the post, in person. Recording it from
     * somebody's personal account would attach a guest's consent to a
     * resident's account, and the record would mean nothing.
     */
    public function test_the_guest_document_is_not_given_from_a_personal_account(): void
    {
        Sanctum::actingAs($this->resident);

        $this->postJson('/api/v1/consents', [
            'document' => ConsentDocument::GuestPersonalData->value,
            'revision' => app(ConsentTexts::class)->currentRevision(ConsentDocument::GuestPersonalData),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('document');

        $this->assertDatabaseCount('consent_records', 0);
    }

    /**
     * Art. 19 part 2 cl. 8 of Federal Law No. 152-FZ: the operator registers
     * and accounts for the actions performed with personal data. Giving and
     * withdrawing consent are two of them.
     */
    public function test_giving_and_withdrawing_consent_are_written_to_the_audit_log(): void
    {
        Sanctum::actingAs($this->resident);

        $this->postJson('/api/v1/consents', [
            'document' => ConsentDocument::ResidentPersonalData->value,
            'revision' => $this->currentRevision,
        ])->assertStatus(201);

        $document = ConsentDocument::ResidentPersonalData->value;

        $this->postJson("/api/v1/consents/{$document}/withdrawal")->assertOk();

        $granted = AuditLog::query()->where('action', AuditAction::ConsentGranted->value)->sole();
        $withdrawn = AuditLog::query()->where('action', AuditAction::ConsentWithdrawn->value)->sole();

        $this->assertSame($this->resident->id, $granted->user_id);
        $this->assertSame($document, $granted->payload['document']);
        $this->assertSame($this->currentRevision, $granted->payload['revision']);

        $this->assertSame($this->resident->id, $withdrawn->user_id);
        $this->assertSame($document, $withdrawn->payload['document']);
        $this->assertSame(ConsentRecord::query()->sole()->id, $withdrawn->subject_id);
    }

    public function test_the_consent_routes_need_a_session(): void
    {
        $document = ConsentDocument::ResidentPersonalData->value;

        $this->getJson('/api/v1/consents')->assertStatus(401);
        $this->getJson('/api/v1/consents/pending')->assertStatus(401);
        $this->postJson('/api/v1/consents', [])->assertStatus(401);
        $this->postJson("/api/v1/consents/{$document}/withdrawal")->assertStatus(401);
    }

    /**
     * A person reads and withdraws their own consent and nobody else's. There
     * is no parameter for another account, which is why there is no policy
     * either.
     */
    public function test_the_history_and_the_withdrawal_reach_only_the_caller_s_own_consent(): void
    {
        $registry = app(ConsentRegistry::class);
        $registry->record($this->resident, ConsentDocument::ResidentPersonalData, $this->currentRevision);

        $neighbour = User::factory()
            ->withRole(RoleCode::Resident, $this->building)
            ->create(['email' => 'neighbour@example.test']);

        Sanctum::actingAs($neighbour);

        $this->getJson('/api/v1/consents')->assertOk()->assertJsonCount(0, 'data');

        $document = ConsentDocument::ResidentPersonalData->value;
        $this->postJson("/api/v1/consents/{$document}/withdrawal")->assertNoContent();

        $this->assertTrue(
            $registry->hasInForce($this->resident->fresh(), ConsentDocument::ResidentPersonalData)
        );
    }

    /**
     * Every revision the configuration names is a file the repository holds.
     * A stand whose configuration pointed at a missing text would ask people
     * to consent to nothing and store a record proving nothing.
     */
    public function test_every_configured_revision_is_a_text_the_repository_can_produce(): void
    {
        $texts = app(ConsentTexts::class);

        foreach (ConsentDocument::cases() as $document) {
            $text = $texts->current($document);

            $this->assertNotSame('', $text->body, $document->value);
            $this->assertSame($document, $text->document);
            $this->assertTrue($texts->has($document, $text->revision));
        }
    }
}

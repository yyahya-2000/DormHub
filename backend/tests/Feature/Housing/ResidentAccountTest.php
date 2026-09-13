<?php

declare(strict_types=1);

namespace Tests\Feature\Housing;

use App\Enums\AuditAction;
use App\Enums\RoleCode;
use App\Enums\StudyStatus;
use App\Jobs\DeliverResidentCredential;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\User;
use App\Notifications\ResidentAccountIssued;
use App\Services\ResidentAccountIssuer;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * FR-42, «Issuing a resident account». One test per acceptance criterion.
 *
 * The third criterion is the one worth stating carefully, because it is easy
 * to test into meaninglessness. «A one-time credential is delivered to the
 * confirmed contact rather than shown on screen» is two claims: nothing
 * secret leaves in the response, and something did leave by the other road.
 * The first is asserted over the whole response body and not over the field
 * somebody remembered to check; the second is asserted on the notification
 * reaching the queue, which is the last fact this application controls — a
 * message arriving in a mailbox is the mail server's business, and C-03 keeps
 * every other channel out of the MVP entirely.
 */
final class ResidentAccountTest extends TestCase
{
    use RefreshDatabase;

    private Building $first;

    private Building $second;

    private User $managerOfFirst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->first = Building::factory()->create(['name' => 'Block 1']);
        $this->second = Building::factory()->create(['name' => 'Block 2']);

        $this->managerOfFirst = User::factory()
            ->withRole(RoleCode::Manager, $this->first)
            ->create(['email' => 'manager-of-first@example.test']);
    }

    /**
     * First and second criteria: the manager of a building creates an account
     * for an incoming resident of that building, and the grant it carries is
     * the resident role scoped to it.
     */
    public function test_the_manager_creates_an_account_for_an_incoming_resident_of_their_own_building(): void
    {
        Notification::fake();

        Sanctum::actingAs($this->managerOfFirst);

        $response = $this->postJson("/api/v1/buildings/{$this->first->id}/residents", [
            'full_name' => 'Taisiya Kurbatova',
            'email' => 'incoming@example.test',
            'phone' => '+79001112233',
            'study_status' => StudyStatus::Enrolled->value,
            'citizenship' => 'RU',
        ])->assertStatus(201);

        $resident = User::query()->where('email', 'incoming@example.test')->sole();

        $response
            ->assertJsonPath('data.id', $resident->id)
            ->assertJsonPath('data.full_name', 'Taisiya Kurbatova')
            ->assertJsonPath('data.password_change_required', true)
            ->assertJsonPath('data.roles.0.role', 'student')
            ->assertJsonPath('data.roles.0.building_id', $this->first->id);

        $this->assertTrue($resident->hasRoleInBuilding(RoleCode::Resident, $this->first));
        $this->assertFalse($resident->hasRoleInBuilding(RoleCode::Resident, $this->second));
        $this->assertSame(1, $resident->roleGrants()->count());
        $this->assertSame(StudyStatus::Enrolled, $resident->study_status);
        $this->assertSame('RU', $resident->citizenship);
    }

    /**
     * First criterion, the other half of the circle: the warden does the same
     * thing, because revision 2 of the role model leaves him every capability
     * the manager has.
     */
    public function test_the_warden_creates_an_account_as_well(): void
    {
        Notification::fake();

        $warden = User::factory()
            ->withRole(RoleCode::Warden, $this->first)
            ->create(['email' => 'warden-of-first@example.test']);

        Sanctum::actingAs($warden);

        $this->postJson("/api/v1/buildings/{$this->first->id}/residents", [
            'full_name' => 'Gleb Shilov',
            'email' => 'wardens-resident@example.test',
        ])->assertStatus(201);

        $resident = User::query()->where('email', 'wardens-resident@example.test')->sole();

        $this->assertTrue($resident->hasRoleInBuilding(RoleCode::Resident, $this->first));
    }

    /**
     * Third criterion, first half, and the verification's own words: «the
     * response body carries no password».
     *
     * The assertion walks the whole body rather than naming a field. A test
     * that checked `data.password` would keep passing if the secret were ever
     * added under a different name, which is the failure it exists to catch.
     */
    public function test_the_response_to_creating_a_resident_carries_no_password(): void
    {
        Notification::fake();

        Sanctum::actingAs($this->managerOfFirst);

        $response = $this->postJson("/api/v1/buildings/{$this->first->id}/residents", [
            'full_name' => 'Milana Panfilova',
            'email' => 'no-secret@example.test',
        ])->assertStatus(201);

        $this->assertNoSecretIn($response->json());

        $resident = User::query()->where('email', 'no-secret@example.test')->sole();
        $body = (string) $response->getContent();

        // Nor is the stored hash anywhere in the body, which would be the same
        // leak spelled differently.
        $this->assertStringNotContainsString($resident->getAttributes()['password_hash'], $body);

        // Nor the one-time code, which is the secret that actually exists at
        // this moment: it went to the resident's own contact, and the person
        // who created the account never sees it.
        $this->assertStringNotContainsString($this->tokenSentTo($resident), $body);
    }

    /**
     * Third criterion, second half: the credential went to the contact. The
     * notification is queued rather than sent, so what is asserted is that it
     * reached the queue addressed to the resident.
     */
    public function test_the_one_time_credential_is_queued_for_delivery_to_the_resident(): void
    {
        Notification::fake();

        Sanctum::actingAs($this->managerOfFirst);

        $this->postJson("/api/v1/buildings/{$this->first->id}/residents", [
            'full_name' => 'Savva Yakimov',
            'email' => 'delivered@example.test',
        ])->assertStatus(201);

        $resident = User::query()->where('email', 'delivered@example.test')->sole();

        Notification::assertSentTo(
            $resident,
            ResidentAccountIssued::class,
            function (ResidentAccountIssued $notification, array $channels) use ($resident): bool {
                // C-03 leaves one channel in the MVP, and it is the address on
                // the account.
                $this->assertSame(['mail'], $channels);

                // Queued, but not this object: what the queue carries is
                // `DeliverResidentCredential`, and this message is built inside
                // the worker so that the code is never serialised. Making the
                // notification queueable again would undo that in one word, so
                // the test states it.
                $this->assertNotInstanceOf(ShouldQueue::class, $notification);

                $this->assertNotSame('', $notification->token);

                // The mail carries the code itself: that is the whole point of
                // sending it down a different road from the response.
                $lines = $notification->toMail($resident)->introLines;

                $this->assertTrue(
                    collect($lines)->contains(
                        fn (string $line): bool => str_contains($line, $notification->token)
                    ),
                    'The one-time code is not in the message that carries it.',
                );

                return true;
            },
        );

        Notification::assertCount(1);
    }

    /**
     * Fourth criterion: «creating an account for another building is refused».
     */
    public function test_creating_a_resident_in_another_building_is_refused(): void
    {
        Notification::fake();

        Sanctum::actingAs($this->managerOfFirst);

        $this->postJson("/api/v1/buildings/{$this->second->id}/residents", [
            'full_name' => 'Rostislav Tregubov',
            'email' => 'elsewhere@example.test',
        ])->assertStatus(403);

        $this->assertFalse(User::query()->where('email', 'elsewhere@example.test')->exists());
        Notification::assertNothingSent();
    }

    /**
     * Second criterion: «no staff role can be issued this way». There is no
     * parameter for one, and a payload that invents it changes nothing.
     */
    public function test_no_staff_role_can_be_issued_through_this_route(): void
    {
        Notification::fake();

        Sanctum::actingAs($this->managerOfFirst);

        $this->postJson("/api/v1/buildings/{$this->first->id}/residents", [
            'full_name' => 'Vera Zheltova',
            'email' => 'not-staff@example.test',
            'role' => RoleCode::Warden->value,
        ])->assertStatus(201);

        $resident = User::query()->where('email', 'not-staff@example.test')->sole();

        $this->assertSame(1, $resident->roleGrants()->count());
        $this->assertTrue($resident->hasRoleInBuilding(RoleCode::Resident, $this->first));
        $this->assertFalse($resident->hasRoleInBuilding(RoleCode::Warden, $this->first));
    }

    public function test_a_resident_issues_no_account_and_neither_does_a_duty_officer(): void
    {
        Notification::fake();

        foreach ([RoleCode::Resident, RoleCode::DutyOfficer, RoleCode::SecurityOfficer] as $index => $role) {
            $user = User::factory()
                ->withRole($role, $this->first)
                ->create(['email' => sprintf('outsider-%d@example.test', $index)]);

            Sanctum::actingAs($user);

            $this->postJson("/api/v1/buildings/{$this->first->id}/residents", [
                'full_name' => 'Nikita Zheltov',
                'email' => sprintf('refused-%d@example.test', $index),
            ])->assertStatus(403);
        }

        Notification::assertNothingSent();
    }

    /**
     * Third criterion, the end of it: «the resident sets their own password on
     * first sign-in». Until they do, the account signs in with nothing.
     */
    public function test_the_resident_sets_their_own_password_with_the_one_time_code(): void
    {
        Notification::fake();

        Sanctum::actingAs($this->managerOfFirst);

        $this->postJson("/api/v1/buildings/{$this->first->id}/residents", [
            'full_name' => 'Polina Tregubova',
            'email' => 'first-sign-in@example.test',
        ])->assertStatus(201);

        $resident = User::query()->where('email', 'first-sign-in@example.test')->sole();

        $token = $this->tokenSentTo($resident);

        $this->postJson('/api/v1/auth/password', [
            'email' => 'first-sign-in@example.test',
            'token' => $token,
            'password' => 'a-password-of-my-own',
            'password_confirmation' => 'a-password-of-my-own',
        ])->assertStatus(204);

        $this->assertFalse($resident->fresh()->password_change_required);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'first-sign-in@example.test',
            'password' => 'a-password-of-my-own',
        ])->assertOk()->assertJsonPath('data.user.password_change_required', false);
    }

    public function test_the_one_time_code_works_once(): void
    {
        Notification::fake();

        Sanctum::actingAs($this->managerOfFirst);

        $this->postJson("/api/v1/buildings/{$this->first->id}/residents", [
            'full_name' => 'Timur Kurbatov',
            'email' => 'once@example.test',
        ])->assertStatus(201);

        $resident = User::query()->where('email', 'once@example.test')->sole();
        $token = $this->tokenSentTo($resident);

        $payload = [
            'email' => 'once@example.test',
            'token' => $token,
            'password' => 'the-first-password',
            'password_confirmation' => 'the-first-password',
        ];

        $this->postJson('/api/v1/auth/password', $payload)->assertStatus(204);
        $this->postJson('/api/v1/auth/password', $payload)->assertStatus(422);
    }

    public function test_an_invented_code_sets_no_password(): void
    {
        Notification::fake();

        Sanctum::actingAs($this->managerOfFirst);

        $this->postJson("/api/v1/buildings/{$this->first->id}/residents", [
            'full_name' => 'Alevtina Shilova',
            'email' => 'invented@example.test',
        ])->assertStatus(201);

        $this->postJson('/api/v1/auth/password', [
            'email' => 'invented@example.test',
            'token' => 'not-a-token',
            'password' => 'would-be-nice',
            'password_confirmation' => 'would-be-nice',
        ])->assertStatus(422);

        $this->assertTrue(
            User::query()->where('email', 'invented@example.test')->sole()->password_change_required
        );
    }

    /**
     * The account is created with a secret nobody holds, so it cannot be signed
     * into before the resident sets one. This is what makes the third criterion
     * a property of the data rather than a check somebody could remove.
     */
    public function test_an_account_awaiting_its_first_password_signs_in_with_nothing(): void
    {
        Notification::fake();

        Sanctum::actingAs($this->managerOfFirst);

        $this->postJson("/api/v1/buildings/{$this->first->id}/residents", [
            'full_name' => 'Arseniy Nechaev',
            'email' => 'no-way-in@example.test',
        ])->assertStatus(201);

        foreach (['password', '', 'secret'] as $attempt) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'no-way-in@example.test',
                'password' => $attempt,
            ])->assertStatus($attempt === '' ? 422 : 401);
        }
    }

    public function test_issuing_an_account_is_written_to_the_audit_log(): void
    {
        Notification::fake();

        Sanctum::actingAs($this->managerOfFirst);

        $this->postJson("/api/v1/buildings/{$this->first->id}/residents", [
            'full_name' => 'Yulia Panfilova',
            'email' => 'audited-resident@example.test',
        ])->assertStatus(201);

        $resident = User::query()->where('email', 'audited-resident@example.test')->sole();

        $entry = AuditLog::query()
            ->where('action', AuditAction::ResidentAccountIssued->value)
            ->sole();

        $this->assertSame($this->managerOfFirst->id, $entry->user_id);
        $this->assertSame($resident->id, $entry->subject_id);
        $this->assertSame($this->first->id, $entry->payload['building_id']);
        $this->assertSame('student', $entry->payload['role']);
        $this->assertNoSecretIn($entry->payload);

        // The account and the credential are two events, because they commit
        // at two moments: the account inside a transaction, the credential
        // after the queue accepted it. The second entry says where the
        // credential went and never what it was.
        $delivery = AuditLog::query()
            ->where('action', AuditAction::ResidentCredentialIssued->value)
            ->sole();

        $this->assertSame($this->managerOfFirst->id, $delivery->user_id);
        $this->assertSame($resident->id, $delivery->subject_id);
        $this->assertSame('email', $delivery->payload['delivered_to']);
        $this->assertFalse($delivery->payload['reissue']);
        $this->assertNoSecretIn($delivery->payload);
    }

    /**
     * The tenth finding of the acceptance of 14.09.2026: the log used to claim
     * a delivery from inside the transaction that created the account, while
     * the delivery itself happened after the commit. A queue that refused the
     * job left an audit entry asserting something that never took place — the
     * one thing an audit log may not do.
     *
     * The queue is made to refuse, and what is asserted is the shape of the
     * damage: the account is there, the account's own entry is there, and
     * nothing claims a credential.
     */
    public function test_a_queue_that_refuses_the_job_writes_no_record_of_a_delivery(): void
    {
        Notification::fake();

        Bus::swap(Mockery::mock(
            Dispatcher::class,
            function (MockInterface $bus): void {
                $bus->shouldReceive('dispatch')->andThrow(new RuntimeException('the queue is down'));
            },
        ));

        // The account is committed before the dispatch is attempted, and that
        // order is deliberate: a worker reaching an uncommitted row would find
        // no such user. So the refusal travels out of the service, and the row
        // stays.
        $refusal = null;

        try {
            app(ResidentAccountIssuer::class)->issue(
                actor: $this->managerOfFirst,
                building: $this->first,
                attributes: ['full_name' => 'Zoya Nechaeva', 'email' => 'queue-down@example.test'],
            );
        } catch (Throwable $thrown) {
            $refusal = $thrown;
        }

        $this->assertInstanceOf(RuntimeException::class, $refusal);
        $this->assertSame('the queue is down', $refusal->getMessage());

        $this->assertTrue(User::query()->where('email', 'queue-down@example.test')->exists());

        $this->assertSame(
            1,
            AuditLog::query()->where('action', AuditAction::ResidentAccountIssued->value)->count(),
        );

        $this->assertSame(
            0,
            AuditLog::query()->where('action', AuditAction::ResidentCredentialIssued->value)->count(),
            'The log records a delivery the queue refused.',
        );
    }

    public function test_an_address_already_in_use_is_a_malformed_request(): void
    {
        Notification::fake();

        Sanctum::actingAs($this->managerOfFirst);

        $this->postJson("/api/v1/buildings/{$this->first->id}/residents", [
            'full_name' => 'Duplicate Person',
            'email' => $this->managerOfFirst->email,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        Notification::assertNothingSent();
    }

    /**
     * The second finding of the acceptance of 14.09.2026, stated as a test.
     *
     * The notification was `ShouldQueue` and carried the code as a public
     * property, so the queue wrote the code in plain text into its own store —
     * `redis-cli monitor` read it off the wire — and into `failed_jobs`
     * whenever a delivery failed, where it stayed for good.
     *
     * What is queued now is a job carrying two model identifiers, and the code
     * is minted inside the worker. The assertion is made on the bytes the queue
     * would actually hold, and the second half of it is the stronger one: at the
     * moment the job is queued no code exists at all, so there is nothing for
     * the payload to leak.
     */
    public function test_the_queued_job_carries_no_code_because_no_code_exists_yet(): void
    {
        Queue::fake();

        Sanctum::actingAs($this->managerOfFirst);

        $this->postJson("/api/v1/buildings/{$this->first->id}/residents", [
            'full_name' => 'Lidiya Zhuravleva',
            'email' => 'nothing-on-the-wire@example.test',
        ])->assertStatus(201);

        $resident = User::query()->where('email', 'nothing-on-the-wire@example.test')->sole();

        Queue::assertPushed(
            DeliverResidentCredential::class,
            function (DeliverResidentCredential $job) use ($resident): bool {
                $this->assertTrue($job->resident->is($resident));

                // The bytes the queue store would hold. `SerializesModels`
                // reduces both models to a class name and a key.
                $payload = serialize($job);

                $this->assertStringNotContainsString('token', strtolower($payload));
                $this->assertDoesNotMatchRegularExpression(
                    '/[0-9a-f]{32,}/i',
                    $payload,
                    'Something that looks like a secret is in the queue payload.',
                );

                return true;
            },
        );

        // Nothing was minted when the job was queued: the code comes into
        // being in the worker, and a job that dies in `failed_jobs` leaves no
        // credential behind it.
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    /**
     * The third finding: a code that expired unspent used to end the account.
     * The password could not be set, no second code could be issued, the
     * account could not be removed, and `users.email` is unique — so the
     * address was held for ever by a row nobody could use.
     */
    public function test_the_office_sends_a_second_code_after_the_first_has_expired(): void
    {
        Notification::fake();

        Sanctum::actingAs($this->managerOfFirst);

        $this->postJson("/api/v1/buildings/{$this->first->id}/residents", [
            'full_name' => 'Ilya Tregubov',
            'email' => 'expired@example.test',
        ])->assertStatus(201);

        $resident = User::query()->where('email', 'expired@example.test')->sole();
        $first = $this->tokenSentTo($resident);

        $this->travel((int) config('auth.passwords.users.expire') + 1)->minutes();

        $this->postJson('/api/v1/auth/password', [
            'email' => 'expired@example.test',
            'token' => $first,
            'password' => 'too-late-for-this-one',
            'password_confirmation' => 'too-late-for-this-one',
        ])->assertStatus(422);

        // The office sends another one. 202: the delivery was accepted, and
        // whether a mail server takes it is not this application's fact.
        Notification::fake();
        Sanctum::actingAs($this->managerOfFirst);

        $this->postJson("/api/v1/buildings/{$this->first->id}/residents/{$resident->id}/credential")
            ->assertStatus(202);

        $second = $this->tokenSentTo($resident);
        $this->assertNotSame($first, $second);

        $this->postJson('/api/v1/auth/password', [
            'email' => 'expired@example.test',
            'token' => $second,
            'password' => 'a-password-of-my-own',
            'password_confirmation' => 'a-password-of-my-own',
        ])->assertStatus(204);

        $this->travelBack();

        $this->assertFalse($resident->fresh()->password_change_required);
    }

    /**
     * What happens to the old code: it stops. The token store keeps one token
     * per account, so a second code is also the revocation of the first, and
     * an office that sends a replacement does not leave two live secrets in
     * the world.
     */
    public function test_a_second_code_stops_the_first(): void
    {
        Notification::fake();

        Sanctum::actingAs($this->managerOfFirst);

        $this->postJson("/api/v1/buildings/{$this->first->id}/residents", [
            'full_name' => 'Marfa Shilova',
            'email' => 'replaced@example.test',
        ])->assertStatus(201);

        $resident = User::query()->where('email', 'replaced@example.test')->sole();
        $first = $this->tokenSentTo($resident);

        Notification::fake();
        Sanctum::actingAs($this->managerOfFirst);

        $this->postJson("/api/v1/buildings/{$this->first->id}/residents/{$resident->id}/credential")
            ->assertStatus(202);

        $this->postJson('/api/v1/auth/password', [
            'email' => 'replaced@example.test',
            'token' => $first,
            'password' => 'the-old-code-should-not-work',
            'password_confirmation' => 'the-old-code-should-not-work',
        ])->assertStatus(422);

        $this->assertTrue($resident->fresh()->password_change_required);
    }

    /**
     * The limit of the re-issue, and the reason it has one. A member of staff
     * who could send a fresh code to an account already in use would hold a
     * password reset over every resident of their building, which is a far
     * larger power than FR-42 grants. 409: the role covers the object, and
     * what stands in the way is the state of the account.
     */
    public function test_no_second_code_for_an_account_that_already_has_a_password(): void
    {
        Notification::fake();

        Sanctum::actingAs($this->managerOfFirst);

        $this->postJson("/api/v1/buildings/{$this->first->id}/residents", [
            'full_name' => 'Fedor Panfilov',
            'email' => 'settled@example.test',
        ])->assertStatus(201);

        $resident = User::query()->where('email', 'settled@example.test')->sole();

        $this->postJson('/api/v1/auth/password', [
            'email' => 'settled@example.test',
            'token' => $this->tokenSentTo($resident),
            'password' => 'a-password-of-my-own',
            'password_confirmation' => 'a-password-of-my-own',
        ])->assertStatus(204);

        Sanctum::actingAs($this->managerOfFirst);

        $this->postJson("/api/v1/buildings/{$this->first->id}/residents/{$resident->id}/credential")
            ->assertStatus(409);
    }

    public function test_no_second_code_for_a_resident_of_another_dormitory(): void
    {
        Notification::fake();

        $managerOfSecond = User::factory()
            ->withRole(RoleCode::Manager, $this->second)
            ->create(['email' => 'manager-of-second@example.test']);

        Sanctum::actingAs($managerOfSecond);

        $this->postJson("/api/v1/buildings/{$this->second->id}/residents", [
            'full_name' => 'Agata Kurbatova',
            'email' => 'over-there@example.test',
        ])->assertStatus(201);

        $resident = User::query()->where('email', 'over-there@example.test')->sole();

        // The manager of block 1 works in block 1, and in block 1 there is no
        // such resident. 404 and not 403: the scope is right, the subject is
        // not in it.
        Sanctum::actingAs($this->managerOfFirst);

        $this->postJson("/api/v1/buildings/{$this->first->id}/residents/{$resident->id}/credential")
            ->assertStatus(404);

        // And the same call against the dormitory the resident does belong to
        // is refused earlier still, by the gate on the building.
        $this->postJson("/api/v1/buildings/{$this->second->id}/residents/{$resident->id}/credential")
            ->assertStatus(403);
    }

    public function test_a_resident_asks_for_no_codes_at_all(): void
    {
        Notification::fake();

        Sanctum::actingAs($this->managerOfFirst);

        $this->postJson("/api/v1/buildings/{$this->first->id}/residents", [
            'full_name' => 'Gennadiy Zheltov',
            'email' => 'self-service@example.test',
        ])->assertStatus(201);

        $resident = User::query()->where('email', 'self-service@example.test')->sole();

        Sanctum::actingAs($resident);

        $this->postJson("/api/v1/buildings/{$this->first->id}/residents/{$resident->id}/credential")
            ->assertStatus(403);
    }

    public function test_the_second_code_is_written_to_the_audit_log_as_a_re_issue(): void
    {
        Notification::fake();

        Sanctum::actingAs($this->managerOfFirst);

        $this->postJson("/api/v1/buildings/{$this->first->id}/residents", [
            'full_name' => 'Klara Yakimova',
            'email' => 'twice-issued@example.test',
        ])->assertStatus(201);

        $resident = User::query()->where('email', 'twice-issued@example.test')->sole();

        $this->postJson("/api/v1/buildings/{$this->first->id}/residents/{$resident->id}/credential")
            ->assertStatus(202);

        $entries = AuditLog::query()
            ->where('action', AuditAction::ResidentCredentialIssued->value)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $entries);
        $this->assertFalse($entries[0]->payload['reissue']);
        $this->assertTrue($entries[1]->payload['reissue']);
        $this->assertSame($this->managerOfFirst->id, $entries[1]->user_id);
        $this->assertSame($resident->id, $entries[1]->subject_id);
        $this->assertNoSecretIn($entries[1]->payload);
    }

    /**
     * The fourth finding, answered as far as the MVP honestly can.
     *
     * Confirming an address before the first message cannot be done here: the
     * message that would carry the confirmation link is the same message that
     * carries the credential, because the account has no other way in. What the
     * system can state is the weaker fact — a code sent to this address was
     * received and spent — and it states it at the moment that happens.
     */
    public function test_the_address_counts_as_confirmed_once_a_code_sent_to_it_is_spent(): void
    {
        Notification::fake();

        Sanctum::actingAs($this->managerOfFirst);

        $this->postJson("/api/v1/buildings/{$this->first->id}/residents", [
            'full_name' => 'Nadezhda Zhuravleva',
            'email' => 'unproven@example.test',
        ])->assertStatus(201);

        $resident = User::query()->where('email', 'unproven@example.test')->sole();

        // Typed by the manager, checked for shape, proved by nobody.
        $this->assertNull($resident->email_confirmed_at);

        $this->postJson('/api/v1/auth/password', [
            'email' => 'unproven@example.test',
            'token' => $this->tokenSentTo($resident),
            'password' => 'a-password-of-my-own',
            'password_confirmation' => 'a-password-of-my-own',
        ])->assertStatus(204);

        $this->assertNotNull($resident->fresh()->email_confirmed_at);

        $entry = AuditLog::query()
            ->where('action', AuditAction::PasswordSet->value)
            ->sole();

        $this->assertTrue($entry->payload['email_confirmed']);
    }

    /**
     * The one-time code as it was actually sent.
     */
    private function tokenSentTo(User $resident): string
    {
        $token = null;

        Notification::assertSentTo(
            $resident,
            ResidentAccountIssued::class,
            function (ResidentAccountIssued $notification) use (&$token): bool {
                $token = $notification->token;

                return true;
            },
        );

        $this->assertIsString($token);

        return $token;
    }

    /**
     * No field anywhere in the structure carries a secret, at any depth.
     *
     * The list is of names a secret could plausibly travel under rather than
     * of the one name it travels under today, so a future field called
     * `temporary_password` fails this assertion on the day it is added.
     * `password_change_required` is deliberately not among them: it says an
     * account is waiting for its first password and carries no secret.
     *
     * @param  mixed  $value
     */
    private function assertNoSecretIn($value, string $path = 'root'): void
    {
        $secretNames = [
            'password', 'password_hash', 'plain_password', 'plain_text_password',
            'temporary_password', 'one_time_password', 'initial_password',
            'secret', 'credential', 'credentials', 'token', 'reset_token',
        ];

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $nested) {
            if (is_string($key)) {
                $this->assertNotContains(
                    strtolower($key),
                    $secretNames,
                    sprintf('A secret travels in the answer, at %s.%s.', $path, $key),
                );
            }

            $this->assertNoSecretIn($nested, $path.'.'.$key);
        }
    }
}

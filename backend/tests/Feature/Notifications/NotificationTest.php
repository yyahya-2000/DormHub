<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Contracts\CategorisedNotification;
use App\Enums\NotificationCategory;
use App\Enums\RoleCode;
use App\Models\Building;
use App\Models\ConsentRecord;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\DocumentAwaitingSignature;
use App\Notifications\EventNotification;
use App\Notifications\GuestRequestDecided;
use App\Notifications\GuestVisitOverdue;
use App\Notifications\MaintenanceRequestStatusChanged;
use App\Notifications\ResidentAccountIssued;
use App\Services\Notifier;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * FR-34, «Notifications». One test per acceptance criterion, and the two
 * criteria are the two things that could silently stop being true.
 *
 * The first is about **when** a message is queued, not about whether it
 * arrives: NFR-02 gives five seconds between the event and the enqueue, and
 * the enqueue is the last moment the application controls. What happens after
 * it belongs to the worker and to the mail server.
 *
 * The second is about **whether** it is queued at all, and it is the one that
 * has to hold for categories nobody has written yet. So one of the tests below
 * dispatches a notification class that does not exist anywhere in the
 * application — declared inside the test — and shows that the switch silences
 * it on the strength of its category alone. That is the claim «adding a
 * category requires no change to the dispatch», stated as an assertion rather
 * than as a comment.
 */
final class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private Building $building;

    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->building = Building::factory()->create(['name' => 'Block A']);

        $this->resident = User::factory()
            ->withRole(RoleCode::Resident, $this->building)
            ->create(['email' => 'resident@example.test']);

        // The optional categories rest on consent (§2.7.1), so a resident who
        // has given none would be silenced for a reason that has nothing to do
        // with the switch this file is about. Consent in force is the ordinary
        // state, and it is the state these tests start from; the effect of
        // withdrawing it is asserted in the FR-35 tests, where it belongs.
        ConsentRecord::factory()->for($this->resident)->create();
    }

    /**
     * First criterion: «delivery is queued within the interval of NFR-02».
     *
     * All three occasions FR-34 names that the verification asks for — a
     * decision, an overdue visit, a maintenance status change — and each of
     * them measured. The assertion is against the figure in configuration
     * rather than a literal 5, so that the requirement and the test cannot
     * drift apart.
     *
     * What is measured is the wall-clock time from the call that stands for the
     * status change to the moment the job is on the queue. It is deliberately
     * not «the notification was sent»: the delivery is queued, and a test that
     * waited for a mail server would be measuring the mail server.
     */
    public function test_delivery_is_queued_within_the_interval_of_nfr_02(): void
    {
        Queue::fake();

        $budget = (float) config('dormitory.notifications.enqueue_budget_seconds');
        $notifier = app(Notifier::class);

        $occasions = [
            NotificationCategory::RequestDecision->value => new GuestRequestDecided(
                requestId: 17,
                guestName: 'Agafya Sviridova',
                approved: true,
            ),
            NotificationCategory::VisitOverdue->value => new GuestVisitOverdue(
                visitId: 41,
                guestName: 'Agafya Sviridova',
                buildingName: $this->building->name,
                dueAt: now()->setTime(23, 0),
            ),
            NotificationCategory::MaintenanceStatus->value => new MaintenanceRequestStatusChanged(
                requestId: 73,
                fromStatus: 'assigned',
                toStatus: 'done',
            ),
        ];

        foreach ($occasions as $category => $notification) {
            $startedAt = hrtime(true);

            $notifier->send($this->resident, $notification);

            $elapsed = (hrtime(true) - $startedAt) / 1_000_000_000;

            $this->assertLessThan(
                $budget,
                $elapsed,
                sprintf(
                    'A «%s» notification took %.3f s to reach the queue; NFR-02 allows %.0f s.',
                    $category,
                    $elapsed,
                    $budget,
                ),
            );

            /*
             * The framework clones the notification once per recipient and
             * stamps it with an id, so the job does not hold the object that
             * was handed in. The class and the category are what identify it,
             * and the recipient is the person this occasion concerns.
             */
            Queue::assertPushed(
                SendQueuedNotifications::class,
                fn (SendQueuedNotifications $job): bool => $notification::class === $job->notification::class
                    && $job->notification->category()->value === $category
                    && $job->notifiables->contains(
                        fn (User $person): bool => $person->is($this->resident)
                    ),
            );
        }

        /*
         * Six jobs for three occasions, and the arithmetic is the design: the
         * framework queues one job per channel, so the in-app copy and the mail
         * travel separately and a mail server that is slow or down holds up
         * neither the other channel nor anything else. Nothing was sent inside
         * the request, which is what the five seconds of NFR-02 buy.
         */
        Queue::assertPushed(SendQueuedNotifications::class, 6);
    }

    /**
     * Second criterion: «the user can disable non-mandatory categories».
     *
     * Both halves in one test, because either alone would pass on a broken
     * implementation: a dispatch that sends nothing satisfies «the disabled
     * category does not arrive», and a dispatch that ignores the settings
     * satisfies «the mandatory one still does».
     */
    public function test_the_user_can_disable_non_mandatory_categories(): void
    {
        Sanctum::actingAs($this->resident);

        $this->putJson('/api/v1/notification-settings', [
            'categories' => [NotificationCategory::MaintenanceStatus->value => false],
        ])
            ->assertOk()
            ->assertJsonPath('data.3.category', NotificationCategory::MaintenanceStatus->value)
            ->assertJsonPath('data.3.enabled', false)
            ->assertJsonPath('data.3.mandatory', false);

        Notification::fake();

        $notifier = app(Notifier::class);

        $notifier->send($this->resident->fresh(), new MaintenanceRequestStatusChanged(
            requestId: 73,
            fromStatus: 'assigned',
            toStatus: 'done',
        ));

        $notifier->send($this->resident->fresh(), new GuestVisitOverdue(
            visitId: 41,
            guestName: 'Agafya Sviridova',
            buildingName: $this->building->name,
            dueAt: now()->setTime(23, 0),
        ));

        Notification::assertNotSentTo($this->resident, MaintenanceRequestStatusChanged::class);
        Notification::assertSentTo($this->resident, GuestVisitOverdue::class);
        Notification::assertCount(1);
    }

    /**
     * The other side of the same criterion: a category that is **not**
     * non-mandatory has no switch, and the refusal says so rather than
     * pretending to have saved something.
     */
    public function test_a_mandatory_category_cannot_be_disabled(): void
    {
        Sanctum::actingAs($this->resident);

        $this->putJson('/api/v1/notification-settings', [
            'categories' => [NotificationCategory::VisitOverdue->value => false],
        ])
            ->assertStatus(422)
            ->assertJsonPath('category', NotificationCategory::VisitOverdue->value);

        $this->assertDatabaseCount('notification_preferences', 0);

        Notification::fake();

        app(Notifier::class)->send($this->resident->fresh(), new GuestVisitOverdue(
            visitId: 41,
            guestName: 'Agafya Sviridova',
            buildingName: $this->building->name,
            dueAt: now()->setTime(23, 0),
        ));

        Notification::assertSentTo($this->resident, GuestVisitOverdue::class);
    }

    /**
     * The database refuses the same thing the service does, so a row written
     * around the service — by an import, a console command, a hand-edited
     * fixture — cannot switch off a mandatory category either.
     */
    public function test_the_database_refuses_a_preference_that_switches_off_a_mandatory_category(): void
    {
        $this->expectExceptionMessageMatches('/notification_preferences_optional_only/');

        NotificationPreference::query()->create([
            'user_id' => $this->resident->getKey(),
            'category' => NotificationCategory::DocumentSignature->value,
            'enabled' => false,
        ]);
    }

    /**
     * «Adding a category must not require a change to the dispatch.»
     *
     * The notification below is declared in this test file and exists nowhere
     * in the application, which is the point: nothing in `User::notify()`,
     * `Notifier` or `NotificationPreferences` knows it, and it is nonetheless
     * filtered correctly — on the strength of the one method the interface
     * requires. A later increment's notification is in exactly this position.
     */
    public function test_a_notification_the_dispatch_has_never_heard_of_is_filtered_by_its_category_alone(): void
    {
        Sanctum::actingAs($this->resident);

        $this->putJson('/api/v1/notification-settings', [
            'categories' => [NotificationCategory::RequestDecision->value => false],
        ])->assertOk();

        Notification::fake();

        $stranger = new class extends EventNotification
        {
            public function category(): NotificationCategory
            {
                return NotificationCategory::RequestDecision;
            }
        };

        $this->assertInstanceOf(CategorisedNotification::class, $stranger);

        app(Notifier::class)->send($this->resident->fresh(), $stranger);

        Notification::assertNothingSent();
    }

    /**
     * FR-34's first sentence: «in-app and external-channel notifications». The
     * in-app half is the framework's own table (§3.4.1, decision 7); the
     * external half is mail, and C-03 leaves no other.
     */
    public function test_a_notification_reaches_both_the_in_app_list_and_the_external_channel(): void
    {
        $notification = new GuestRequestDecided(
            requestId: 17,
            guestName: 'Agafya Sviridova',
            approved: false,
            comment: 'The visiting window is already full that evening.',
        );

        $this->assertSame(['database', 'mail'], $notification->via($this->resident));

        // The queue is `sync` under test, so the delivery happens inline and
        // the row is written by the same channel the worker would use.
        $this->resident->notify($notification);

        $stored = $this->resident->notifications()->sole();

        $this->assertSame(GuestRequestDecided::class, $stored->type);
        $this->assertSame(NotificationCategory::RequestDecision->value, $stored->data['category']);
        $this->assertSame(17, $stored->data['guest_request_id']);
        $this->assertNull($stored->read_at);

        $mail = $notification->toMail($this->resident);

        $this->assertSame('Your guest request has been refused', $mail->subject);
        $this->assertTrue(
            collect($mail->introLines)->contains(
                fn (string $line): bool => str_contains($line, 'Agafya Sviridova')
            ),
        );
    }

    /**
     * The one-time credential of FR-42 is the exception, and deliberately so:
     * it is the only notification that leaves no copy in the table, because
     * the copy would be a secret sitting in a database after its single use.
     */
    public function test_the_one_time_credential_leaves_no_copy_in_the_in_app_list(): void
    {
        $issued = new ResidentAccountIssued(
            token: 'a-one-time-code',
            buildingName: $this->building->name,
            expiresInMinutes: 60,
        );

        $this->assertSame(['mail'], $issued->via($this->resident));
        $this->assertSame(NotificationCategory::AccountIssued, $issued->category());
        $this->assertTrue($issued->category()->isMandatory());

        $this->resident->notify($issued);

        $this->assertSame(0, $this->resident->notifications()->count());
    }

    public function test_the_settings_list_every_category_with_its_mandatory_flag(): void
    {
        Sanctum::actingAs($this->resident);

        $response = $this->getJson('/api/v1/notification-settings')->assertOk();

        $this->assertCount(count(NotificationCategory::cases()), $response->json('data'));

        foreach ($response->json('data') as $row) {
            $category = NotificationCategory::from($row['category']);

            $this->assertSame($category->isMandatory(), $row['mandatory']);
            $this->assertTrue($row['enabled'], 'A category nobody has decided about is on.');
            $this->assertNotSame('', $row['label']);
            $this->assertNotSame('', $row['description']);
        }
    }

    public function test_an_unknown_category_in_the_settings_is_a_malformed_request(): void
    {
        Sanctum::actingAs($this->resident);

        $this->putJson('/api/v1/notification-settings', [
            'categories' => ['parcel_arrived' => false],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('categories.parcel_arrived');
    }

    public function test_the_personal_account_reads_its_own_messages_and_marks_them_read(): void
    {
        $this->resident->notify(new DocumentAwaitingSignature(
            documentCode: 'accommodation_agreement',
            revision: '2026-08-15',
            title: 'Accommodation agreement, annexe 2',
        ));

        Sanctum::actingAs($this->resident);

        $listed = $this->getJson('/api/v1/notifications')->assertOk();

        $this->assertCount(1, $listed->json('data'));
        $listed->assertJsonPath('data.0.category', NotificationCategory::DocumentSignature->value);
        $listed->assertJsonPath('data.0.read_at', null);

        $id = $listed->json('data.0.id');

        $this->postJson("/api/v1/notifications/{$id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.payload.document_code', 'accommodation_agreement');

        $this->assertNotNull($this->resident->notifications()->sole()->read_at);

        $this->getJson('/api/v1/notifications?unread=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * There is no route by which one account reads another's messages, and the
     * refusal is a 404 rather than a 403: a 403 would confirm the identifier
     * exists, which is more than a stranger should be told.
     */
    public function test_one_account_does_not_read_or_mark_read_the_messages_of_another(): void
    {
        $this->resident->notify(new DocumentAwaitingSignature(
            documentCode: 'accommodation_agreement',
            revision: '2026-08-15',
            title: 'Accommodation agreement, annexe 2',
        ));

        $id = $this->resident->notifications()->sole()->id;

        $neighbour = User::factory()
            ->withRole(RoleCode::Resident, $this->building)
            ->create(['email' => 'neighbour@example.test']);

        Sanctum::actingAs($neighbour);

        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/notifications/{$id}/read")->assertStatus(404);

        $this->assertNull($this->resident->notifications()->sole()->read_at);
    }

    public function test_the_messages_of_the_personal_account_need_a_session(): void
    {
        $this->getJson('/api/v1/notifications')->assertStatus(401);
        $this->getJson('/api/v1/notification-settings')->assertStatus(401);
        $this->putJson('/api/v1/notification-settings', ['categories' => []])->assertStatus(401);
    }

    /**
     * Every category is a complete entry: a case added without a label or a
     * description would leave a blank switch on the settings screen, and the
     * mandatory flag decides whether the switch is there at all.
     */
    public function test_every_category_is_complete_enough_to_be_drawn(): void
    {
        foreach (NotificationCategory::cases() as $category) {
            $this->assertNotSame('', $category->label(), $category->value);
            $this->assertNotSame('', $category->description(), $category->value);
            $this->assertSame(! $category->isMandatory(), $category->restsOnConsent(), $category->value);
        }

        $this->assertNotSame([], NotificationCategory::optional());
        $this->assertNotSame(NotificationCategory::cases(), NotificationCategory::optional());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\RoleCode;
use App\Models\Building;
use App\Models\User;
use App\Notifications\EventNotification;
use App\Notifications\GuestRequestDecided;
use App\Notifications\GuestVisitOverdue;
use App\Notifications\MaintenanceRequestStatusChanged;
use App\Services\Notifier;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * FR-34, «Notifications», as the MVP keeps it.
 *
 * The first criterion is about **when** a message is queued, not about whether
 * it arrives: NFR-02 gives five seconds between the event and the enqueue, and
 * the enqueue is the last moment the application controls. What happens after
 * it belongs to the worker and to the mail server.
 *
 * The second criterion — a switch per category — is withdrawn, and so is the
 * consent of FR-35 that used to silence part of the list. Nothing stands
 * between a dispatched message and the person it names, and the tests below
 * say so rather than leaving it to be inferred from the absence of a settings
 * test: one of them dispatches a notification class that exists nowhere in the
 * application, declared inside the test, and shows that it is delivered on the
 * strength of its category alone.
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
     * The switch is gone, the consent gate is gone, and this is the assertion
     * that says so.
     *
     * Every category of the enumeration is dispatched to a resident who has
     * set nothing and signed nothing, and every one of them arrives. Without
     * this the removal would be invisible: a dispatch that had kept a filter
     * with nothing to read would pass every other test in this file.
     */
    public function test_every_category_reaches_a_resident_who_has_set_nothing(): void
    {
        Notification::fake();

        $notifier = app(Notifier::class);

        foreach (NotificationCategory::cases() as $category) {
            $notifier->send($this->resident->fresh(), $this->notificationOf($category));
        }

        Notification::assertCount(count(NotificationCategory::cases()));
    }

    /**
     * «Adding a category must not require a change to the dispatch.»
     *
     * The notification below is declared in this test file and exists nowhere
     * in the application, which is the point: nothing in `User::notify()` or
     * `Notifier` knows it, and it is delivered all the same — on the strength
     * of the one method the interface requires. A later increment's
     * notification is in exactly this position.
     */
    public function test_a_notification_the_dispatch_has_never_heard_of_is_delivered_by_its_category_alone(): void
    {
        Notification::fake();

        $stranger = new class extends EventNotification
        {
            public function category(): NotificationCategory
            {
                return NotificationCategory::RequestDecision;
            }
        };

        app(Notifier::class)->send($this->resident->fresh(), $stranger);

        Notification::assertSentTo($this->resident, $stranger::class);
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

    public function test_the_personal_account_reads_its_own_messages_and_marks_them_read(): void
    {
        $this->resident->notify(new MaintenanceRequestStatusChanged(
            requestId: 73,
            fromStatus: 'assigned',
            toStatus: 'done',
        ));

        Sanctum::actingAs($this->resident);

        $listed = $this->getJson('/api/v1/notifications')->assertOk();

        $this->assertCount(1, $listed->json('data'));
        $listed->assertJsonPath('data.0.category', NotificationCategory::MaintenanceStatus->value);
        $listed->assertJsonPath('data.0.read_at', null);
        $listed->assertJsonPath('meta.unread_count', 1);

        $id = $listed->json('data.0.id');

        $this->postJson("/api/v1/notifications/{$id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.payload.maintenance_request_id', 73);

        $this->assertNotNull($this->resident->notifications()->sole()->read_at);

        // The message stays on the list — there is no filter that could hide it
        // — and the badge behind `unread_count` goes out.
        $reread = $this->getJson('/api/v1/notifications')->assertOk();

        $this->assertCount(1, $reread->json('data'));
        $reread->assertJsonPath('meta.unread_count', 0);
        $this->assertNotNull($reread->json('data.0.read_at'));
    }

    /**
     * The list is one page, newest first, and it holds read and unread alike.
     * The `unread` parameter the route used to take is gone; a client still
     * sending it gets the whole list rather than a filtered one, which is what
     * an ignored parameter means.
     */
    public function test_the_list_is_one_page_newest_first_and_carries_the_unread_count(): void
    {
        foreach ([3, 2, 1] as $daysAgo) {
            $this->travelTo(now()->subDays($daysAgo), function () use ($daysAgo): void {
                $this->resident->notify(new MaintenanceRequestStatusChanged(
                    requestId: 70 + $daysAgo,
                    fromStatus: 'assigned',
                    toStatus: 'done',
                ));
            });
        }

        $this->resident->notifications()->first()?->markAsRead();

        Sanctum::actingAs($this->resident);

        $listed = $this->getJson('/api/v1/notifications?unread=1')->assertOk();

        $this->assertCount(3, $listed->json('data'), 'The filter is gone: every message is on the page.');
        $this->assertSame(
            [71, 72, 73],
            array_column(array_column($listed->json('data'), 'payload'), 'maintenance_request_id'),
            'Newest first, and the oldest is the one sent three days ago.',
        );
        $listed->assertJsonPath('meta.unread_count', 2);
    }

    /**
     * There is no route by which one account reads another's messages, and the
     * refusal is a 404 rather than a 403: a 403 would confirm the identifier
     * exists, which is more than a stranger should be told.
     */
    public function test_one_account_does_not_read_or_mark_read_the_messages_of_another(): void
    {
        $this->resident->notify(new MaintenanceRequestStatusChanged(
            requestId: 73,
            fromStatus: 'assigned',
            toStatus: 'done',
        ));

        $id = $this->resident->notifications()->sole()->id;

        $neighbour = User::factory()
            ->withRole(RoleCode::Resident, $this->building)
            ->create(['email' => 'neighbour@example.test']);

        Sanctum::actingAs($neighbour);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.unread_count', 0);

        $this->postJson("/api/v1/notifications/{$id}/read")->assertStatus(404);

        $this->assertNull($this->resident->notifications()->sole()->read_at);
    }

    public function test_the_messages_of_the_personal_account_need_a_session(): void
    {
        $this->getJson('/api/v1/notifications')->assertStatus(401);
    }

    /**
     * The settings screen is gone, and so are its routes. A client built
     * against the old contract is answered 404 rather than being quietly
     * served something that looks like a saved preference.
     */
    public function test_the_settings_routes_are_gone(): void
    {
        Sanctum::actingAs($this->resident);

        $this->getJson('/api/v1/notification-settings')->assertStatus(404);
        $this->putJson('/api/v1/notification-settings', [
            'categories' => [NotificationCategory::MaintenanceStatus->value => false],
        ])->assertStatus(404);
    }

    private function notificationOf(NotificationCategory $category): EventNotification
    {
        return new class($category) extends EventNotification
        {
            public function __construct(private readonly NotificationCategory $chosen) {}

            public function category(): NotificationCategory
            {
                return $this->chosen;
            }
        };
    }
}

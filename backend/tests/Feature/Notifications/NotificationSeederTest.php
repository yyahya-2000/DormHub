<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\MaintenanceRequestStatus;
use App\Enums\NotificationCategory;
use App\Models\GuestRequest;
use App\Models\GuestVisit;
use App\Models\MaintenanceRequest;
use App\Notifications\MaintenanceRequestStatusChanged;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The personal account of the development stand, asserted rather than assumed.
 *
 * **The acceptance finding of 15.09.2026.** The seeder invented the rows its
 * messages were about — guest request 1000+n, visit 2000+n, maintenance
 * request 3000+n — and invented the statuses too: it wrote a repair moving
 * from «assigned» to «done», two words that are §3.5.2's diagram labels and
 * have never been values of `MaintenanceRequestStatus`. At the demonstration a
 * resident opened his messages and read that request 3002 had gone to «done»;
 * there was no such request and there is no such status. A stand that talks
 * about records nobody can open is a stand that cannot be shown.
 *
 * So the three tests below ask the three questions the finding raises: does
 * every status named in a message exist in the enumeration, does every
 * record a message points at exist in its table, and does the account still
 * carry one message of each category the screen draws.
 */
final class NotificationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_status_a_message_names_is_one_the_module_has(): void
    {
        $this->seed(DatabaseSeeder::class);

        $vocabulary = MaintenanceRequestStatus::values();

        $messages = DatabaseNotification::query()
            ->where('type', MaintenanceRequestStatusChanged::class)
            ->get();

        $this->assertTrue($messages->isNotEmpty(), 'The stand carries no maintenance message at all.');

        foreach ($messages as $message) {
            $this->assertContains($message->data['from_status'], $vocabulary);
            $this->assertContains($message->data['to_status'], $vocabulary);
        }
    }

    /**
     * Every message points at a row the reader can open. The identifiers used
     * to be arithmetic on the loop counter.
     */
    public function test_every_message_names_a_record_that_exists(): void
    {
        $this->seed(DatabaseSeeder::class);

        $messages = DatabaseNotification::query()->get();

        $this->assertTrue($messages->isNotEmpty(), 'The stand carries no messages at all.');

        foreach ($messages as $message) {
            $data = $message->data;

            match ($data['category']) {
                NotificationCategory::RequestDecision->value => $this->assertTrue(
                    GuestRequest::query()->whereKey($data['guest_request_id'])->exists(),
                    sprintf('Guest request %s is in a message and not in the register.', $data['guest_request_id']),
                ),
                NotificationCategory::VisitOverdue->value => $this->assertTrue(
                    GuestVisit::query()->whereKey($data['guest_visit_id'])->exists(),
                    sprintf('Visit %s is in a message and not in the register.', $data['guest_visit_id']),
                ),
                NotificationCategory::MaintenanceStatus->value => $this->assertTrue(
                    MaintenanceRequest::query()->whereKey($data['maintenance_request_id'])->exists(),
                    sprintf('Request %s is in a message and not in the queue.', $data['maintenance_request_id']),
                ),
                default => $this->fail('A message of an unexpected category is on the stand: '.$data['category']),
            };
        }
    }

    /**
     * The point of seeding messages at all: an account whose screen is worth
     * opening — the three occasions of §3.5 that reach a resident personally,
     * and one of them already read.
     *
     * Announcements and lost-and-found claims are the other two categories of
     * the enumeration and are not seeded here: an announcement reaches its
     * audience through the queued fan-out and a claim through the module's own
     * routes, and writing either by hand would be this seeder asserting
     * something the other two seeders own.
     */
    public function test_the_stand_carries_an_account_with_every_category_and_one_of_them_read(): void
    {
        $this->seed(DatabaseSeeder::class);

        $categories = [
            NotificationCategory::RequestDecision->value,
            NotificationCategory::VisitOverdue->value,
            NotificationCategory::MaintenanceStatus->value,
        ];

        $readers = DB::table('notifications')
            ->select('notifiable_id')
            ->whereIn(DB::raw("data->>'category'"), $categories)
            ->groupBy('notifiable_id')
            ->havingRaw("count(distinct data->>'category') = ?", [count($categories)])
            ->pluck('notifiable_id');

        $this->assertTrue(
            $readers->isNotEmpty(),
            'No account on the stand carries a message of every category.',
        );

        $this->assertTrue(
            DatabaseNotification::query()
                ->whereIn('notifiable_id', $readers->all())
                ->whereNotNull('read_at')
                ->exists(),
            'Every message on the stand is unread, so the screen cannot show the difference.',
        );
    }
}

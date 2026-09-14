<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Enums\MaintenanceRequestStatus;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceWorkLog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The development stand, asserted rather than assumed.
 *
 * A seeder is the one piece of the system nobody runs in anger and everybody
 * relies on at the demonstration, which is where it is discovered to be
 * broken. Four things are worth pinning down here: that the stand holds a
 * request in every state of FR-38's graph, that the two ways of closing one
 * are both present and told apart, that every request carries the history
 * §3.4.1's sixth decision exists for, and that a second run does not double
 * the queue somebody is about to walk through.
 */
final class MaintenanceSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_stand_carries_a_request_in_every_state_of_the_lifecycle(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (MaintenanceRequestStatus::cases() as $status) {
            $this->assertTrue(
                MaintenanceRequest::query()->withStatus($status)->exists(),
                sprintf('The stand has no maintenance request in status «%s».', $status->value),
            );
        }
    }

    /**
     * FR-39's second criterion is about telling the two endings apart, and a
     * stand with only one of them cannot show it.
     */
    public function test_the_stand_shows_both_ways_a_request_can_close(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(
            MaintenanceRequest::query()
                ->withStatus(MaintenanceRequestStatus::Closed)
                ->whereNotNull('confirmed_at')
                ->where('auto_closed', false)
                ->exists(),
            'The stand has no request the reporter confirmed.',
        );

        $this->assertTrue(
            MaintenanceRequest::query()
                ->withStatus(MaintenanceRequestStatus::Closed)
                ->whereNull('confirmed_at')
                ->where('auto_closed', true)
                ->exists(),
            'The stand has no request that closed for want of an answer.',
        );
    }

    /**
     * FR-40's flag needs something to point at, and waiting a month is not a
     * demonstration.
     */
    public function test_the_stand_carries_a_request_old_enough_to_be_overdue(): void
    {
        $this->seed(DatabaseSeeder::class);

        $threshold = (int) config('dormitory.maintenance.overdue_after_days');

        $this->assertTrue(
            MaintenanceRequest::query()
                ->open()
                ->get()
                ->contains(fn (MaintenanceRequest $request): bool => $request->isOverdueAt($threshold)),
            'The stand has no overdue request to show the flag on.',
        );
    }

    /**
     * §3.4.1, decision 6. A stand whose requests had statuses and no history
     * would be demonstrating exactly the design that decision rejects.
     */
    public function test_every_request_on_the_stand_carries_its_work_log(): void
    {
        $this->seed(DatabaseSeeder::class);

        $requests = MaintenanceRequest::query()->with('workLog')->get();

        $this->assertGreaterThan(0, $requests->count());

        foreach ($requests as $request) {
            $this->assertGreaterThan(
                0,
                $request->workLog->count(),
                sprintf('Request #%d has no history.', $request->getKey()),
            );

            $this->assertNull(
                $request->workLog->first()?->from_status,
                sprintf('The history of request #%d does not begin with its submission.', $request->getKey()),
            );
        }

        // The automatic closure has no author, which is the log's way of
        // saying that the calendar decided (FR-39).
        $this->assertTrue(MaintenanceWorkLog::query()
            ->whereNull('actor_id')
            ->where('to_status', MaintenanceRequestStatus::Closed->value)
            ->exists());
    }

    public function test_a_second_run_does_not_double_the_queue(): void
    {
        $this->seed(DatabaseSeeder::class);
        $first = MaintenanceRequest::query()->count();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($first, MaintenanceRequest::query()->count());
    }
}

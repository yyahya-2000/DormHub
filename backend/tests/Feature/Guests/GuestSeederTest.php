<?php

declare(strict_types=1);

namespace Tests\Feature\Guests;

use App\Enums\GuestRequestStatus;
use App\Enums\GuestVisitStatus;
use App\Models\GuestRequest;
use App\Models\GuestVisit;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The development stand, asserted rather than assumed.
 *
 * A seeder is the one piece of the system nobody runs in anger and everybody
 * relies on at the demonstration, which is where it is discovered to be
 * broken. Three things are worth pinning down: that it produces the states the
 * demonstration walks through, that a second run does not double them, and
 * that nothing it writes is a real person's data.
 */
final class GuestSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_stand_carries_a_request_in_every_state_the_demonstration_needs(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach ([
            GuestRequestStatus::PendingReview,
            GuestRequestStatus::Approved,
            GuestRequestStatus::Rejected,
            GuestRequestStatus::InProgress,
            GuestRequestStatus::Overdue,
            GuestRequestStatus::Completed,
        ] as $status) {
            $this->assertTrue(
                GuestRequest::query()->withStatus($status)->exists(),
                sprintf('The stand has no request in status «%s».', $status->value),
            );
        }

        // FR-18, FR-19: an open visit for the «who is still inside» list, a
        // closed one for the register, and an overdue one for FR-20.
        $this->assertTrue(GuestVisit::query()->where('status', GuestVisitStatus::InBuilding->value)->exists());
        $this->assertTrue(GuestVisit::query()->where('status', GuestVisitStatus::Closed->value)->exists());
        $this->assertTrue(GuestVisit::query()->whereNotNull('overdue_notified_at')->exists());
    }

    public function test_a_second_run_does_not_double_the_queue(): void
    {
        $this->seed(DatabaseSeeder::class);
        $first = GuestRequest::query()->count();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($first, GuestRequest::query()->count());
    }

    /**
     * C-05: no real personal data anywhere in the repository, fixtures and
     * seeders included. The accounts the stand writes are on `example.test`, a
     * name that cannot resolve.
     */
    public function test_the_stand_carries_no_real_account(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (GuestRequest::query()->with('student')->get() as $request) {
            $this->assertStringEndsWith('@example.test', (string) $request->student?->email);
        }
    }
}

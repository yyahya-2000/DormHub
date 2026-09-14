<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GuestRequestStatus;
use App\Guests\AccessCodeGenerator;
use App\Models\Building;
use App\Models\GuestRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Invented guests only. Constraint C-05 keeps real personal data out of the
 * repository, and the names below match nobody.
 *
 * The default interval, 14:00–18:00, sits inside clause 2.2's window on any
 * building the `BuildingFactory` makes, so a request created without states is
 * a valid one — which is what a factory is for.
 *
 * @extends Factory<GuestRequest>
 */
class GuestRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => User::factory(),
            'building_id' => Building::factory(),
            'guest_full_name' => fake()->name(),
            'visit_date' => CarbonImmutable::now()->toDateString(),
            'planned_from' => '14:00:00',
            'planned_to' => '18:00:00',
            'status' => GuestRequestStatus::PendingReview,
        ];
    }

    public function forBuilding(Building $building): static
    {
        return $this->state(fn (): array => ['building_id' => $building->getKey()]);
    }

    public function from(User $student): static
    {
        return $this->state(fn (): array => ['student_id' => $student->getKey()]);
    }

    public function on(string $date, string $from = '14:00:00', string $to = '18:00:00'): static
    {
        return $this->state(fn (): array => [
            'visit_date' => $date,
            'planned_from' => $from,
            'planned_to' => $to,
        ]);
    }

    /**
     * Approved, and therefore carrying a code: the CHECK constraint
     * `guest_requests_code_follows_approval` refuses the pair any other way,
     * which is the point of the state existing rather than two lines in every
     * test.
     */
    public function approved(?User $officer = null): static
    {
        return $this->state(fn (): array => [
            'status' => GuestRequestStatus::Approved,
            'access_code' => app(AccessCodeGenerator::class)->generate(),
            'decided_by' => $officer?->getKey(),
            'decided_at' => now(),
        ]);
    }

    public function rejected(?User $officer = null, string $reason = 'The visiting hours are over for today.'): static
    {
        return $this->state(fn (): array => [
            'status' => GuestRequestStatus::Rejected,
            'decided_by' => $officer?->getKey(),
            'decided_at' => now(),
            'decision_comment' => $reason,
        ]);
    }
}

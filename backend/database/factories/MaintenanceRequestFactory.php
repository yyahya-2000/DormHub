<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MaintenanceCategory;
use App\Enums\MaintenanceLocation;
use App\Enums\MaintenanceRequestStatus;
use App\Enums\MaintenanceUrgency;
use App\Models\Building;
use App\Models\MaintenanceRequest;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Invented defects only (C-05). The descriptions come from a fixed list rather
 * than from a text generator, so that a seeded stand reads like a dormitory's
 * queue and not like a paragraph of lorem ipsum — the point of a demonstration
 * stand is that somebody can look at it and see what the screen is for.
 *
 * **Every state is a named method and none of them writes `status` alone**,
 * because the CHECK constraints of the table refuse a half-written state: an
 * accepted request without a planned date, a closed one without a moment, a
 * row claiming both `confirmed_at` and `auto_closed`. The states below are
 * what makes a test say `->accepted()` instead of four lines that would have
 * to be got right in every test that needs one.
 *
 * @extends Factory<MaintenanceRequest>
 */
class MaintenanceRequestFactory extends Factory
{
    /** Invented defects, chosen by index rather than sampled. */
    private const DEFECTS = [
        'The mixer tap in the washbasin drips and the washer does not hold any more.',
        'The socket beside the desk sparks when a plug is pushed in.',
        'The lock of the wardrobe door has come away from the frame.',
        'The radiator stays cold while the one in the next room is hot.',
        'The wifi point in the corridor drops every few minutes on this floor.',
        'The window handle turns without catching, so the window will not lock.',
        'The shower drain in the block backs up and stands full after ten minutes.',
        'The lamp above the mirror flickers and goes out when the door is shut.',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'building_id' => Building::factory(),
            'room_id' => null,
            'reporter_id' => User::factory(),
            'category' => MaintenanceCategory::Plumbing,
            'location' => MaintenanceLocation::CommonArea,
            'location_note' => 'The shower block on the third floor',
            'title' => null,
            'description' => fake()->randomElement(self::DEFECTS),
            'urgency' => MaintenanceUrgency::Routine,
            'photo_paths' => [],
            'status' => MaintenanceRequestStatus::Submitted,
        ];
    }

    public function forBuilding(Building $building): static
    {
        return $this->state(fn (): array => ['building_id' => $building->getKey()]);
    }

    public function from(User $reporter): static
    {
        return $this->state(fn (): array => ['reporter_id' => $reporter->getKey()]);
    }

    /**
     * An «own room» request: the location and the room travel together,
     * because `maintenance_requests_location_is_coherent` refuses them apart.
     */
    public function inRoom(Room $room): static
    {
        return $this->state(fn (): array => [
            'room_id' => $room->getKey(),
            'building_id' => $room->building_id,
            'location' => MaintenanceLocation::OwnRoom,
            'location_note' => null,
        ]);
    }

    public function ofCategory(MaintenanceCategory $category): static
    {
        return $this->state(fn (): array => ['category' => $category]);
    }

    public function withUrgency(MaintenanceUrgency $urgency): static
    {
        return $this->state(fn (): array => ['urgency' => $urgency]);
    }

    /**
     * Filed `$days` ago, which is the only way to make an age — and therefore
     * FR-40's overdue flag — testable without waiting.
     */
    public function submittedDaysAgo(int $days): static
    {
        return $this->state(fn (): array => [
            'created_at' => CarbonImmutable::now()->subDays($days),
            'updated_at' => CarbonImmutable::now()->subDays($days),
        ]);
    }

    /**
     * Accepted, and therefore carrying a planned date: the CHECK constraint
     * `maintenance_requests_accepted_has_a_target_date` refuses the pair any
     * other way, which is FR-37's second criterion in the database.
     */
    public function accepted(?User $assignee = null, ?string $targetDate = null): static
    {
        return $this->state(fn (): array => [
            'status' => MaintenanceRequestStatus::Accepted,
            'target_date' => $targetDate ?? CarbonImmutable::now()->addDays(3)->toDateString(),
            'assigned_to' => $assignee?->getKey(),
            'assigned_at' => $assignee === null ? null : CarbonImmutable::now(),
        ]);
    }

    public function inProgress(?User $assignee = null): static
    {
        return $this->accepted($assignee)->state(fn (): array => [
            'status' => MaintenanceRequestStatus::InProgress,
        ]);
    }

    /**
     * Completed `$daysAgo` days ago, which is what the confirmation window of
     * FR-39 is measured from.
     */
    public function completed(?User $assignee = null, int $daysAgo = 0): static
    {
        return $this->accepted($assignee)->state(fn (): array => [
            'status' => MaintenanceRequestStatus::Completed,
            'completed_at' => CarbonImmutable::now()->subDays($daysAgo),
        ]);
    }

    /**
     * Closed because the reporter said so: the ending the module exists to
     * produce.
     */
    public function confirmed(?User $assignee = null): static
    {
        return $this->completed($assignee)->state(fn (): array => [
            'status' => MaintenanceRequestStatus::Closed,
            'confirmed_at' => CarbonImmutable::now(),
            'closed_at' => CarbonImmutable::now(),
            'auto_closed' => false,
        ]);
    }

    /**
     * Closed because nobody answered. Never `confirmed_at` as well — the CHECK
     * refuses the row that claims both, and the difference between the two
     * endings is the whole point of FR-39's second criterion.
     */
    public function autoClosed(?User $assignee = null): static
    {
        return $this->completed($assignee)->state(fn (): array => [
            'status' => MaintenanceRequestStatus::Closed,
            'confirmed_at' => null,
            'closed_at' => CarbonImmutable::now(),
            'auto_closed' => true,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => MaintenanceRequestStatus::Rejected,
            'closed_at' => CarbonImmutable::now(),
        ]);
    }
}

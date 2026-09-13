<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GuestVisitStatus;
use App\Models\GuestRequest;
use App\Models\GuestVisit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuestVisit>
 */
class GuestVisitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'guest_request_id' => GuestRequest::factory(),
            'checked_in_at' => CarbonImmutable::now(),
            'checked_in_by' => User::factory(),
            'status' => GuestVisitStatus::InBuilding,
            'due_at' => CarbonImmutable::now()->endOfDay()->setTime(23, 0),
            'admitted_on_decision' => false,
        ];
    }

    public function forRequest(GuestRequest $request, ?User $officer = null): static
    {
        return $this->state(fn (): array => [
            'guest_request_id' => $request->getKey(),
            'checked_in_by' => $officer?->getKey() ?? User::factory(),
            'due_at' => $request->dueAt(),
        ]);
    }

    public function enteredAt(string $moment): static
    {
        return $this->state(fn (): array => ['checked_in_at' => CarbonImmutable::parse($moment)]);
    }

    public function dueAt(string $moment): static
    {
        return $this->state(fn (): array => ['due_at' => CarbonImmutable::parse($moment)]);
    }

    /**
     * A visit that has been closed. The exit carries an operator because the
     * CHECK `guest_visits_exit_complete` refuses half of one.
     */
    public function closed(?User $officer = null, ?string $at = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'checked_out_at' => $at !== null ? CarbonImmutable::parse($at) : CarbonImmutable::now(),
            'checked_out_by' => $officer?->getKey() ?? $attributes['checked_in_by'],
            'status' => GuestVisitStatus::Closed,
        ]);
    }
}

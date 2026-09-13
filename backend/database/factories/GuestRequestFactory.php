<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GuestDocumentType;
use App\Enums\GuestRequestStatus;
use App\Guests\AccessCodeGenerator;
use App\Models\Building;
use App\Models\GuestRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Invented guests only. Constraint C-05 keeps real personal data out of the
 * repository, and a document number is exactly the field where a real one
 * would otherwise be pasted in «just to see it work»: the numbers below are
 * built from a counter and match nobody.
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
            'guest_doc_type' => GuestDocumentType::InternalPassport,
            'guest_doc_number' => sprintf('%04d %06d', fake()->numberBetween(1000, 9999), fake()->numberBetween(100000, 999999)),
            'is_foreign_document' => false,
            'purpose' => 'A visit to a friend',
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

    /**
     * FR-23: a foreign document. The flag is derived from the type by the
     * service, and here it is set beside the type for the same reason the
     * model repeats the migration's defaults — a factory row has to be
     * consistent before anything reads it back.
     */
    public function withForeignDocument(): static
    {
        return $this->state(fn (): array => [
            'guest_doc_type' => GuestDocumentType::ForeignPassport,
            'guest_doc_number' => sprintf('AB%07d', fake()->numberBetween(100000, 9999999)),
            'is_foreign_document' => true,
        ]);
    }
}

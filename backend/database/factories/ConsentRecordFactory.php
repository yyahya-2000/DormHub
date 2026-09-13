<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ConsentDocument;
use App\Models\ConsentRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Generated consents only (C-05). The addresses are of the documentation
 * ranges, and the revision is the one in force in the repository — a factory
 * that invented a revision identifier would produce records pointing at a text
 * that does not exist, which is precisely the state FR-35 exists to prevent.
 *
 * @extends Factory<ConsentRecord>
 */
class ConsentRecordFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'document_code' => ConsentDocument::ResidentPersonalData,
            'document_revision' => config(
                'dormitory.consent.revisions.'.ConsentDocument::ResidentPersonalData->value
            ),
            'accepted_at' => now()->subDays(fake()->numberBetween(1, 60)),
            'revoked_at' => null,
            'ip_address' => fake()->boolean() ? '192.0.2.'.fake()->numberBetween(2, 254) : null,
        ];
    }

    public function forDocument(ConsentDocument $document): static
    {
        return $this->state(fn (): array => [
            'document_code' => $document,
            'document_revision' => config('dormitory.consent.revisions.'.$document->value),
        ]);
    }

    /**
     * A consent that was given and later withdrawn. The row stays, which is
     * the whole point of the column.
     */
    public function withdrawn(): static
    {
        return $this->state(fn (array $attributes): array => [
            'revoked_at' => now(),
        ]);
    }

    public function revision(string $revision): static
    {
        return $this->state(fn (): array => ['document_revision' => $revision]);
    }
}

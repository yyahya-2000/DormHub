<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AnnouncementCategory;
use App\Models\Announcement;
use App\Models\Building;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Invented notices only (C-05).
 *
 * A row made without states is a current announcement about a planned water
 * shutoff — the ordinary case, so that a test which cares about something else
 * does not have to say so.
 *
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'building_id' => Building::factory(),
            'author_id' => User::factory(),
            'title' => 'Cold water shut off on the ninth',
            'body' => 'The riser on floors three to five is being replaced. Water returns the same evening.',
            'category' => AnnouncementCategory::Utilities->value,
            'published_at' => now(),
            'expires_at' => null,
        ];
    }

    public function forBuilding(?Building $building): static
    {
        return $this->state(fn (): array => ['building_id' => $building?->getKey()]);
    }

    /**
     * `building_id` NULL: addressed to every dormitory (§3.4.2). The
     * administrator's alone, which the policy rather than the factory
     * enforces.
     */
    public function toEveryBuilding(): static
    {
        return $this->state(fn (): array => ['building_id' => null]);
    }

    public function by(User $author): static
    {
        return $this->state(fn (): array => ['author_id' => $author->getKey()]);
    }

    /**
     * The category is a free label, so this takes one: a case of the catalogue
     * reads as `AnnouncementCategory::Safety->value`, and a heading somebody
     * typed reads as itself.
     */
    public function ofCategory(AnnouncementCategory|string $category): static
    {
        $label = $category instanceof AnnouncementCategory ? $category->value : $category;

        return $this->state(fn (): array => ['category' => $label]);
    }

    public function publishedAt(CarbonInterface $moment): static
    {
        return $this->state(fn (): array => ['published_at' => $moment]);
    }

    /**
     * A validity period. The CHECK constraint refuses an expiry at or before
     * the publication, so a state that sets only one of the two would produce
     * a row the database turns away — which is why the two travel together
     * here.
     */
    public function expiringAt(CarbonInterface $moment): static
    {
        return $this->state(fn (array $attributes): array => [
            'published_at' => $attributes['published_at'] ?? now(),
            'expires_at' => $moment,
        ]);
    }
}

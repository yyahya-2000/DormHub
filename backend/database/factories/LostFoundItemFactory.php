<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LostFoundCustody;
use App\Enums\LostFoundItemKind;
use App\Enums\LostFoundItemStatus;
use App\Models\Building;
use App\Models\LostFoundItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Invented objects only (C-05). The things come from a fixed list rather than
 * from a text generator, so that a seeded stand reads like a dormitory's
 * noticeboard and not like a paragraph of lorem ipsum — the point of a
 * demonstration stand is that somebody can look at it and see what the screen
 * is for.
 *
 * **Every state is a named method and none of them writes `status` alone**,
 * because the CHECK constraints of the table refuse a half-written state: a
 * resolved entry without a moment, a declaration dated before the finding.
 *
 * `resolved()` deliberately does **not** create the accepted claim underneath
 * it. FR-26's «only» is a rule of the service and the tests that assert it
 * build the claim through the service, which is where the rule lives; a
 * factory that quietly produced a valid-looking closure would let a test pass
 * over a code path nobody exercised.
 *
 * @extends Factory<LostFoundItem>
 */
class LostFoundItemFactory extends Factory
{
    /** Invented objects, chosen by index rather than sampled. */
    private const THINGS = [
        ['A black umbrella with a wooden handle', 'The landing between the third and fourth floors'],
        ['A student card in a blue sleeve', 'The bench beside the entrance'],
        ['A pair of headphones in a grey case', 'The reading room on the second floor'],
        ['A single leather glove, left hand', 'The staircase by the laundry'],
        ['A steel water bottle with a dented lid', 'The kitchen on the fifth floor'],
        ['A bunch of three keys on a red ring', 'The corridor outside room 305'],
        ['A paperback with a library stamp', 'The common room'],
        ['A phone charger, white, two metres', 'The socket beside the vending machine'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        [$title, $place] = self::THINGS[fake()->numberBetween(0, count(self::THINGS) - 1)];

        return [
            'building_id' => Building::factory(),
            'reporter_id' => User::factory(),
            'kind' => LostFoundItemKind::Found,
            'custody' => LostFoundCustody::Finder,
            'title' => $title,
            'description' => null,
            'place' => $place,
            'happened_on' => CarbonImmutable::now()->subDays(2)->toDateString(),
            'declared_on' => null,
            'photo_path' => null,
            'status' => LostFoundItemStatus::Published,
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

    public function of(string $title, ?string $place = null): static
    {
        return $this->state(fn (): array => array_filter([
            'title' => $title,
            'place' => $place,
        ], static fn ($value): bool => $value !== null));
    }

    public function lost(): static
    {
        return $this->state(fn (): array => ['kind' => LostFoundItemKind::Lost]);
    }

    /**
     * §2.5.4's other path: the object was handed in at the post or deposited
     * with the administration, and the staff of the dormitory answer claims on
     * it (Civil Code art. 227 cl. 1 para. 2).
     */
    public function depositedWithTheAdministration(?CarbonInterface $declaredOn = null): static
    {
        return $this->state(fn (): array => [
            'custody' => LostFoundCustody::Administration,
            'declared_on' => $declaredOn?->toDateString(),
        ]);
    }

    /**
     * The day of the finding, which is not the day of the entry. The only way
     * to make the feed's ordering testable without waiting.
     */
    public function foundDaysAgo(int $days): static
    {
        return $this->state(fn (): array => [
            'happened_on' => CarbonImmutable::now()->subDays($days)->toDateString(),
        ]);
    }

    /**
     * Declared to the police or to a local self-government body on a given
     * day (Civil Code art. 227 cl. 2). NULL by default, because most finds are
     * never declared and the column says so rather than guessing.
     */
    public function declaredOn(CarbonInterface $day): static
    {
        return $this->state(fn (): array => ['declared_on' => $day->toDateString()]);
    }

    public function claimed(): static
    {
        return $this->state(fn (): array => ['status' => LostFoundItemStatus::Claimed]);
    }

    /**
     * Closed as returned, with the moment the CHECK constraint refuses the row
     * without.
     */
    public function resolved(): static
    {
        return $this->state(fn (): array => [
            'status' => LostFoundItemStatus::Resolved,
            'resolved_at' => CarbonImmutable::now(),
        ]);
    }

    public function withPhotograph(string $path = 'lost-found/invented.png'): static
    {
        return $this->state(fn (): array => ['photo_path' => $path]);
    }
}

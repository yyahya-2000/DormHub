<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LostFoundClaimStatus;
use App\Models\LostFoundClaim;
use App\Models\LostFoundItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Invented claims only (C-05). The marks are of a kind a person really does
 * remember about their own things — a chip, a name written inside, a scratch —
 * because that is what makes a claim answerable, which is the whole substance
 * of FR-26.
 *
 * **Every state is a named method**, because the CHECK constraints of the
 * table refuse a half-written one: an acceptance without a handover point, a
 * decision without a decider, a referral without a moment.
 *
 * @extends Factory<LostFoundClaim>
 */
class LostFoundClaimFactory extends Factory
{
    /** Invented identifying marks. */
    private const MARKS = [
        'The handle is chipped on the underside and there is a strip of blue tape near the tip.',
        'My surname is written in pencil inside the front cover, on the second page.',
        'The left cup has a scratch across the logo and the case zip sticks halfway.',
        'There is a small burn mark on the cuff from a soldering iron.',
        'The lid is dented on one side; I dropped it on the stairs in September.',
        'Three keys and a supermarket token, and one of the keys has red tape round the head.',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lost_found_item_id' => LostFoundItem::factory(),
            'claimant_id' => User::factory(),
            'message' => fake()->randomElement(self::MARKS),
            'status' => LostFoundClaimStatus::New,
        ];
    }

    public function on(LostFoundItem $item): static
    {
        return $this->state(fn (): array => ['lost_found_item_id' => $item->getKey()]);
    }

    public function by(User $claimant): static
    {
        return $this->state(fn (): array => ['claimant_id' => $claimant->getKey()]);
    }

    public function describing(string $marks): static
    {
        return $this->state(fn (): array => ['message' => $marks]);
    }

    /**
     * Accepted, and therefore carrying a handover point: the CHECK constraint
     * `lost_found_claims_acceptance_names_a_place` refuses the pair any other
     * way, which is §2.4.4's «the claimant is notified with the handover
     * point» in the database.
     */
    public function accepted(User $decider, string $handoverPoint = 'Room 305, after six in the evening'): static
    {
        return $this->state(fn (): array => [
            'status' => LostFoundClaimStatus::Accepted,
            'handover_point' => $handoverPoint,
            'decided_by' => $decider->getKey(),
            'decided_at' => CarbonImmutable::now(),
        ]);
    }

    public function declined(User $decider, ?string $reason = null): static
    {
        return $this->state(fn (): array => [
            'status' => LostFoundClaimStatus::Declined,
            'decision_note' => $reason,
            'decided_by' => $decider->getKey(),
            'decided_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Declined and then put to the warden by the claimant. The refusal comes
     * first, because a referral is an edge out of `declined` and the table has
     * no other way in.
     */
    public function referred(User $decider, ?string $note = null): static
    {
        return $this->declined($decider)->state(fn (): array => [
            'status' => LostFoundClaimStatus::Referred,
            'referred_at' => CarbonImmutable::now(),
            'decision_note' => $note,
        ]);
    }
}

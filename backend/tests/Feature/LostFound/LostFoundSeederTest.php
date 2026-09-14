<?php

declare(strict_types=1);

namespace Tests\Feature\LostFound;

use App\Enums\LostFoundClaimStatus;
use App\Enums\LostFoundCustody;
use App\Enums\LostFoundItemKind;
use App\Enums\LostFoundItemStatus;
use App\Models\LostFoundClaim;
use App\Models\LostFoundItem;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The development stand, asserted rather than assumed.
 *
 * A seeder is the one piece of the system nobody runs in anger and everybody
 * relies on at the demonstration, which is where it is discovered to be
 * broken. Five things are worth pinning down here: that the stand holds an
 * entry in every state of §3.5.5 the MVP reaches, that it holds a claim in
 * every state FR-26 names, that both of §2.5.4's paths are visible, that the
 * declaration date FR-27 will one day count from is actually recorded on the
 * deposited entry, and that a second run does not double the feed somebody is
 * about to walk through.
 */
final class LostFoundSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_stand_carries_an_entry_in_every_state_the_mvp_reaches(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (LostFoundItemStatus::cases() as $status) {
            $this->assertTrue(
                LostFoundItem::query()->withStatus($status)->exists(),
                sprintf('The stand has no find in status «%s».', $status->value),
            );
        }
    }

    public function test_the_stand_carries_a_claim_in_every_state_the_requirement_names(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (LostFoundClaimStatus::cases() as $status) {
            $this->assertTrue(
                LostFoundClaim::query()->withStatus($status)->exists(),
                sprintf('The stand has no claim in status «%s».', $status->value),
            );
        }
    }

    /**
     * FR-26's second criterion is the single most easily broken behaviour in
     * the module, so the stand shows it: a declined claim on an entry that is
     * back in the published list.
     */
    public function test_the_stand_shows_a_declined_claim_on_a_find_that_is_back_in_the_published_list(): void
    {
        $this->seed(DatabaseSeeder::class);

        $declined = LostFoundClaim::query()
            ->withStatus(LostFoundClaimStatus::Declined)
            ->with('item')
            ->get();

        $this->assertTrue($declined->isNotEmpty(), 'The stand has no declined claim.');

        $this->assertTrue(
            $declined->contains(
                fn (LostFoundClaim $claim): bool => $claim->item?->status === LostFoundItemStatus::Published
            ),
            'Every declined claim on the stand sits on an entry that stayed out of the feed.',
        );
    }

    /**
     * §2.5.4's two paths, side by side: a find the resident is holding and one
     * the administration is keeping. A stand with only the first cannot show
     * why the second exists.
     */
    public function test_the_stand_shows_both_paths_a_find_can_take(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (LostFoundCustody::cases() as $custody) {
            $this->assertTrue(
                LostFoundItem::query()->where('custody', $custody->value)->exists(),
                sprintf('The stand has no find on the «%s» path.', $custody->value),
            );
        }
    }

    /**
     * §2.5.4: the declaration date cannot be retrofitted onto records created
     * without one, so the stand records it where a declaration would actually
     * have been made — on the object the administration is keeping.
     *
     * Nothing on the stand counts the six months of Civil Code art. 228 cl. 1:
     * FR-27 is outside the MVP.
     */
    public function test_the_deposited_find_carries_the_declaration_date_and_the_others_do_not(): void
    {
        $this->seed(DatabaseSeeder::class);

        $declared = LostFoundItem::query()->whereNotNull('declared_on')->get();

        $this->assertTrue($declared->isNotEmpty(), 'The stand records no declaration at all.');

        foreach ($declared as $item) {
            $this->assertSame(LostFoundCustody::Administration, $item->custody);
            $this->assertTrue(
                $item->declared_on?->greaterThanOrEqualTo($item->happened_on) === true,
                'A declaration on the stand precedes the finding it is about.',
            );
        }

        $this->assertTrue(
            LostFoundItem::query()
                ->where('custody', LostFoundCustody::Finder->value)
                ->whereNull('declared_on')
                ->exists(),
            'Every find on the stand carries a declaration, which most finds never do.',
        );
    }

    /**
     * The ER model carries both directions of the notice (§3.4.3, «lost or
     * found»), and a stand with only one of them shows half a feed.
     */
    public function test_the_stand_shows_both_directions_of_the_notice(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (LostFoundItemKind::cases() as $kind) {
            $this->assertTrue(
                LostFoundItem::query()->ofKind($kind)->exists(),
                sprintf('The stand has no entry of kind «%s».', $kind->value),
            );
        }

        // And no claim on a loss: a claim is «that is mine», and the author of
        // a loss is holding nothing.
        $this->assertFalse(
            LostFoundClaim::query()
                ->whereHas('item', fn ($item) => $item->where('kind', LostFoundItemKind::Lost->value))
                ->exists(),
        );
    }

    public function test_a_second_run_does_not_double_the_feed(): void
    {
        $this->seed(DatabaseSeeder::class);

        $items = LostFoundItem::query()->count();
        $claims = LostFoundClaim::query()->count();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($items, LostFoundItem::query()->count());
        $this->assertSame($claims, LostFoundClaim::query()->count());
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\LostFoundClaimStatus;
use App\Enums\LostFoundCustody;
use App\Enums\LostFoundItemKind;
use App\Enums\LostFoundItemStatus;
use App\Enums\Permission;
use App\Enums\RoleCode;
use App\Models\Building;
use App\Models\LostFoundClaim;
use App\Models\LostFoundItem;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;

/**
 * The lost-and-found module on the development stand: an entry in every state
 * §3.5.5 draws inside the MVP, and a claim in every state FR-26 names.
 *
 * **Everything here is invented (C-05).** The objects are of a kind a
 * dormitory really does lose and belong to nobody; the identifying marks are
 * of a kind a person really does remember about their own things, because
 * that is what makes a claim answerable and answerability is the whole
 * substance of FR-26. The people are the ones the housing seeder already made.
 *
 * **Every state, and why each of them is here.** A plain published find,
 * because the feed is empty without one and the feed is the resident's screen.
 * A find with a claim waiting, because that is the state the person holding
 * the object has to answer and the one the module is built around. A find
 * whose claim was accepted but which has not changed hands yet, because the
 * gap between the acceptance and the handover is where FR-26's «only» lives. A
 * find that has gone home, because FR-26's third criterion is about it leaving
 * the list and a stand without one cannot show the list shortening. A find
 * whose claim was declined and which is back in the feed, because that is
 * FR-26's second criterion and the single most easily broken behaviour in the
 * module. A refused claim now referred to the warden, because §2.5.4's first
 * exception is invisible otherwise. An object deposited with the
 * administration with a declaration recorded against it, because that is
 * §2.5.4's second exception and the only path Civil Code art. 227 cl. 1
 * para. 2 governs. And a notice of a loss, because the ER model carries both
 * directions of the notice.
 *
 * **What is deliberately absent.** Nothing here sets a retention flag or
 * counts the six months of art. 228 cl. 1: FR-27 is outside the MVP, and a
 * stand that showed a control the application does not run would be
 * demonstrating a system that does not exist. The declaration date is seeded
 * on the deposited entry so that whoever implements FR-27 has something to
 * count from.
 */
class LostFoundSeeder extends Seeder
{
    /** Invented objects, paired with the place they turned up. */
    private const THINGS = [
        ['A black umbrella with a wooden handle', 'The landing between the third and fourth floors'],
        ['A bunch of three keys on a red ring', 'The corridor outside room 305'],
        ['A pair of headphones in a grey case', 'The reading room on the second floor'],
        ['A steel water bottle with a dented lid', 'The kitchen on the fifth floor'],
        ['A single leather glove, left hand', 'The staircase by the laundry'],
        ['A student card in a blue sleeve', 'Handed in at the security post'],
        ['A paperback with a library stamp', 'The common room'],
        ['A phone charger, white, two metres', 'The socket beside the vending machine'],
    ];

    public function run(): void
    {
        $building = Building::query()->orderBy('id')->first();

        if ($building === null) {
            return;
        }

        $residents = $this->residentsOf($building);
        $keeper = $this->staffOf($building, Permission::HoldLostFoundItems);
        $warden = $this->staffOf($building, Permission::DecideLostFoundDisputes);

        if (count($residents) < 2) {
            // Nothing to seed against, exactly as the notification, guest and
            // maintenance seeders decide in the same situation. The
            // module needs two residents and not one: every scenario in it is
            // one person answering another.
            return;
        }

        if (LostFoundItem::query()->where('building_id', $building->getKey())->exists()) {
            // Idempotent in the only way that matters: a second run must not
            // double the feed the demonstration is walked through.
            return;
        }

        $today = CarbonImmutable::now();

        [$finder, $owner] = $residents;
        $third = $residents[2] ?? $owner;

        $this->published($building, $finder, $today, 0);
        $this->claimed($building, $finder, $owner, $today, 1);
        $this->accepted($building, $finder, $owner, $today, 2);
        $this->resolved($building, $finder, $owner, $today, 3);
        $this->declinedAndBackInTheFeed($building, $finder, $third, $today, 4);

        if ($keeper !== null) {
            $this->deposited($building, $keeper, $owner, $today, 5);
        }

        if ($warden !== null) {
            $this->referred($building, $finder, $owner, $today, 6);
        }

        $this->lost($building, $owner, $today, 7);
    }

    /**
     * A find nobody has claimed: the plain state of the feed.
     */
    private function published(Building $building, User $finder, CarbonImmutable $day, int $index): void
    {
        $this->make($building, $finder, $index, $day->subDays(2));
    }

    /**
     * The state the module is built around: somebody has said «that is mine»
     * and the person holding the object has yet to answer.
     */
    private function claimed(
        Building $building,
        User $finder,
        User $claimant,
        CarbonImmutable $day,
        int $index,
    ): void {
        $item = $this->make($building, $finder, $index, $day->subDays(4), [
            'status' => LostFoundItemStatus::Claimed,
        ]);

        $this->claim($item, $claimant, $day->subDays(3), [
            'message' => 'Mine has a supermarket token on the ring and one key wrapped in red tape.',
        ]);
    }

    /**
     * Accepted and not yet handed over: the gap FR-26's «only» lives in — the
     * closure is now admissible and has not happened.
     */
    private function accepted(
        Building $building,
        User $finder,
        User $claimant,
        CarbonImmutable $day,
        int $index,
    ): void {
        $item = $this->make($building, $finder, $index, $day->subDays(3), [
            'status' => LostFoundItemStatus::Claimed,
        ]);

        $this->claim($item, $claimant, $day->subDays(2), [
            'message' => 'The left cup has a scratch across the logo and the case zip sticks halfway.',
            'status' => LostFoundClaimStatus::Accepted,
            'handover_point' => 'Room 412, after six in the evening',
            'decided_by' => $finder->getKey(),
            'decided_at' => $day->subDay(),
        ]);
    }

    /**
     * Gone home. FR-26's third criterion is about this entry not being in the
     * feed, and a stand without one cannot show the list shortening.
     */
    private function resolved(
        Building $building,
        User $finder,
        User $claimant,
        CarbonImmutable $day,
        int $index,
    ): void {
        $item = $this->make($building, $finder, $index, $day->subDays(12), [
            'status' => LostFoundItemStatus::Resolved,
            'resolved_at' => $day->subDays(9),
        ]);

        $this->claim($item, $claimant, $day->subDays(11), [
            'message' => 'The lid is dented on one side; I dropped it on the stairs in September.',
            'status' => LostFoundClaimStatus::Accepted,
            'handover_point' => 'Room 412, any evening this week',
            'decided_by' => $finder->getKey(),
            'decided_at' => $day->subDays(10),
        ]);
    }

    /**
     * FR-26's second criterion, which is the single most easily broken
     * behaviour in the module: the claim was declined and the entry is back in
     * the published list.
     */
    private function declinedAndBackInTheFeed(
        Building $building,
        User $finder,
        User $claimant,
        CarbonImmutable $day,
        int $index,
    ): void {
        $item = $this->make($building, $finder, $index, $day->subDays(6));

        $this->claim($item, $claimant, $day->subDays(5), [
            'message' => 'There is a small burn mark on the cuff from a soldering iron.',
            'status' => LostFoundClaimStatus::Declined,
            'decision_note' => 'The glove I picked up has no mark of that kind on it.',
            'decided_by' => $finder->getKey(),
            'decided_at' => $day->subDays(4),
        ]);
    }

    /**
     * §2.5.4's first exception: a refusal the claimant did not accept, now
     * with the warden.
     */
    private function referred(
        Building $building,
        User $finder,
        User $claimant,
        CarbonImmutable $day,
        int $index,
    ): void {
        $item = $this->make($building, $finder, $index, $day->subDays(8), [
            'status' => LostFoundItemStatus::Claimed,
        ]);

        $this->claim($item, $claimant, $day->subDays(7), [
            'message' => 'My surname is written in pencil inside the front cover, on the second page.',
            'status' => LostFoundClaimStatus::Referred,
            'decision_note' => 'I would like the warden to look at the book.',
            'decided_by' => $finder->getKey(),
            'decided_at' => $day->subDays(6),
            'referred_at' => $day->subDays(5),
        ]);
    }

    /**
     * §2.5.4's second exception, and the one path Civil Code art. 227 cl. 1
     * para. 2 governs: an object handed in at the post and kept by the
     * administration, with a declaration recorded against it.
     *
     * The declaration date is the day the find was declared to the police or
     * to a local self-government body (art. 227 cl. 2). It is seeded because
     * FR-27 will one day count six months from it, and never from the day the
     * row was written.
     */
    private function deposited(
        Building $building,
        User $keeper,
        User $claimant,
        CarbonImmutable $day,
        int $index,
    ): void {
        $item = $this->make($building, $keeper, $index, $day->subDays(20), [
            'custody' => LostFoundCustody::Administration,
            'declared_on' => $day->subDays(18)->toDateString(),
            'status' => LostFoundItemStatus::Claimed,
        ]);

        $this->claim($item, $claimant, $day->subDays(2), [
            'message' => 'The sleeve is blue and the card has a bent corner at the top right.',
        ]);
    }

    /**
     * The other direction of the notice (§3.4.3, «lost or found»). No claim
     * belongs on it: a claim is «that is mine», and the author of a loss is
     * holding nothing.
     */
    private function lost(Building $building, User $owner, CarbonImmutable $day, int $index): void
    {
        $this->make($building, $owner, $index, $day->subDays(1), [
            'kind' => LostFoundItemKind::Lost,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function make(
        Building $building,
        User $reporter,
        int $index,
        CarbonImmutable $publishedAt,
        array $overrides = [],
    ): LostFoundItem {
        [$title, $place] = self::THINGS[$index % count(self::THINGS)];

        $item = LostFoundItem::query()->create(array_merge([
            'building_id' => $building->getKey(),
            'reporter_id' => $reporter->getKey(),
            'kind' => LostFoundItemKind::Found,
            'custody' => LostFoundCustody::Finder,
            'title' => $title,
            'place' => $place,
            // The day of the finding, a day before the entry: the two are not
            // the same fact, and a stand where they always coincide would
            // teach the reader that they are.
            'happened_on' => $publishedAt->subDay()->toDateString(),
            'status' => LostFoundItemStatus::Published,
        ], $overrides));

        $item->forceFill(['created_at' => $publishedAt, 'updated_at' => $publishedAt])->save();

        return $item;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function claim(
        LostFoundItem $item,
        User $claimant,
        CarbonImmutable $filedAt,
        array $overrides = [],
    ): LostFoundClaim {
        $claim = LostFoundClaim::query()->create(array_merge([
            'lost_found_item_id' => $item->getKey(),
            'claimant_id' => $claimant->getKey(),
            'message' => 'I can describe the marks on it.',
            'status' => LostFoundClaimStatus::New,
        ], $overrides));

        $claim->forceFill(['created_at' => $filedAt, 'updated_at' => $filedAt])->save();

        return $claim;
    }

    /**
     * @return list<User>
     */
    private function residentsOf(Building $building): array
    {
        return User::query()
            ->whereHas('roleGrants', fn (Builder $grant) => $grant
                ->where('building_id', $building->getKey())
                ->whereHas('role', fn (Builder $role) => $role->where('code', RoleCode::Resident->value)))
            ->orderBy('id')
            ->limit(3)
            ->get()
            ->all();
    }

    /**
     * Whoever holds `$permission` in this dormitory — asked as a capability
     * rather than as a list of role names, for the reason §3.3.3 gives.
     */
    private function staffOf(Building $building, Permission $permission): ?User
    {
        $codes = array_values(array_map(
            static fn (RoleCode $code): string => $code->value,
            array_filter(
                RoleCode::cases(),
                static fn (RoleCode $code): bool => $code->grants($permission),
            ),
        ));

        if ($codes === [] || Role::query()->whereIn('code', $codes)->doesntExist()) {
            return null;
        }

        return User::query()
            ->whereHas('roleGrants', fn (Builder $grant) => $grant
                ->where('building_id', $building->getKey())
                ->whereHas('role', fn (Builder $role) => $role->whereIn('code', $codes)))
            ->orderBy('id')
            ->first();
    }
}

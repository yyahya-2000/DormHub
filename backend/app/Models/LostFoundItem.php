<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LostFoundClaimStatus;
use App\Enums\LostFoundCustody;
use App\Enums\LostFoundItemKind;
use App\Enums\LostFoundItemStatus;
use Database\Factories\LostFoundItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * LOST_FOUND_ITEM of the ER model (§3.4.3).
 *
 * What the model owns is the one question the whole module turns on: **whose
 * decision is a claim on this entry**. §2.5.4 answers it in two sentences —
 * the finder keeps the object and decides, and the administration decides for
 * an object deposited with it — and the answer is needed by the policy, by the
 * service and by the notification dispatch. Computed in three places it would
 * be three answers, and the one that drifted would be the one that let
 * somebody else give away a resident's umbrella.
 *
 * **`decisionRestsWith()` returns an identifier and never a name.** FR-25
 * keeps the registering user out of the card altogether, and the way that is
 * held is that `LostFoundItemResource` never serialises `reporter_id` while
 * the domain keeps using it. A method returning a `User` would be the natural
 * place for a later edit to reach a full name.
 */
#[Fillable([
    'building_id',
    'reporter_id',
    'kind',
    'custody',
    'title',
    'description',
    'place',
    'happened_on',
    'declared_on',
    'photo_path',
    'status',
    'resolved_at',
])]
class LostFoundItem extends Model
{
    /** @use HasFactory<LostFoundItemFactory> */
    use HasFactory;

    /**
     * The migration's defaults, repeated so that an entry is complete in
     * memory and not only after a round trip — the same reason `Building`,
     * `Room` and `MaintenanceRequest` repeat theirs.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => 'found',
        'custody' => 'finder',
        'status' => 'published',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'building_id' => 'integer',
            'reporter_id' => 'integer',
            'kind' => LostFoundItemKind::class,
            'custody' => LostFoundCustody::class,
            'status' => LostFoundItemStatus::class,
            'happened_on' => 'date',
            'declared_on' => 'date',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Building, $this>
     */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /**
     * The person who published the entry — the finder, or the member of staff
     * who took the object in.
     *
     * **Never serialised** (FR-25). The relation exists because the service
     * and the policy ask who decides; `LostFoundItemResource` does not touch
     * it, and the test named in §4.6.2 asserts the field's absence from the
     * body.
     *
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /**
     * The claims made against the entry, oldest first.
     *
     * §3.5.5: «Several claims may coexist. The entry returns to Published if
     * none of them is confirmed.»
     *
     * @return HasMany<LostFoundClaim, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(LostFoundClaim::class)->orderBy('id');
    }

    /**
     * Who answers a claim on this entry (§2.5.4).
     *
     * Null on the deposited path: the decision belongs to the staff of the
     * dormitory as a circle rather than to one account, because the officer
     * who took the object in on Friday is not on shift on Monday. The policy
     * turns the null into the capability check, and the service turns it into
     * the list of people who are notified.
     */
    public function decisionRestsWith(): ?int
    {
        return $this->custody?->restsWithTheAdministration() === true
            ? null
            : (int) $this->reporter_id;
    }

    /**
     * Whether this account is the person the entry's decisions belong to.
     *
     * False for every account on the deposited path, capability or not — the
     * capability is a separate question and `LostFoundItemPolicy` asks it
     * separately, so that neither half can be mistaken for the other.
     */
    public function isDecidedBy(User|int $user): bool
    {
        $holder = $this->decisionRestsWith();

        return $holder !== null
            && $holder === ($user instanceof User ? (int) $user->getKey() : $user);
    }

    /**
     * Whether the entry is in the public list of FR-25 at all.
     */
    public function isInTheFeed(): bool
    {
        return $this->status?->isVisibleInTheFeed() === true;
    }

    /**
     * FR-26, first criterion: closure is admitted «only on a claim the finder
     * accepted or on a warden's decision on a referred claim», and both of
     * those end at one place — a claim in status `accepted`.
     *
     * Asked of the loaded relation where there is one, so that a controller
     * that has just eager-loaded the claims does not send the same query
     * again.
     */
    public function hasAnAcceptedClaim(): bool
    {
        if ($this->relationLoaded('claims')) {
            return $this->claims->contains(
                fn (LostFoundClaim $claim): bool => $claim->status === LostFoundClaimStatus::Accepted
            );
        }

        return $this->claims()
            ->where('status', LostFoundClaimStatus::Accepted->value)
            ->exists();
    }

    /**
     * Whether anybody is still waiting on an answer.
     *
     * FR-26's second criterion — «a declined claim returns the find to the
     * published list» — is this question asked after each refusal: the entry
     * goes back into the feed once nothing is outstanding on it, and stays out
     * of it while a referral is with the warden.
     */
    public function hasAnOutstandingClaim(): bool
    {
        return $this->claims()
            ->whereIn('status', array_map(
                static fn (LostFoundClaimStatus $status): string => $status->value,
                LostFoundClaimStatus::outstanding(),
            ))
            ->exists();
    }

    /**
     * Entries of one dormitory. Every building-scoped question the feed asks
     * goes through this, so there is one place the horizontal boundary of
     * FR-07 is expressed in SQL.
     *
     * @param  Builder<LostFoundItem>  $query
     */
    public function scopeInBuilding(Builder $query, Building|int $building): void
    {
        $query->where(
            'building_id',
            $building instanceof Building ? $building->getKey() : $building,
        );
    }

    /**
     * @param  Builder<LostFoundItem>  $query
     * @param  list<int>  $buildingIds
     */
    public function scopeInBuildings(Builder $query, array $buildingIds): void
    {
        $query->whereIn('building_id', $buildingIds);
    }

    /**
     * FR-26, third criterion: «after closure the record disappears from the
     * public list». One scope, used by the feed and by nothing else, so there
     * is a single sentence of SQL behind the criterion.
     *
     * @param  Builder<LostFoundItem>  $query
     */
    public function scopeVisibleInTheFeed(Builder $query): void
    {
        $query->whereIn('status', array_map(
            static fn (LostFoundItemStatus $status): string => $status->value,
            LostFoundItemStatus::visibleInTheFeed(),
        ));
    }

    /**
     * @param  Builder<LostFoundItem>  $query
     */
    public function scopeWithStatus(Builder $query, LostFoundItemStatus ...$statuses): void
    {
        $query->whereIn('status', array_map(
            static fn (LostFoundItemStatus $status): string => $status->value,
            $statuses,
        ));
    }

    /**
     * @param  Builder<LostFoundItem>  $query
     */
    public function scopeOfKind(Builder $query, LostFoundItemKind $kind): void
    {
        $query->where('kind', $kind->value);
    }
}

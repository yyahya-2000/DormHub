<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LostFoundItemKind;
use App\Enums\LostFoundItemStatus;
use App\Models\LostFoundItem;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The read side of the lost-and-found module (FR-25), named as §3.3.6 names
 * the other read-side classes.
 *
 * *Purpose*: the list of finds a resident sees, and the one question that
 * decides which finds those are. *Dependencies*: the models only; no
 * controller and no HTTP object (§3.3.1).
 *
 * **«Their own dormitory» is computed from the token and is never a parameter**
 * (FR-25, first criterion). There is no `building_id` on the route, so there
 * is no way to phrase a request for another dormitory's finds — the boundary
 * is a missing parameter rather than a policy somebody has to remember to
 * call. It is the arrangement the announcement feed uses, and it rests on the
 * same two facts: the grant that names a dormitory, and the residency register
 * that says whether the person has left it (FR-05).
 *
 * **FR-26's third criterion is a scope and not a habit.** «After closure the
 * record disappears from the public list» — so `visibleInTheFeed()` is the one
 * sentence of SQL behind it, and the status filter below cannot widen past it:
 * a client asking for `resolved` is asking for something the feed does not
 * have, and the form request refuses the value rather than this method
 * quietly returning nothing.
 *
 * **The default is `published` alone, which is what «the published list»
 * means.** FR-26's second criterion — «a declined claim returns the find to
 * the published list» — only says something if a claimed entry is not in that
 * list, so it is not: an entry somebody has claimed is read by asking for it,
 * and the resident scrolling the feed is shown what is actually available.
 */
final readonly class LostFoundFeed
{
    public function __construct(private int $pageSize) {}

    /**
     * FR-25: the feed.
     */
    public function feed(
        User $reader,
        ?LostFoundItemStatus $status = null,
        ?LostFoundItemKind $kind = null,
        ?string $search = null,
        ?int $perPage = null,
    ): LengthAwarePaginator {
        $query = LostFoundItem::query()->with('building')->visibleInTheFeed();

        $buildingIds = $this->readableBuildingIdsFor($reader);

        if ($buildingIds !== null) {
            $query->inBuildings($buildingIds);
        }

        $query->withStatus($status ?? LostFoundItemStatus::Published);

        if ($kind !== null) {
            $query->ofKind($kind);
        }

        if ($search !== null && trim($search) !== '') {
            /*
             * A LIKE over the title and the place, and deliberately nothing
             * cleverer. A resident looking for a black umbrella types «umbrella»
             * and a dormitory's feed is hundreds of rows rather than millions;
             * a full-text index would be a tuning decision taken against a
             * table that does not exist yet.
             */
            $needle = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($search)).'%';

            $query->where(function ($scoped) use ($needle): void {
                $scoped->where('title', 'like', $needle)
                    ->orWhere('place', 'like', $needle);
            });
        }

        return $query
            // Newest find first, by the day it was found rather than by the
            // day it was entered: that is the date the reader is shown, and a
            // list that sorted by one and displayed the other would look
            // shuffled. The identifier breaks the tie, because two finds of
            // the same day must not swap places between one page and the next.
            ->orderByDesc('happened_on')
            ->orderByDesc('id')
            ->paginate($perPage ?? $this->pageSize);
    }

    /**
     * The dormitories whose feed this account reads, or null for every one of
     * them.
     *
     * Null is the administrator, whose grant names no building. Everyone else
     * is confined to the buildings their grants name, minus the ones the
     * housing register says they have left — FR-05 closes the building-bound
     * functions on the stated departure date, and a feed of finds in a
     * dormitory somebody no longer lives in is exactly such a function.
     *
     * @return list<int>|null
     */
    public function readableBuildingIdsFor(User $reader): ?array
    {
        if ($reader->isAdministrator()) {
            return null;
        }

        return array_values(array_filter(
            $reader->scopedBuildingIds(),
            fn (int $buildingId): bool => ! $reader->hasMovedOutOf($buildingId),
        ));
    }

    /**
     * Whether this account is attached to the dormitory an entry belongs to —
     * the question `LostFoundItemPolicy` asks about a single card, phrased
     * against the same definition the list is built on so that the two cannot
     * disagree.
     */
    public function reaches(User $reader, LostFoundItem $item): bool
    {
        $buildingIds = $this->readableBuildingIdsFor($reader);

        return $buildingIds === null
            || in_array((int) $item->building_id, $buildingIds, true);
    }
}

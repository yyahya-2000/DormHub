<?php

declare(strict_types=1);

namespace App\Guests;

use App\Enums\GuestRequestStatus;
use App\Exceptions\GuestQuotaExceededException;
use App\Models\GuestRequest;
use Carbon\CarbonInterface;

/**
 * `GuestQuotaChecker` of §3.3.4, and the one line of the approval that is a
 * house rule rather than a norm.
 *
 * **Counted at approval and not at submission**, which is the whole of the
 * design. A resident may ask for as many visits as they like; what the
 * dormitory limits is how many guests are actually admitted on a given day,
 * and that number is settled by the decision on a request. Counting at
 * submission would refuse a resident who has two pending requests and no
 * approvals — telling them they are over a ceiling nobody has yet let them
 * reach — and would let two officers approve past the ceiling in the same
 * minute, because nothing would be counted at the moment that matters.
 *
 * **What counts as spent.** Every request for that day whose decision went the
 * guest's way and has not been withdrawn: approved, in progress, overdue,
 * completed. A cancelled approval gives the place back, which is the only
 * behaviour a resident could predict. A rejected one never took a place.
 *
 * The count runs inside the approval's transaction, under the row lock
 * `GuestRequestService` takes, which is what stops two officers approving the
 * same resident's third guest at once.
 */
final readonly class GuestQuota
{
    public function __construct(
        private int $perResident,
        private int $perBuilding,
    ) {}

    /**
     * @throws GuestQuotaExceededException
     */
    public function assertRoomFor(GuestRequest $request): void
    {
        $date = $request->visit_date;

        if ($date === null) {
            return;
        }

        if ($this->perResident > 0) {
            $mine = $this->countSpent($request, $date, forResident: true);

            if ($mine >= $this->perResident) {
                throw GuestQuotaExceededException::forResident(
                    limit: $this->perResident,
                    approved: $mine,
                    onDate: $date->toDateString(),
                );
            }
        }

        if ($this->perBuilding > 0) {
            $theirs = $this->countSpent($request, $date, forResident: false);

            if ($theirs >= $this->perBuilding) {
                throw GuestQuotaExceededException::forBuilding(
                    limit: $this->perBuilding,
                    approved: $theirs,
                    onDate: $date->toDateString(),
                );
            }
        }
    }

    /**
     * The request being decided is excluded from its own count: it is not
     * approved yet, and if it ever were the ceiling would be one lower than
     * the number configured.
     */
    private function countSpent(GuestRequest $request, CarbonInterface $date, bool $forResident): int
    {
        $query = GuestRequest::query()
            ->whereKeyNot($request->getKey())
            ->whereDate('visit_date', $date->toDateString())
            ->withStatus(
                GuestRequestStatus::Approved,
                GuestRequestStatus::InProgress,
                GuestRequestStatus::Overdue,
                GuestRequestStatus::Completed,
            );

        return $forResident
            ? $query->where('student_id', $request->student_id)->count()
            : $query->where('building_id', $request->building_id)->count();
    }
}

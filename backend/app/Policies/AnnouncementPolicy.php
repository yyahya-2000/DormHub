<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Announcement;
use App\Models\Building;
use App\Models\User;
use App\Services\AnnouncementQuery;

/**
 * FR-09, FR-11 and FR-12 over one announcement.
 *
 * The policy names no role (§3.3.3). It asks `BuildingPolicy` for a
 * capability, and `RoleCode::permissions()` says which roles carry it — so
 * «the warden and the manager of their own dormitory publish, the
 * administrator publishes everywhere» is stated once, in the capability map,
 * and not repeated in four methods that could drift apart.
 */
final class AnnouncementPolicy
{
    public function __construct(
        private readonly BuildingPolicy $buildings,
        private readonly AnnouncementQuery $audience,
    ) {}

    /**
     * FR-09: who may publish, and to which dormitory.
     *
     * Reached as `$user->can('publish', [Announcement::class, $building])`. The
     * building is an argument rather than something read off the announcement,
     * because at the moment of this question there is no announcement yet.
     *
     * **A null building is the administrator's alone, and that is the one line
     * of this class worth reading twice.** NULL means «every dormitory»
     * (§3.4.2), so a warden permitted to leave the field empty would be
     * addressing block B from block A — the horizontal boundary of FR-07
     * crossed not by a leak but by an omission. The administrator's grant names
     * no building, which is exactly what makes «all of them» his to write.
     */
    public function publish(User $user, ?Building $building): bool
    {
        if ($building === null) {
            return $user->isAdministrator();
        }

        return $this->buildings->publishAnnouncements($user, $building);
    }

    /**
     * FR-11: the announcement is visible to its audience and to nobody else.
     *
     * Decided against the addressee column and the residency register rather
     * than against a capability, because being in the audience is not a role —
     * it is living in the dormitory the notice is addressed to, and FR-05
     * closes that on the stated departure date.
     */
    public function view(User $user, Announcement $announcement): bool
    {
        return $this->audience->reaches($user, $announcement);
    }

    /**
     * FR-12: a person acknowledges a notice addressed to them.
     *
     * The same circle as the feed, deliberately. An acknowledgement of
     * something one could not see would be a row asserting a reading that
     * never happened, and FR-12's whole value is that the row can be relied
     * on.
     */
    public function acknowledge(User $user, Announcement $announcement): bool
    {
        return $this->audience->reaches($user, $announcement);
    }

    /**
     * FR-12, second criterion, and §4.6.1's «natural place for a horizontal
     * access leak».
     *
     * The route returns a named list of residents who have not complied with
     * an instruction, which is personal data assembled for a purpose, so it is
     * guarded twice over.
     *
     * Here: the caller must hold the publishing capability **in the dormitory
     * the announcement names**. A warden of block A asking for the readers of
     * a block B announcement is refused outright, and the refusal is written
     * to the audit log as `access.denied` by the handler in bootstrap/app.php.
     *
     * And in `AnnouncementQuery::readers()`: for an announcement addressed to
     * every dormitory, where a building-scoped warden legitimately may look,
     * the names returned are narrowed to his own building. The two guards
     * answer different questions — «may you ask» and «about whom» — and an
     * announcement addressed to all buildings is exactly the case where the
     * first alone would not be enough.
     */
    public function viewReaders(User $user, Announcement $announcement): bool
    {
        if ($announcement->addressesEveryBuilding()) {
            /*
             * Nobody is refused here on the strength of the null alone: a
             * warden who publishes in his own dormitory has a legitimate
             * interest in who of his residents has acknowledged a notice sent
             * to all of them. What he may not see is anybody else's residents,
             * and that is the second guard's business.
             */
            return $user->isAdministrator()
                || $this->holdsThePublishingCapabilitySomewhere($user);
        }

        $building = $this->buildingOf($announcement);

        return $building !== null && $this->buildings->publishAnnouncements($user, $building);
    }

    private function holdsThePublishingCapabilitySomewhere(User $user): bool
    {
        foreach ($user->scopedBuildingIds() as $buildingId) {
            if ($user->hasPermissionInBuilding(Permission::PublishAnnouncements, $buildingId)) {
                return true;
            }
        }

        return false;
    }

    private function buildingOf(Announcement $announcement): ?Building
    {
        return $announcement->relationLoaded('building')
            ? $announcement->building
            : $announcement->building()->first();
    }
}

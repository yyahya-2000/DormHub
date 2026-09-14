<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Announcement;
use App\Models\Building;
use App\Models\User;
use App\Services\AnnouncementQuery;

/**
 * FR-09 and FR-11 over one announcement.
 *
 * The policy names no role (§3.3.3). It asks `BuildingPolicy` for a
 * capability, and `RoleCode::permissions()` says which roles carry it — so
 * «the warden and the manager of their own dormitory publish, the
 * administrator publishes everywhere» is stated once, in the capability map,
 * and not repeated in methods that could drift apart.
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
     *
     * A named building is his to write too, and by the ordinary route: his
     * grant carries `PublishAnnouncements` and names no building, so
     * `hasPermissionInBuilding` answers for every dormitory. The
     * administrator therefore chooses a block or chooses all of them, which is
     * the whole of the addressee — one field, `building_id`, an identifier or
     * null.
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
}

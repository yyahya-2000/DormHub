<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Building;
use App\Models\User;

/**
 * FR-09 over one announcement: who may publish, and to which dormitory.
 *
 * The policy names no role (§3.3.3). It asks `BuildingPolicy` for a
 * capability, and `RoleCode::permissions()` says which roles carry it — so
 * «the warden and the manager of their own dormitory publish, the
 * administrator publishes everywhere» is stated once, in the capability map,
 * and not repeated in methods that could drift apart.
 *
 * **There is one method, and there used to be two** (acceptance of
 * 15.09.2026). `view()` decided whether one announcement was inside a reader's
 * audience, and nothing ever asked it: FR-11 is a feed and not a card, there
 * is no `GET /announcements/{id}` in the contract or in `routes/api.php`, and
 * the feed keeps the boundary by computing the audience from the token's own
 * grants rather than by checking a row somebody named. An authorisation rule
 * that is never reached is worse than no rule: it reads like a guarantee, it
 * is covered by nothing, and the route that finally arrives inherits whatever
 * it happens to say. Should a card ever be wanted, the question it asks is one
 * line of `AnnouncementQuery`, written then and tested then.
 */
final class AnnouncementPolicy
{
    public function __construct(private readonly BuildingPolicy $buildings) {}

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
}

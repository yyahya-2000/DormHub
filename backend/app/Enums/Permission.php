<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a role lets its holder do, named separately from the role itself.
 *
 * §3.3.3 warns against a policy that asks «is this user a warden». The warning
 * is usually read as being only about scope — the warden of which building —
 * but it has a second half. A policy that names roles has to be edited every
 * time the set of roles changes, and the edit is easy to get wrong in exactly
 * one place: the addition of the building manager touched five policy methods
 * across four classes, and a sixth method forgotten would have been invisible
 * until somebody tried it.
 *
 * So the policies ask for a capability, `RoleCode::permissions()` says which
 * roles carry it, and the two questions a policy really has — «may this
 * account do this» and «in which dormitory» — meet in
 * `User::hasPermissionInBuilding()`.
 *
 * The names are of the work, not of the screen: FR-02's register of rooms is
 * one capability to read and another to keep, because the duty officer needs
 * the first to know which room a guest is bound for and has no business with
 * the second.
 */
enum Permission: string
{
    /** The card of the dormitory itself (FR-07). */
    case ViewBuilding = 'building.view';

    /** The register of rooms and places, read (FR-02). */
    case ViewRooms = 'rooms.view';

    /** The register of rooms and places, kept (FR-02). */
    case ManageRooms = 'rooms.manage';

    /** Moving people in and out (FR-03, FR-05). */
    case ManageResidencies = 'residencies.manage';

    /** The roll of people attached to the dormitory (FR-07). */
    case ViewPeople = 'people.view';

    /**
     * The resident card, which carries personal data (FR-06).
     *
     * The narrowest capability in the map, and deliberately so: citizenship,
     * telephone and study status belong to the register work, and the register
     * work belongs to the warden and the manager. Reading a card is audited
     * (§3.9.6), which only means the widening of this line would be visible
     * afterwards — not that it would be harmless.
     */
    case ViewResidentCard = 'resident_card.view';

    /** Creating an account for an incoming resident (FR-42). */
    case IssueResidentAccount = 'resident_account.issue';
}

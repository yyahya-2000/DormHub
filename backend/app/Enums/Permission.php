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

    /** The queue of guest requests of the dormitory, read (FR-16, FR-17). */
    case ViewGuestRequests = 'guest_requests.view';

    /**
     * Approving and refusing a guest request (FR-17).
     *
     * **The narrowest capability of the guest module, and the one line the
     * role model is asked about most often.** The agreement of 13.09.2026 is
     * that the duty officer decides and the warden does not, so this
     * capability is on exactly one role — not on the warden, not on the
     * manager, and not on the administrator either. The administrator holds
     * the register of dormitories and appoints the staff of every building;
     * the decision on a visitor is shift work at a particular post on a
     * particular evening, and an administrator who could take it would be a
     * duty officer of every building without ever being appointed one.
     *
     * The consequence is deliberate and worth stating plainly: on a dormitory
     * with no duty officer appointed, nobody can approve a guest request. That
     * is the correct failure. The remedy is an appointment, which is FR-41 and
     * is itself recorded.
     */
    case DecideGuestRequests = 'guest_requests.decide';

    /**
     * Working the security post: looking a guest up, recording the entry and
     * the exit (FR-18, FR-19).
     */
    case OperateCheckpoint = 'checkpoint.operate';

    /**
     * The visitor register of the dormitory, read and exported (FR-21).
     *
     * §3.9.6 names the circle — «the administrator and the warden of the
     * building concerned» — and the manager is outside it. The register is the
     * electronic replacement of the journal clause 2.1.2 makes the security
     * service keep, and widening its readership is a decision for the
     * operator's responsible officer rather than a convenience.
     */
    case ViewVisitRegister = 'visit_register.view';

    /**
     * Reading a guest's document number in full (NFR-06, §3.4.2).
     *
     * The column is stored encrypted and shown masked everywhere, so this is
     * not «may you see the register» but «may you unmask one number in it».
     * The security post does not hold it: at the desk the document itself is
     * in the officer's hand and the last four characters are what a comparison
     * needs. Reading in full is an event of the audit log in its own right.
     */
    case ViewGuestDocument = 'guest_document.view';
}

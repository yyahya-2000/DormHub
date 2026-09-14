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
     * Publishing an announcement, and reading who has acknowledged it
     * (FR-09, FR-12).
     *
     * **One capability and not two, which is a decision rather than an
     * oversight.** The obvious split — publish here, read the list of readers
     * there — would produce two capabilities held by exactly the same three
     * roles, because nothing in the requirements distinguishes them. The
     * register of rooms is split into `ViewRooms` and `ManageRooms` for a
     * reason this pair does not have: the duty officer needs the read and has
     * no business with the write, so the two lists of holders genuinely
     * differ. Here they would not, and a capability that never separates
     * anybody is a line of code pretending to be a rule.
     *
     * The list of readers is the evidential half of the same act. FR-12 exists
     * because SN-11 asks the warden to be able to show that a mandatory notice
     * was delivered; the person who has to show it is the person who posted
     * it.
     *
     * **The scope is the whole of the safety here.** A holder of this
     * capability publishes into the dormitory their grant names and reads the
     * readers of that dormitory. An announcement addressed to every building —
     * `building_id` NULL — is the administrator's alone, whose grant names
     * none, and the readers of such an announcement are narrowed to the
     * caller's own building before a single name is returned. See
     * `App\Policies\AnnouncementPolicy`.
     */
    case PublishAnnouncements = 'announcements.publish';

    /**
     * The maintenance queue of the dormitory, read (FR-40).
     *
     * Split from the triage below for the reason `ViewRooms` is split from
     * `ManageRooms`: the two lists of holders genuinely differ. The
     * administrator reads every dormitory's queue — FR-40 names the campus
     * directorate among its stakeholders and a directorate that cannot see the
     * backlog cannot act on it — and triages none of them, because a planned
     * completion date is a promise made by the person who will keep it.
     */
    case ViewMaintenanceRequests = 'maintenance_requests.view';

    /**
     * Accepting, refusing and working a maintenance request (FR-37, FR-38).
     *
     * **The warden and the manager of the building, and nobody else.** The
     * duty officer is outside it although they decide on guest requests: the
     * two decisions have nothing in common but the word. A guest request is
     * shift work at a post on a particular evening; a maintenance request
     * commits the dormitory's own labour and a date, which is the register
     * work §1.1.4's revision 2 puts with the manager beneath the warden.
     *
     * The resident is outside it too, and that separation is the point of the
     * module rather than a detail of it (§3.5.2): the person who does the work
     * moves the request as far as «completed», and the person who reported the
     * defect is the only one who can take it further. A status set by whoever
     * did the work proves nothing.
     */
    case TriageMaintenanceRequests = 'maintenance_requests.triage';

    /**
     * Taking a found object into the administration's safekeeping, and
     * deciding on the claims made against one (FR-24, FR-26).
     *
     * **The narrowest thing this capability says is «you are holding it».**
     * §2.5.4 makes the module peer-to-peer: the finder keeps the object,
     * publishes it and decides on claims, and no capability is involved at all
     * on that path. This one covers the other path — the object handed in at
     * the security post or deposited with the administration, which is the
     * case Civil Code art. 227 cl. 1 para. 2 addresses. Whoever holds the
     * object decides who gets it back, so the capability that records the
     * deposit is the same one that answers a claim against a deposited entry.
     *
     * The security officer holds it because a found object is handed in at the
     * desk, which is the only place in the dormitory open at four in the
     * morning. The warden and the manager hold it because the safekeeping is
     * the administration's.
     */
    case HoldLostFoundItems = 'lost_found.hold';

    /**
     * Deciding a claim the finder and the claimant could not settle (FR-26).
     *
     * **Held by the warden and the manager, and by nobody else — the
     * administrator included.** §2.5.4 names the warden, and the manager is
     * inside «the warden of this building» everywhere the contract says it
     * about operative work. The administrator is outside it for the reason
     * they are outside `DecideGuestRequests`: the dispute is about two people
     * standing in one dormitory with one umbrella between them, and a person
     * who holds the register of every dormitory is not the person who can see
     * which of them is telling the truth.
     *
     * It is split from the capability above because the two lists of holders
     * genuinely differ — the security officer takes an object in and settles
     * nobody's dispute — which is the test `ViewRooms` and `ManageRooms` are
     * split on.
     */
    case DecideLostFoundDisputes = 'lost_found.decide_disputes';
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The five roles of §1.1.4, in the codes the ER model of §3.4.3 fixes.
 *
 * The role itself is a table (§4.4.2) because ROLE_USER references it and
 * carries the building that scopes the grant; this enumeration is the
 * type-safe name of a row, not a replacement for it.
 *
 * **The building manager (revision 2 of the role model, 13.09.2026).** The
 * warden stays what the rules of internal order call him — the head of the
 * dormitory — and the day-to-day register work moves to a manager beneath him:
 * rooms and places, moving in and out, resident cards and accounts,
 * announcements, the maintenance queue. Two things follow, and both are stated
 * in code rather than in prose.
 *
 * The warden keeps every capability the manager has (`permissions()` below
 * returns the same set for both). A small dormitory may have no manager at
 * all, and the system has to keep working when it does not: the manager
 * relieves the warden, he does not replace him.
 *
 * Appointing staff is the warden's alone (`grantableRoles()`). That is the one
 * line between the two roles, and it is drawn once, here, instead of being
 * repeated as an exception in every policy.
 *
 * No regulation knows the intermediate level: the rules of internal order name
 * the warden and the security service and nothing between them. The manager is
 * an organisational answer to a building of several hundred places, not a
 * requirement read out of a norm.
 *
 * **The duty officer is gone (revision 4 of the role model, 21.09.2026).**
 * Revision 3 of the guest module put the decision on a request with the warden
 * and the manager beside the duty officer, and what the duty officer had left
 * afterwards was five capabilities of which the manager already held every
 * one. A role whose rights are a subset of another role's, granted in the same
 * scope and appointed by the same person, is not a role — it is a second name
 * for one, and the second name costs a row in `roles`, an arm in every match
 * below, a line in the client's mirror and a string in two locale files. The
 * duty officer is merged into the manager: existing grants are rewritten to
 * name the manager, and the shift at the desk is worked by the person who
 * keeps the register, which is what the dormitories had been doing before
 * revision 3 admitted it.
 *
 * The merge widens one line rather than narrowing it, and the widening is
 * accepted rather than overlooked. The duty officer was deliberately kept away
 * from the resident card (FR-06); the manager reads it. Whoever held a duty
 * officer's grant therefore reads the citizenship and the telephone of the
 * residents of that building from now on. Every such reading is audited
 * (§3.9.6), which makes the widening visible afterwards and not harmless: an
 * operator unwilling to grant it has to withhold the appointment, because the
 * capability can no longer be withheld on its own.
 */
enum RoleCode: string
{
    case Administrator = 'admin';
    case Warden = 'warden';
    case Manager = 'manager';
    case SecurityOfficer = 'security';
    case Resident = 'student';

    /**
     * The name shown in the interface, as §1.1.4 states it.
     */
    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Administrator',
            self::Warden => 'Dormitory head',
            self::Manager => 'Manager',
            self::SecurityOfficer => 'Security',
            self::Resident => 'Resident',
        };
    }

    /**
     * Whether the role is held over the system as a whole rather than inside
     * one dormitory. Only the administrator is: every other grant names a
     * building, which is what keeps FR-07's horizontal boundary in place. The
     * manager falls on the building side of that line, and the CHECK
     * constraint of `role_user` refuses a manager's grant that names none.
     */
    public function isSystemWide(): bool
    {
        return $this === self::Administrator;
    }

    /**
     * What a holder of this role may do inside the dormitory the grant names.
     *
     * This map is the single place a role's rights are written down. A policy
     * asks for a capability and never for a role, so a sixth role is added
     * by extending this method and nothing else — which is what the addition
     * of the manager itself would otherwise have cost in every policy class.
     *
     * The resident is deliberately empty. Their access to the building card is
     * not a plain grant: FR-05 closes it on the day the register says they
     * moved out, so it is decided against the residency register in
     * `BuildingPolicy::view` and cannot be expressed as a static capability.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        /*
         * The three arms below held one list between them until the guest
         * module arrived. It is split now because the guest module is the
         * first place where the administrator, the warden and the manager
         * differ from one another, and the difference is not decorative:
         * §3.9.6 gives the visitor register to «the administrator and the
         * warden of the building concerned» and not to the manager, and the
         * administrator reads the guest queue of every dormitory without
         * deciding in any of them. Sharing one arm and adding a condition
         * afterwards would have hidden both rules inside a method that is
         * supposed to state them.
         *
         * **Revision 3 of the guest module (16.09.2026), and what it cost the
         * role model.** The decision on a request had been a sixth role's
         * alone — the duty officer's; revision 3 gave it to the warden and the
         * manager as well, inside the building their grant names. The
         * agreement of 13.09.2026 had divided the queue from the register work
         * on paper, and the dormitories do not: the person who keeps the
         * register is the person a resident finds at the desk, and a request
         * that waits for an officer who is on the door is a request the fourth
         * criterion of FR-17 closes as rejected. The separation was dropped in
         * favour of the building boundary, which is the one that was ever
         * enforced — a manager of block A still cannot touch a request of
         * block B, and the grant's `building_id` is what refuses him.
         *
         * That left the sixth role holding nothing the manager did not hold,
         * and revision 4 (21.09.2026) removed it. There is no arm for it
         * below, and nothing moved into the three that remain: the merge was
         * possible precisely because the manager's list already covered it.
         *
         * The register work — rooms, places, move-in, move-out, cards,
         * accounts — is still identical for all three, and the manager still
         * relieves the warden rather than replacing him.
         */
        return match ($this) {
            self::Administrator => [
                Permission::ViewBuilding,
                Permission::ViewRooms,
                Permission::ManageRooms,
                Permission::ManageResidencies,
                Permission::ViewPeople,
                Permission::ViewResidentCard,
                Permission::IssueResidentAccount,
                Permission::PublishAnnouncements,
                Permission::ViewGuestRequests,
                Permission::ViewVisitRegister,
                /*
                 * The maintenance queue read and not triaged, which is the
                 * same shape as the guest queue above and rests on the same
                 * argument. FR-40 names the campus directorate (S4) among the
                 * people the queue is for, and a directorate that cannot see
                 * the backlog of its dormitories cannot act on it. Accepting a
                 * request, however, means promising a date and committing the
                 * building's own labour, and that is the warden's promise to
                 * make.
                 */
                Permission::ViewMaintenanceRequests,
            ],
            self::Warden => [
                Permission::ViewBuilding,
                Permission::ViewRooms,
                Permission::ManageRooms,
                Permission::ManageResidencies,
                Permission::ViewPeople,
                Permission::ViewResidentCard,
                Permission::IssueResidentAccount,
                Permission::PublishAnnouncements,
                Permission::ViewGuestRequests,
                Permission::DecideGuestRequests,
                Permission::ViewVisitRegister,
                Permission::ViewMaintenanceRequests,
                Permission::TriageMaintenanceRequests,
                /*
                 * The lost-and-found module's two staff capabilities (§2.5.4).
                 * Neither is the module's ordinary path: a find stays with the
                 * resident who found it and is settled between two residents.
                 * These cover the two exceptions — an object deposited with
                 * the administration for safekeeping, and a claim the two
                 * sides could not settle.
                 */
                Permission::HoldLostFoundItems,
                Permission::DecideLostFoundDisputes,
            ],
            /*
             * The manager reads the guest queue and decides on it, because a
             * request names a room, the rooms are his work, and he is the
             * person at the desk when a resident brings the request. He still
             * does not export the register: §3.9.6 names the administrator and
             * the warden there, and that line is untouched by revision 3.
             */
            self::Manager => [
                Permission::ViewBuilding,
                Permission::ViewRooms,
                Permission::ManageRooms,
                Permission::ManageResidencies,
                Permission::ViewPeople,
                Permission::ViewResidentCard,
                Permission::IssueResidentAccount,
                Permission::PublishAnnouncements,
                Permission::ViewGuestRequests,
                Permission::DecideGuestRequests,
                /*
                 * The maintenance queue in full. §1.1.4's revision 2 lists it
                 * among the register work that moves to the manager, and this
                 * is the one module where the manager is the intended reader
                 * rather than the relieving one: the defects are in the rooms,
                 * and the rooms are his.
                 */
                Permission::ViewMaintenanceRequests,
                Permission::TriageMaintenanceRequests,
                /*
                 * The same two as the warden's, and for the reason §1.1.4's
                 * revision 2 gives: keeping an object somebody left at the
                 * desk, and settling a disagreement between two residents of
                 * the building, are both day-to-day work of the building.
                 */
                Permission::HoldLostFoundItems,
                Permission::DecideLostFoundDisputes,
            ],
            /*
             * The security officer works the post and nothing else. FR-18 and
             * FR-19 are the whole of it: find the guest, compare the document,
             * record the entry, record the exit. He does not read the queue of
             * undecided requests — there is nothing for him to do with one —
             * and he does not export the register, which is §3.9.6's line.
             */
            self::SecurityOfficer => [
                Permission::ViewBuilding,
                Permission::OperateCheckpoint,
                /*
                 * FR-24 names the security officer beside the warden as a
                 * publisher of finds: an object picked up in the corridor is
                 * handed in at the desk, which is the only place in the
                 * dormitory staffed at four in the morning. Taking it in and
                 * handing it back are one job, so the officer also answers the
                 * claims made against the entries the post holds. He settles
                 * no disputes — that is the other capability, and it is not
                 * here.
                 */
                Permission::HoldLostFoundItems,
            ],
            self::Resident => [],
        };
    }

    public function grants(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * FR-41: the roles a holder of this one may grant and revoke, inside the
     * scope their own grant names.
     *
     * The chain of appointment agreed on 13.09.2026 reads
     * «system administrator → warden of the building → manager, security», and
     * this method is that chain. Nobody may grant the
     * administrator, because the administrator's grant is the one held over
     * the system as a whole and is issued outside the application; nobody but
     * the administrator may grant a warden; and the manager grants nothing at
     * all — which is the difference between the manager and the warden, stated
     * once.
     *
     * **Each level hands out exactly the level below (MVP decision of
     * 14.09.2026).** The administrator grants the warden and nothing else; the
     * staff of a dormitory — the manager and the security officer — are the
     * warden's own appointments, made inside the building he answers for. The
     * administrator held the whole list for a while, so that a dismissed
     * warden's appointments could still be unwound from the top; the MVP drops
     * that in favour of the chain the roles were agreed as. The consequence is
     * accepted rather than overlooked: to replace a security officer of a
     * dormitory that has lost its warden, the administrator appoints a warden
     * first.
     *
     * The resident role is on nobody's list, and that is FR-42's doing rather
     * than an omission: a resident grant is written when an account is issued
     * to somebody moving in, and it belongs to the housing register.
     *
     * @return list<RoleCode>
     */
    public function grantableRoles(): array
    {
        return match ($this) {
            self::Administrator => [self::Warden],
            self::Warden => [self::Manager, self::SecurityOfficer],
            default => [],
        };
    }
}

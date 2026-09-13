<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The six roles of §1.1.4, in the codes the ER model of §3.4.3 fixes.
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
 */
enum RoleCode: string
{
    case Administrator = 'admin';
    case DutyOfficer = 'duty_officer';
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
            self::DutyOfficer => 'Duty officer',
            self::Warden => 'Warden',
            self::Manager => 'Building manager',
            self::SecurityOfficer => 'Security officer',
            self::Resident => 'Student resident',
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
     * asks for a capability and never for a role, so a seventh role is added
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
        return match ($this) {
            self::Administrator, self::Warden, self::Manager => [
                Permission::ViewBuilding,
                Permission::ViewRooms,
                Permission::ManageRooms,
                Permission::ManageResidencies,
                Permission::ViewPeople,
                Permission::ViewResidentCard,
                Permission::IssueResidentAccount,
            ],
            self::DutyOfficer => [
                Permission::ViewBuilding,
                Permission::ViewRooms,
                Permission::ViewPeople,
                Permission::ViewResidentCard,
            ],
            self::SecurityOfficer => [
                Permission::ViewBuilding,
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
     * «system administrator → warden of the building → manager, duty officer,
     * security», and this method is that chain. Nobody may grant the
     * administrator, because the administrator's grant is the one held over
     * the system as a whole and is issued outside the application; nobody but
     * the administrator may grant a warden; and the manager grants nothing at
     * all — which is the difference between the manager and the warden, stated
     * once.
     *
     * @return list<RoleCode>
     */
    public function grantableRoles(): array
    {
        return match ($this) {
            self::Administrator => [self::Warden],
            self::Warden => [self::Manager, self::DutyOfficer, self::SecurityOfficer],
            default => [],
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The five roles of §1.1.4, in the codes the ER model of §3.4.3 fixes.
 *
 * The role itself is a table (§4.4.2) because ROLE_USER references it and
 * carries the building that scopes the grant; this enumeration is the
 * type-safe name of a row, not a replacement for it.
 */
enum RoleCode: string
{
    case Administrator = 'admin';
    case DutyOfficer = 'duty_officer';
    case Warden = 'warden';
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
            self::SecurityOfficer => 'Security officer',
            self::Resident => 'Student resident',
        };
    }

    /**
     * Whether the role is held over the system as a whole rather than inside
     * one dormitory. Only the administrator is: every other grant names a
     * building, which is what keeps FR-07's horizontal boundary in place.
     */
    public function isSystemWide(): bool
    {
        return $this === self::Administrator;
    }
}

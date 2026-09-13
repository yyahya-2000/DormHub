import { RoleCode, type User } from '@/api/generated/model'

/**
 * What the interface shows — and only that.
 *
 * §3.3.2 draws the line this module lives on: the client hides a control, the
 * server takes the decision. Nothing here grants access. A student who types
 * the address of the building roll by hand reaches the page, the page asks the
 * API, and the API answers 403 — which the page then shows as a refusal rather
 * than as a broken screen. The functions below exist so that a security officer
 * is not offered a link that would only ever refuse him.
 *
 * The table below mirrors `RoleCode::permissions()` on the server, and mirrors
 * it in the same shape: a capability names the work, a role carries a set of
 * capabilities, and a screen asks for the capability. That is what makes a new
 * role cheap. The building manager of revision 2 of the role model (13.09.2026)
 * sits between the warden and the duty officer, and adding it here is one line
 * in one map — no screen names a role, so no screen has to be edited.
 *
 * A mirror can fall out of step with what it mirrors, and this one is allowed
 * to: it decides what is drawn, never what is permitted. If the two disagree
 * the reader sees either a control that the server then refuses, or no control
 * where one was due — both visible, neither a hole.
 */

/**
 * Role codes the interface knows about. The union is deliberately wider than
 * the generated `RoleCode`, and the building manager is the case that proved
 * why: it was written here while the contract still named five roles, the
 * screens compiled either way, and regenerating the client the day the sixth
 * role landed changed nothing above this line.
 */
export type KnownRole = RoleCode | 'manager'

/** The work a grant lets its holder do, named as the server names it. */
export const Permission = {
  viewBuilding: 'building.view',
  viewRooms: 'rooms.view',
  manageRooms: 'rooms.manage',
  manageResidencies: 'residencies.manage',
  viewPeople: 'people.view',
  viewResidentCard: 'resident_card.view',
} as const

export type Permission = (typeof Permission)[keyof typeof Permission]

/**
 * Keyed by the wire value rather than by `KnownRole`, so that a role the
 * contract has not yet declared resolves to no capabilities instead of to a
 * type error. An unknown role therefore draws nothing — which is the safe
 * direction to be wrong in, since the server is the one deciding.
 */
const CAPABILITIES: Record<string, readonly Permission[]> = {
  admin: [
    Permission.viewBuilding,
    Permission.viewRooms,
    Permission.manageRooms,
    Permission.manageResidencies,
    Permission.viewPeople,
    Permission.viewResidentCard,
  ],
  warden: [
    Permission.viewBuilding,
    Permission.viewRooms,
    Permission.manageRooms,
    Permission.manageResidencies,
    Permission.viewPeople,
    Permission.viewResidentCard,
  ],
  // The manager relieves the warden of the register work and of nothing else.
  // Over the housing domain the two sets are identical, which is why no screen
  // of this slice draws a difference between them; appointing staff is the one
  // capability the warden keeps to himself, and it belongs to the routes of
  // FR-41, which are not part of these screens.
  manager: [
    Permission.viewBuilding,
    Permission.viewRooms,
    Permission.manageRooms,
    Permission.manageResidencies,
    Permission.viewPeople,
    Permission.viewResidentCard,
  ],
  // The duty officer approves guest requests, and for that he needs the room a
  // guest is bound for and the roll of the building. Not the resident card: it
  // carries citizenship, telephone and study status, and FR-06's criterion
  // names the warden of that building and the administrator. The server struck
  // this capability from the duty officer's set, and the mirror follows —
  // otherwise the floor plan would ask him for a dozen cards it knows will be
  // refused, and each refusal is an `access.denied` line in the audit log.
  duty_officer: [Permission.viewBuilding, Permission.viewRooms, Permission.viewPeople],
  security: [Permission.viewBuilding],
  student: [],
}

export function rolesOf(user: User): RoleCode[] {
  return (user.roles ?? []).map((grant) => grant.role)
}

/** Distinct buildings the account holds a role in; the administrator holds none. */
export function buildingsOf(user: User): number[] {
  const ids = (user.roles ?? [])
    .map((grant) => grant.building_id)
    .filter((id): id is number => id !== null)
  return [...new Set(ids)]
}

export function isSystemAdministrator(user: User): boolean {
  return (user.roles ?? []).some(
    (grant) => grant.role === RoleCode.admin && grant.building_id === null,
  )
}

/**
 * Whether a grant of this account carries the capability **in this building**.
 * A grant naming no building is held over the system and covers every one of
 * them; that is the administrator, and in this iteration only the administrator.
 */
export function may(user: User, permission: Permission, buildingId: number): boolean {
  return (user.roles ?? []).some((grant) => {
    if (grant.building_id !== null && grant.building_id !== buildingId) {
      return false
    }
    return (CAPABILITIES[grant.role] ?? []).includes(permission)
  })
}

/** Whether any grant at all carries the capability, in whatever building. */
export function mayAnywhere(user: User, permission: Permission): boolean {
  return (user.roles ?? []).some((grant) =>
    (CAPABILITIES[grant.role] ?? []).includes(permission),
  )
}

/**
 * FR-01. The register of dormitories is not work done inside a building, so
 * there is no building to scope the question to. The administrator is the
 * answer, and the server states it as the role for the same reason.
 */
export function keepsBuildingRegister(user: User): boolean {
  return isSystemAdministrator(user)
}

/**
 * The audit log is readable by the administrator alone, so the link is offered
 * to nobody else. The route stays reachable, and answers what the server says.
 */
export function showsAuditLink(user: User): boolean {
  return isSystemAdministrator(user)
}

/**
 * The roll carries personal data, so the API narrows it to the administrator
 * and to the staff of that same building. The section is drawn only for those;
 * for everyone else the building card stands alone.
 */
export function showsBuildingRoll(user: User, buildingId: number): boolean {
  return may(user, Permission.viewPeople, buildingId)
}

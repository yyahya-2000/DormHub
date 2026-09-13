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
 */

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
 * The audit log is readable by the administrator alone, so the link is offered
 * to nobody else. The route stays reachable, and answers what the server says.
 */
export function showsAuditLink(user: User): boolean {
  return isSystemAdministrator(user)
}

/**
 * The roll carries personal data, so the API narrows it to the administrator
 * and to the warden or duty officer of that same building. The section is drawn
 * only for those; for everyone else the building card stands alone.
 */
export function showsBuildingRoll(user: User, buildingId: number): boolean {
  if (isSystemAdministrator(user)) {
    return true
  }
  return (user.roles ?? []).some(
    (grant) =>
      grant.building_id === buildingId &&
      (grant.role === RoleCode.warden || grant.role === RoleCode.duty_officer),
  )
}

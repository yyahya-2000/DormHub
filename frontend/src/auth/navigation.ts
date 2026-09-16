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
  issueResidentAccount: 'resident_account.issue',
  viewGuestRequests: 'guest_requests.view',
  decideGuestRequests: 'guest_requests.decide',
  operateCheckpoint: 'checkpoint.operate',
  viewVisitRegister: 'visit_register.view',
  viewGuestDocument: 'guest_document.view',
  publishAnnouncements: 'announcements.publish',
  viewMaintenanceRequests: 'maintenance_requests.view',
  triageMaintenanceRequests: 'maintenance_requests.triage',
  // The two staff capabilities of the lost-and-found module, and neither is
  // its ordinary path: a find stays with the resident who picked it up and is
  // settled between two residents without either of these being asked for.
  holdLostFoundItems: 'lost_found.hold',
  decideLostFoundDisputes: 'lost_found.decide_disputes',
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
    Permission.issueResidentAccount,
    Permission.viewGuestRequests,
    Permission.viewVisitRegister,
    Permission.viewGuestDocument,
    Permission.publishAnnouncements,
    // FR-40 names the campus directorate among the readers of a queue, and a
    // directorate that cannot see the backlog cannot act on it. Reading only:
    // accepting a request promises a date and commits a building's own labour,
    // and that promise is the warden's to make.
    Permission.viewMaintenanceRequests,
  ],
  warden: [
    Permission.viewBuilding,
    Permission.viewRooms,
    Permission.manageRooms,
    Permission.manageResidencies,
    Permission.viewPeople,
    Permission.viewResidentCard,
    Permission.issueResidentAccount,
    Permission.viewGuestRequests,
    Permission.decideGuestRequests,
    Permission.viewVisitRegister,
    Permission.viewGuestDocument,
    Permission.publishAnnouncements,
    Permission.viewMaintenanceRequests,
    Permission.triageMaintenanceRequests,
    // §2.5.4's two exceptions to a peer-to-peer module: an object deposited
    // with the administration for safekeeping, and a claim the two residents
    // could not settle between them.
    Permission.holdLostFoundItems,
    Permission.decideLostFoundDisputes,
  ],
  // The manager relieves the warden of the register work and of nothing else.
  // Over the housing domain the two sets are identical, and issuing an account
  // to an incoming resident (FR-42) is register work, so the manager carries it
  // too. Appointing staff is the one capability the warden keeps to himself,
  // and it is not a capability at all — see `GRANTABLE` below.
  // The guest module is where the three register roles stop being the same
  // set. The manager reads the queue and decides on it — revision 3 of the
  // guest module (16.09.2026) gave the decision to whoever keeps the register
  // of the building, because that is the person a resident finds at the desk.
  // He still does not export the register or unmask a document number: §3.9.6
  // names the administrator and the warden there.
  manager: [
    Permission.viewBuilding,
    Permission.viewRooms,
    Permission.manageRooms,
    Permission.manageResidencies,
    Permission.viewPeople,
    Permission.viewResidentCard,
    Permission.issueResidentAccount,
    Permission.viewGuestRequests,
    Permission.decideGuestRequests,
    Permission.publishAnnouncements,
    // The one module where the manager is the intended reader rather than the
    // relieving one: the defects are in the rooms, and the rooms are his work.
    Permission.viewMaintenanceRequests,
    Permission.triageMaintenanceRequests,
    // The same two as the warden's: keeping an object somebody left at the
    // desk, and settling a disagreement between two residents of the
    // building, are both day-to-day work of the building.
    Permission.holdLostFoundItems,
    Permission.decideLostFoundDisputes,
  ],
  // The duty officer approves guest requests, and for that he needs the room a
  // guest is bound for and the roll of the building. Not the resident card: it
  // carries citizenship and telephone, and FR-06's criterion
  // names the warden of that building and the administrator. The server struck
  // this capability from the duty officer's set, and the mirror follows —
  // otherwise the floor plan would ask him for a dozen cards it knows will be
  // refused, and each refusal is an `access.denied` line in the audit log.
  duty_officer: [
    Permission.viewBuilding,
    Permission.viewRooms,
    Permission.viewPeople,
    Permission.viewGuestRequests,
    Permission.decideGuestRequests,
  ],
  // The security officer works the post and nothing else: find the guest,
  // compare the document, record the entry, record the exit. Not the queue of
  // undecided requests — there is nothing at the desk to do with one — and not
  // the register export, which §3.9.6 gives to the warden and the administrator.
  // FR-24 names the officer beside the warden as a publisher of finds: an
  // object picked up in a corridor at four in the morning is handed in at the
  // desk, the only place in the dormitory staffed at that hour. Taking it in
  // and handing it back are one job, so the post also answers the claims made
  // against the entries it holds. It settles no disputes — that is the other
  // capability, and it is not here.
  security: [
    Permission.viewBuilding,
    Permission.operateCheckpoint,
    Permission.holdLostFoundItems,
  ],
  student: [],
}

/**
 * FR-41, the chain of appointment, mirrored from `RoleCode::grantableRoles()`.
 *
 * It is kept apart from `CAPABILITIES` because it is not a capability: what a
 * grant lets its holder appoint is a set of roles, not a yes or a no. «May this
 * warden appoint here» and «may he appoint *this*» have different answers for
 * the same account — a manager yes, another warden no — and the server settles
 * both in one call. Flattening the pair into a single `staff.appoint` flag here
 * would draw a role in the form that the API would then refuse.
 *
 * The manager is absent, and that absence is the requirement: revision 2 of the
 * role model withheld appointment from him, so the screen is never drawn for
 * him at all.
 */
const GRANTABLE: Record<string, readonly KnownRole[]> = {
  admin: [RoleCode.warden],
  warden: [RoleCode.manager, RoleCode.duty_officer, RoleCode.security],
}

/**
 * The roles this account may grant and revoke inside this building. Empty for
 * everyone the chain of appointment does not name, which is what hides the
 * screen rather than merely emptying it.
 */
export function grantableRolesIn(user: User, buildingId: number): KnownRole[] {
  const roles = new Set<KnownRole>()
  for (const grant of user.roles ?? []) {
    if (grant.building_id !== null && grant.building_id !== buildingId) {
      continue
    }
    for (const role of GRANTABLE[grant.role] ?? []) {
      roles.add(role)
    }
  }
  return [...roles]
}

/** FR-41. Whether there is any appointment at all for this account to make here. */
export function appointsStaff(user: User, buildingId: number): boolean {
  return grantableRolesIn(user, buildingId).length > 0
}

/** FR-42. The warden and the manager of this building, and the administrator. */
export function issuesResidentAccounts(user: User, buildingId: number): boolean {
  return may(user, Permission.issueResidentAccount, buildingId)
}

export function rolesOf(user: User): RoleCode[] {
  return (user.roles ?? []).map((grant) => grant.role)
}

/**
 * The chain of appointment of §1.1.4, read top to bottom. It is the order the
 * roles are named in when an account holds several, and nothing decides
 * anything by it — the capability map above does that, and this list would be
 * a second, disagreeing answer if it were ever asked a question of access.
 */
const ROLE_PRECEDENCE: readonly KnownRole[] = [
  RoleCode.admin,
  RoleCode.warden,
  RoleCode.manager,
  RoleCode.duty_officer,
  RoleCode.security,
  RoleCode.student,
]

/**
 * The roles of the account, each named once and in the order above.
 *
 * A grant names a building, so one person may hold the same role twice — a
 * duty officer of two dormitories is two grants and one role — and may hold
 * two different roles in two different buildings. The first is a repetition
 * and is dropped; the second is not, and both roles are kept. Naming only the
 * weightiest would be the shorter answer and the wrong one: it would tell a
 * warden of one dormitory who is a duty officer of another that he is only the
 * first of the two.
 */
export function distinctRolesOf(user: User): KnownRole[] {
  const held = new Set<string>(rolesOf(user))
  return ROLE_PRECEDENCE.filter((role) => held.has(role))
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

/**
 * The buildings a grant of this capability names. Empty when the account has
 * none, which is what hides a whole section rather than emptying it. The
 * administrator's grant names no building and so appears here as nothing: the
 * screens that need one take it from the path instead.
 */
export function buildingsWith(user: User, permission: Permission): number[] {
  const ids = (user.roles ?? [])
    .filter((grant) => (CAPABILITIES[grant.role] ?? []).includes(permission))
    .map((grant) => grant.building_id)
    .filter((id): id is number => id !== null)
  return [...new Set(ids)]
}

/**
 * FR-16. The buildings this account may file a guest request for.
 *
 * The server decides this one against the residency register and not against a
 * capability: «the right to invite a guest follows from living there». The
 * mirror cannot read that register, so it asks the nearest question it can —
 * does the account hold the resident role here — and is deliberately wrong in
 * the visible direction. A resident whose departure date has passed still sees
 * the screen, files the form, and is refused by the API with the reason on it
 * (FR-05); a member of staff with no residency sees no tab at all.
 */
export function guestRequestBuildingsOf(user: User): number[] {
  const ids = (user.roles ?? [])
    .filter((grant) => grant.role === RoleCode.student)
    .map((grant) => grant.building_id)
    .filter((id): id is number => id !== null)
  return [...new Set(ids)]
}

/**
 * FR-17. The duty officer, the warden or the manager of **this** dormitory.
 *
 * The building is the argument because it is the whole of the boundary: the
 * same three roles one block over answer false here.
 */
export function decidesGuestRequests(user: User, buildingId: number): boolean {
  return may(user, Permission.decideGuestRequests, buildingId)
}

/** FR-17, FR-21. Who may read the queue of a dormitory: four roles, not one. */
export function readsGuestRequests(user: User, buildingId: number): boolean {
  return may(user, Permission.viewGuestRequests, buildingId)
}

/** FR-18, FR-19. The post of this dormitory. */
export function operatesCheckpoint(user: User, buildingId: number): boolean {
  return may(user, Permission.operateCheckpoint, buildingId)
}

/** FR-21 and §3.9.6: the administrator and the warden of the building concerned. */
export function readsVisitRegister(user: User, buildingId: number): boolean {
  return may(user, Permission.viewVisitRegister, buildingId)
}

/**
 * FR-09. Whether this account may publish into the dormitory named.
 *
 * The three register roles carry it and the two post-facing ones do not, which
 * is the server's own map. The question is asked per building because a warden
 * publishes where his grant names and nowhere else.
 */
export function publishesAnnouncements(user: User, buildingId: number): boolean {
  return may(user, Permission.publishAnnouncements, buildingId)
}

/** The dormitories this account may publish into. Empty hides the screen. */
export function announcementBuildingsOf(user: User): number[] {
  return buildingsWith(user, Permission.publishAnnouncements)
}

/**
 * FR-09 and §3.4.2. Whether «every dormitory» may be chosen as the addressee.
 *
 * A null building is an audience and not a missing field, and it belongs to the
 * administrator alone — a warden permitted to leave the field empty would be
 * addressing buildings his grant does not name. The option is therefore absent
 * from the form rather than present and refused; the server refuses it anyway,
 * with 403, for a client that sends it regardless.
 */
export function addressesEveryBuilding(user: User): boolean {
  return isSystemAdministrator(user)
}

/**
 * FR-40. Who reads the queue of a dormitory: the warden, the manager and the
 * administrator, the last of whom reads every one of them.
 */
export function readsMaintenanceQueue(user: User, buildingId: number): boolean {
  return may(user, Permission.viewMaintenanceRequests, buildingId)
}

/**
 * FR-37 and FR-38. Who moves a request: the warden and the manager of this
 * dormitory. Not the duty officer, who decides on guest requests and has
 * nothing to do with these; not the administrator, who reads every queue and
 * promises no dates; and never the resident who filed it, whose two buttons are
 * `confirm` and `reopen` and are a matter of identity rather than of capability.
 */
export function triagesMaintenance(user: User, buildingId: number): boolean {
  return may(user, Permission.triageMaintenanceRequests, buildingId)
}

/**
 * FR-36. The dormitories this account may file a maintenance request for.
 *
 * Decided the same way as the guest form's, and deliberately wrong in the same
 * visible direction: the server asks the residency register — «a resident
 * without an active residency record cannot file», answered with 403 — and the
 * mirror cannot read that register, so it asks whether the account holds the
 * resident role here. A resident whose departure date has passed still sees the
 * screen and is refused with the reason on it; a member of staff who lives
 * nowhere sees no tab.
 */
export function maintenanceBuildingsOf(user: User): number[] {
  const ids = (user.roles ?? [])
    .filter((grant) => grant.role === RoleCode.student)
    .map((grant) => grant.building_id)
    .filter((id): id is number => id !== null)
  return [...new Set(ids)]
}

/**
 * FR-24, FR-26. Keeping an object deposited with the administration of this
 * dormitory, and answering the claims made against an entry it holds.
 *
 * The warden, the manager and the security post. It is the narrower of the
 * module's two staff capabilities and the only one the post holds.
 */
export function holdsLostFoundItems(user: User, buildingId: number): boolean {
  return may(user, Permission.holdLostFoundItems, buildingId)
}

/**
 * FR-26. Deciding a claim the finder and the claimant could not settle.
 *
 * The warden and the manager, and neither the officer — who keeps objects and
 * settles nothing — nor the administrator, who is outside a disagreement
 * between two residents about one umbrella for the same reason they are
 * outside a decision on a guest request.
 */
export function decidesLostFoundDisputes(user: User, buildingId: number): boolean {
  return may(user, Permission.decideLostFoundDisputes, buildingId)
}

/**
 * FR-24. The dormitories this account may publish a find or a loss into.
 *
 * Two different answers folded into one list, because the form is the same
 * either way. A resident publishes where they live, and the mirror asks the
 * nearest question it can — does the account hold the resident role here —
 * being deliberately wrong in the visible direction, exactly as the guest and
 * maintenance forms are: the server asks the residency register and answers
 * 403 with the reason on it. A member of staff publishes where the safekeeping
 * capability names, which is a capability and is read as one.
 *
 * The administrator appears here as nothing, and that is the server's answer
 * too: they hold neither capability in any building and live in none, so they
 * read every feed and publish into none of them.
 */
export function lostFoundBuildingsOf(user: User): number[] {
  const ids = new Set<number>()
  for (const grant of user.roles ?? []) {
    if (grant.building_id === null) {
      continue
    }
    const holds = (CAPABILITIES[grant.role] ?? []).includes(Permission.holdLostFoundItems)
    if (grant.role === RoleCode.student || holds) {
      ids.add(grant.building_id)
    }
  }
  return [...ids]
}

/**
 * §2.5.4's deposited path, as a question about the form rather than about the
 * feed: may this account state that the administration is holding the object.
 *
 * A resident may publish and may not say that — the entry would be an
 * assertion about the university — so the control is absent from their form
 * rather than present and refused. The server refuses it anyway, with 403.
 */
export function depositsLostFoundItems(user: User, buildingId: number): boolean {
  return holdsLostFoundItems(user, buildingId)
}

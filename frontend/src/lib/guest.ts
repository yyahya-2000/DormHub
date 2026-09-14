import {
  GuestDocumentType,
  GuestRequestStatus,
  GuestVisitStatus,
} from '@/api/generated/model'

/**
 * The small facts about a guest request that several screens need to agree on:
 * which tone a state is drawn in, whether a deadline has passed, and how the
 * visiting window of a dormitory turns into the bounds of a time field.
 *
 * Nothing here decides anything. The verdict on admission is the server's and
 * arrives on the card (§3.3.2); the window is the building's row and arrives
 * with the building. What this module holds is the arithmetic a screen would
 * otherwise repeat four times.
 */

/**
 * The tone of a request state. Three groups and not eight colours: the visit is
 * ahead (prussian), the visit is happening or has passed its deadline (brass,
 * brick), or the request is closed one way or another (grey).
 */
export const GUEST_STATUS_TONE: Record<GuestRequestStatus, string> = {
  pending_review: 'border-brass/45 bg-brass-wash text-brass',
  approved: 'border-prussian/30 bg-prussian-wash text-prussian',
  in_progress: 'border-prussian bg-prussian text-white',
  overdue: 'border-brick/50 bg-brick-wash text-brick',
  rejected: 'border-brick/40 bg-brick-wash text-brick',
  cancelled: 'border-rule bg-paper text-steel',
  expired: 'border-rule bg-paper text-steel',
  completed: 'border-rule bg-paper text-steel',
}

export const VISIT_STATUS_TONE: Record<GuestVisitStatus, string> = {
  in_building: 'border-prussian bg-prussian text-white',
  overdue: 'border-brick/50 bg-brick-wash text-brick',
  closed: 'border-rule bg-paper text-steel',
  closed_late: 'border-brick/40 bg-brick-wash text-brick',
}

/** The states a decision is still open on — the duty officer's queue (FR-17). */
export function awaitsDecision(status: GuestRequestStatus): boolean {
  return status === GuestRequestStatus.pending_review
}

/** The states the author may still withdraw from (FR-16, fourth criterion). */
export function withdrawable(status: GuestRequestStatus): boolean {
  return (
    status === GuestRequestStatus.pending_review ||
    status === GuestRequestStatus.approved
  )
}

/**
 * FR-23, first criterion, mirrored so the warning is on the screen before the
 * form is sent rather than only in the answer. The flag itself is derived by
 * the server from the document type and is never accepted from the client — a
 * client that could set it could clear it.
 */
export function isForeignDocument(type: GuestDocumentType | ''): boolean {
  return (
    type === GuestDocumentType.foreign_passport ||
    type === GuestDocumentType.residence_permit
  )
}

/**
 * Whether the departure deadline has passed. The comparison is against the
 * clock of the machine the screen runs on, so it is a prompt to look and never
 * a verdict: the status `overdue` is written by the quarter-hourly sweep of
 * FR-20, on the server, against the dormitory's own control time.
 */
export function pastDeadline(dueAt: string | null | undefined, now: Date = new Date()): boolean {
  if (dueAt === null || dueAt === undefined || dueAt === '') {
    return false
  }
  const deadline = new Date(dueAt)
  return !Number.isNaN(deadline.getTime()) && deadline.getTime() < now.getTime()
}

/**
 * The bare `HH:MM` a time field takes, from the `HH:MM:SS` the API sends for a
 * visiting window. Used as the `min` and `max` of the interval fields, so the
 * browser refuses an hour the dormitory does not admit before the request
 * travels — NFR-09 in the interface: 08:00–23:00 is nowhere in this source.
 */
export function clockBound(value: string | null | undefined): string | undefined {
  if (typeof value !== 'string' || value.length < 5) {
    return undefined
  }
  return value.slice(0, 5)
}

/** Whether `HH:MM` lies inside the window the building row names, both ends included. */
export function insideWindow(
  value: string,
  from: string | undefined,
  to: string | undefined,
): boolean {
  if (value === '' || from === undefined || to === undefined) {
    return true
  }
  return value >= from && value <= to
}

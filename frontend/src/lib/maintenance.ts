import {
  MaintenanceCategory,
  MaintenanceLocation,
  MaintenanceRequestStatus,
  MaintenanceUrgency,
  type MaintenanceRequest,
} from '@/api/generated/model'

/**
 * The vocabulary of the maintenance module, in one file so that the resident's
 * screen, the card and the building queue draw the same words in the same
 * colours.
 *
 * **Nothing here is a decision.** FR-38's graph lives on the server, as a state
 * machine with a unit test against the transition table of §3.5.5; the
 * functions below say which buttons to draw and are allowed to be wrong in the
 * visible direction — a button the server then refuses with 409, shown as a
 * refusal in the place the button was. The one thing they must not do is invent
 * a clock: `overdue` and `confirmation_window_open` are computed on the server
 * from configuration, arrive on the row, and are read rather than recalculated.
 */

export const MAINTENANCE_CATEGORIES: MaintenanceCategory[] =
  Object.values(MaintenanceCategory)

export const MAINTENANCE_STATUSES: MaintenanceRequestStatus[] = Object.values(
  MaintenanceRequestStatus,
)

export const MAINTENANCE_URGENCIES: MaintenanceUrgency[] = Object.values(
  MaintenanceUrgency,
)

export const MAINTENANCE_LOCATIONS: MaintenanceLocation[] =
  Object.values(MaintenanceLocation)

/**
 * The tone of a state. Four groups and not six colours: the request is waiting
 * for the dormitory to answer (brass), it is being worked (prussian), it is
 * waiting for the resident's word (prussian, filled), or it is over — closed or
 * refused.
 */
export const STATUS_TONE: Record<MaintenanceRequestStatus, string> = {
  submitted: 'border-brass/45 bg-brass-wash text-brass',
  accepted: 'border-prussian/30 bg-prussian-wash text-prussian',
  in_progress: 'border-prussian/30 bg-prussian-wash text-prussian',
  completed: 'border-prussian bg-prussian text-white',
  closed: 'border-rule bg-paper text-steel',
  rejected: 'border-brick/40 bg-brick-wash text-brick',
}

/**
 * The tone of an urgency. The emergency is the only one drawn in the alarm
 * colour: a scale where two of three values shout is a scale that says nothing.
 */
export const URGENCY_TONE: Record<MaintenanceUrgency, string> = {
  routine: 'border-rule bg-paper text-steel',
  urgent: 'border-brass/45 bg-brass-wash text-brass',
  emergency: 'border-brick/50 bg-brick-wash text-brick',
}

/**
 * Reading a queue row's category and urgency back into codes.
 *
 * The queue of FR-40 is «rows of scalars», shaped for the CSV that the same
 * route writes, so `category` and `urgency` arrive there as the server's own
 * words — «Plumbing», «Emergency» — where every other route sends the code and
 * the label beside it. Printing those words on a Russian screen is the one
 * place in this module where NFR-11 would be broken by the shape of the
 * contract rather than by the interface, so the word is read back into the code
 * the locale files are keyed by.
 *
 * The map is of the server's **English** labels, and that is safe for the
 * reason it looks unsafe: the API answers in English and in no other language,
 * which is why these labels are never the first choice anywhere else in the
 * client. A label this map does not know falls through to the server's word,
 * which is what the screen would have shown in any case.
 */
const CATEGORY_BY_LABEL = new Map<string, MaintenanceCategory>([
  ['plumbing', MaintenanceCategory.plumbing],
  ['electrical', MaintenanceCategory.electrical],
  ['furniture', MaintenanceCategory.furniture],
  ['heating', MaintenanceCategory.heating],
  ['network', MaintenanceCategory.network],
  ['network and internet', MaintenanceCategory.network],
  ['other', MaintenanceCategory.other],
])

const URGENCY_BY_LABEL = new Map<string, MaintenanceUrgency>([
  ['routine', MaintenanceUrgency.routine],
  ['urgent', MaintenanceUrgency.urgent],
  ['emergency', MaintenanceUrgency.emergency],
])

export function categoryCodeOf(
  label: string | null | undefined,
): MaintenanceCategory | null {
  return label === null || label === undefined
    ? null
    : (CATEGORY_BY_LABEL.get(label.trim().toLowerCase()) ?? null)
}

export function urgencyCodeOf(
  label: string | null | undefined,
): MaintenanceUrgency | null {
  return label === null || label === undefined
    ? null
    : (URGENCY_BY_LABEL.get(label.trim().toLowerCase()) ?? null)
}

/** FR-40's «open»: everything the dormitory still owes an answer or a repair on. */
export function isOpen(status: MaintenanceRequestStatus): boolean {
  return (
    status !== MaintenanceRequestStatus.closed &&
    status !== MaintenanceRequestStatus.rejected
  )
}

/** FR-37. The state in which a request may be taken into work or refused. */
export function awaitsTriage(status: MaintenanceRequestStatus): boolean {
  return status === MaintenanceRequestStatus.submitted
}

/** FR-38, `accepted → in progress`. There is no edge straight to completion. */
export function awaitsStart(status: MaintenanceRequestStatus): boolean {
  return status === MaintenanceRequestStatus.accepted
}

/** FR-38, `in progress → completed`. */
export function awaitsCompletion(status: MaintenanceRequestStatus): boolean {
  return status === MaintenanceRequestStatus.in_progress
}

/**
 * FR-39. Whether the two buttons that belong to the reporter alone are worth
 * drawing: the request is waiting on their word, and the window is still open.
 *
 * The window is the server's — a configurable number of days, computed there
 * and carried as `confirmation_window_open` — so a screen left open past
 * midnight draws the buttons until the next read and is answered with the 409
 * that names the day the window closed. `completed` and the flag are asked
 * together because a request that has already closed on the clock keeps neither.
 */
export function awaitsReporter(request: MaintenanceRequest): boolean {
  return (
    request.status === MaintenanceRequestStatus.completed &&
    request.confirmation_window_open !== false
  )
}

/**
 * Whether this account is the person who filed the request.
 *
 * FR-39's policy is an identity comparison and not a capability — «the
 * reporter, and nobody else» — so the mirror is one too. The server compares the
 * same two identifiers and answers 403.
 */
export function isReporter(request: MaintenanceRequest, userId: number): boolean {
  return request.reporter_id === userId
}

/**
 * The planned date is missed and the request is still open — FR-40's overdue
 * read off the row rather than worked out here.
 *
 * The flag covers two cases the client could not tell apart on its own: a
 * promise broken to a named resident, and a request nobody has triaged at all,
 * which has no date to be late against and is judged against the configured
 * threshold instead.
 */
export function isOverdue(request: MaintenanceRequest): boolean {
  return request.overdue === true
}

/**
 * Today as the `min` of a planned-date field. FR-37 refuses a date already
 * past: the resident is told that date the moment the acceptance succeeds, and
 * a date behind them would be a promise broken on arrival.
 */
export function earliestTargetDate(): string {
  const now = new Date()
  const pad = (value: number) => String(value).padStart(2, '0')
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}

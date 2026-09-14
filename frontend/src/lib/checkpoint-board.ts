/**
 * What this terminal has recorded during this shift.
 *
 * **Why the board is local, and what it therefore is not.** The contract gives
 * the security post four calls — verify, the guest's consent, the entry, the
 * exit — and no list. That is not an omission: §3.9.6 gives the visitor
 * register to the administrator and the warden, and a post able to read the
 * register of a dormitory over a period is a different instrument from one that
 * cannot. So the roll of guests still inside is assembled here, from the
 * answers this terminal itself received, and the screen says exactly that. It
 * is the paper notebook the officer keeps beside the journal, not the journal.
 * The journal is the register export of FR-21, and a row is authoritative there
 * and nowhere else.
 *
 * **`sessionStorage` rather than `localStorage`.** A post terminal is a shared
 * machine in a lobby. The board holds a guest's name and the room they went to,
 * so it dies with the tab instead of waiting on the disk for the next shift;
 * entries from an earlier day are dropped on load as well, because a tab left
 * open over a night is the normal case at a post rather than the exception.
 *
 * Nothing here is a source of truth about a visit. `checked_out_at` is written
 * once, by the server, and a row removed from this board removes no record.
 */

export type BoardEntry = {
  guestRequestId: number
  visitId: number
  accessCode: string | null
  guestName: string
  room: string | null
  /** The deadline frozen on the visit at the entry: the earlier of the interval and the curfew. */
  dueAt: string | null
  checkedInAt: string
  checkedOutAt: string | null
  /** The local day the entry was recorded on, used to drop yesterday's rows. */
  day: string
}

const KEY_PREFIX = 'dormitory:checkpoint:board:'

function keyFor(buildingId: number): string {
  return `${KEY_PREFIX}${buildingId}`
}

function today(): string {
  const now = new Date()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${now.getFullYear()}-${month}-${day}`
}

/**
 * Storage can be absent or refuse to answer — a private window, a machine with
 * site data switched off. The board is a convenience, so every path through it
 * degrades to an empty list rather than to a broken terminal.
 */
function read(buildingId: number): BoardEntry[] {
  try {
    const raw = window.sessionStorage.getItem(keyFor(buildingId))
    if (raw === null) {
      return []
    }
    const parsed: unknown = JSON.parse(raw)
    if (!Array.isArray(parsed)) {
      return []
    }
    const day = today()
    return (parsed as BoardEntry[]).filter(
      (entry) => typeof entry?.visitId === 'number' && entry.day === day,
    )
  } catch {
    return []
  }
}

function write(buildingId: number, entries: BoardEntry[]): void {
  try {
    window.sessionStorage.setItem(keyFor(buildingId), JSON.stringify(entries))
  } catch {
    // A terminal that cannot remember its own shift still records entries and
    // exits; only the convenience list is lost.
  }
}

export function loadBoard(buildingId: number): BoardEntry[] {
  return read(buildingId)
}

/** Add an entry this terminal has just recorded, or replace the row for that visit. */
export function rememberEntry(buildingId: number, entry: Omit<BoardEntry, 'day'>): BoardEntry[] {
  const row: BoardEntry = { ...entry, day: today() }
  const rest = read(buildingId).filter((other) => other.visitId !== row.visitId)
  const next = [row, ...rest]
  write(buildingId, next)
  return next
}

/** Mark the exit on the row for this visit, with the time the server returned. */
export function rememberExit(
  buildingId: number,
  visitId: number,
  checkedOutAt: string | null,
): BoardEntry[] {
  const next = read(buildingId).map((entry) =>
    entry.visitId === visitId ? { ...entry, checkedOutAt } : entry,
  )
  write(buildingId, next)
  return next
}

/** Drop a row from the board. The record it refers to is untouched. */
export function forgetEntry(buildingId: number, visitId: number): BoardEntry[] {
  const next = read(buildingId).filter((entry) => entry.visitId !== visitId)
  write(buildingId, next)
  return next
}

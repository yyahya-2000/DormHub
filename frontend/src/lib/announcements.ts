import type { Announcement } from '@/api/generated/model'

/**
 * The small facts about an announcement that the feed and the publication form
 * have to agree on.
 *
 * Nothing here decides anything. Whether a notice is in somebody's feed is
 * settled by the server against the addressee column and the residency
 * register; whether it has expired is settled by the same comparison the feed
 * query makes. What this module holds is the vocabulary and the arithmetic that
 * the two screens would otherwise each write for themselves.
 */

/**
 * The value the category select carries for «something else», and the length
 * the free-typed category is cut to.
 *
 * The category is a plain string on the wire: the server offers a list of
 * ready-made ones and accepts anything else up to this length, so the field is
 * a dropdown with a way out rather than a closed enumeration. The sentinel is
 * spelled so that no ready-made code can collide with it.
 */
export const OTHER_CATEGORY = '__other__'
export const CATEGORY_MAX_LENGTH = 32

/**
 * How long a body has to be before the card folds it.
 *
 * Four lines of the feed's width run to roughly this many characters, so the
 * threshold and the clamp agree: below it the fold would hide nothing and the
 * button would be a lie.
 */
export const BODY_FOLD_LENGTH = 300

/**
 * What to print for the category of a notice.
 *
 * A ready-made category travels as a code and the server sends the name beside
 * it; one somebody typed is its own name and comes with no label. Preferring
 * the label and falling back to the value covers both without the card having
 * to know which kind it was given.
 */
export function categoryLabel(announcement: Announcement): string {
  const labelled = announcement as { category_label?: string | null }
  const label = labelled.category_label
  return label === null || label === undefined || label === ''
    ? announcement.category
    : label
}

/**
 * A `datetime-local` value for an expiry field, as the browser wants it:
 * `YYYY-MM-DDTHH:mm`, in local time. The field is empty for a notice that never
 * expires, which is what the contract means by a null `expires_at`.
 */
export function expiryDefault(daysAhead: number): string {
  const moment = new Date()
  moment.setDate(moment.getDate() + daysAhead)
  const pad = (value: number) => String(value).padStart(2, '0')
  return (
    `${moment.getFullYear()}-${pad(moment.getMonth() + 1)}-${pad(moment.getDate())}` +
    `T${pad(moment.getHours())}:${pad(moment.getMinutes())}`
  )
}

/**
 * The moment a `datetime-local` field names, as an ISO instant the API accepts.
 *
 * The field carries no zone, so the browser's own is the one meant: a manager
 * typing «23:00» means eleven in the evening where the dormitory is. `Date`
 * reads a zoneless string as local time and `toISOString` states which instant
 * that was, so the two together are the conversion and not a string edit.
 */
export function expiryInstant(value: string): string | null {
  if (value === '') {
    return null
  }
  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? null : parsed.toISOString()
}

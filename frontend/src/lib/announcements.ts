import { AnnouncementCategory, type Announcement } from '@/api/generated/model'

/**
 * The small facts about an announcement that the feed, the publication form and
 * the readers report have to agree on.
 *
 * Nothing here decides anything. Whether a notice is in somebody's feed is
 * settled by the server against the addressee column and the residency
 * register; whether it has expired is settled by the same comparison the feed
 * query makes. What this module holds is the vocabulary and the arithmetic that
 * three screens would otherwise each write for themselves.
 */

/**
 * The categories, in the order the filter offers them.
 *
 * Drawn from the generated enum rather than typed out, so a category added to
 * the contract appears in the filter on the next `npm run api:generate` instead
 * of quietly going missing from it. The order is the contract's, which runs
 * from the standing rules of the house down to the miscellaneous.
 */
export const ANNOUNCEMENT_CATEGORIES: AnnouncementCategory[] =
  Object.values(AnnouncementCategory)

/**
 * The tone of a category. Four groups rather than five colours: the rules of
 * the house and safety are the two a resident is answerable to, planned works
 * interrupt the water and the lifts, and events and the general sort are
 * neither.
 */
export const CATEGORY_TONE: Record<AnnouncementCategory, string> = {
  house_rules: 'border-prussian/30 bg-prussian-wash text-prussian',
  safety: 'border-brick/40 bg-brick-wash text-brick',
  utilities: 'border-brass/45 bg-brass-wash text-brass',
  events: 'border-rule bg-paper text-steel',
  general: 'border-rule bg-paper text-steel',
}

/**
 * Whether this reader has acknowledged the notice.
 *
 * `acknowledged_at` and `is_unread` are two views of the same left join, and
 * the flag is the one the contract says is «FR-11's mark». The timestamp is
 * read only where the moment itself is shown.
 */
export function acknowledged(announcement: Announcement): boolean {
  return (
    announcement.acknowledged_at !== null && announcement.acknowledged_at !== undefined
  )
}

/**
 * FR-12: the notice asks to be acknowledged, and has not been.
 *
 * The obligation and the category are two columns and not one — a water shutoff
 * is `utilities` and is normally mandatory, a film evening is `events` and never
 * is — so the question is asked of `is_mandatory` and never of the category.
 */
export function awaitsAcknowledgement(announcement: Announcement): boolean {
  return announcement.is_mandatory && !acknowledged(announcement)
}

/**
 * The acknowledged share as whole percent.
 *
 * The fraction arrives from the server, which computes it against the audience
 * the register defines, and is never recomputed here from the two lists: those
 * are the caller's own dormitory and the figure is about the whole audience,
 * so a client dividing one by the other would print a different and wrong
 * number. An empty audience gives 1 on the wire — «nobody to chase» — and this
 * turns it into 100 rather than into a red nought.
 */
export function sharePercent(share: number): number {
  return Math.round(Math.min(1, Math.max(0, share)) * 100)
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
 * The field carries no zone, so the browser's own is the one meant: a warden
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

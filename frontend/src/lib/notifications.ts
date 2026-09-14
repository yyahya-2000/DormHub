import type { Notification, NotificationPayload } from '@/api/generated/model'
import { NotificationCategory } from '@/api/generated/model'

/**
 * Reading a notification without trusting its body.
 *
 * `payload` is `{ [key: string]: unknown }` in the contract, and the comment
 * beside it says why: each notification class decides its own shape, and a
 * category added in a later increment must not force this document — or this
 * client — to change for the existing ones to keep working. That openness is
 * useful on the wire and dangerous on a screen, so every field a component
 * draws is read through one of the accessors below and comes back `null` when
 * it is missing or of another type. A message whose body is not what this
 * build expects is rendered short rather than rendered wrong.
 *
 * The classes named here are the ones the API sends today. `account_issued`
 * is deliberately absent: it carries the one-time credential of FR-42, goes out
 * by mail only and leaves no row behind, so the personal account never has one
 * to draw. The settings screen still lists the category, because the switch is
 * a question about delivery and not about this table.
 */

/** The message classes this build knows how to put into words. */
export const NotificationType = {
  guestRequestDecided: 'GuestRequestDecided',
  guestVisitOverdue: 'GuestVisitOverdue',
  maintenanceStatusChanged: 'MaintenanceRequestStatusChanged',
  documentAwaitingSignature: 'DocumentAwaitingSignature',
  /*
   * The two of the lost-and-found module, and between them they carry the whole
   * of the module the screens cannot show. A claimant never reads the claims on
   * an entry — the marks are what makes a claim checkable, and a list of them
   * readable by the corridor would tell the next claimant what to write — so
   * the decision on their own claim reaches them here and nowhere else, and
   * FR-26's offer of a referral rides on it. The holder and, on a referral, the
   * warden are told the same way that something is waiting for them.
   */
  lostFoundClaimFiled: 'LostFoundClaimFiled',
  lostFoundClaimDecided: 'LostFoundClaimDecided',
} as const

export type NotificationType = (typeof NotificationType)[keyof typeof NotificationType]

const CATEGORIES: readonly string[] = Object.values(NotificationCategory)

/**
 * The category as an enumerated value, or null. The field is nullable in the
 * contract, and a category this build has never heard of is treated the same
 * way as none at all — the message is still shown, under its own words.
 */
export function categoryOf(notification: Notification): NotificationCategory | null {
  const value = notification.category
  if (value === null || value === undefined) {
    return null
  }
  return CATEGORIES.includes(value) ? value : null
}

/**
 * The word the API used for the category, whether or not this build's contract
 * has caught up with it.
 *
 * `categoryOf` above answers «which of the categories I know is this», and is
 * the right question wherever the answer decides something. This one answers
 * «what did the server call it», and is the right question for a caption: the
 * description of `NotificationCategory` is a closed enumeration that a new
 * occasion adds to, and a message of a category added since the client was
 * generated is still a message the dormitory decided to send. Captioning it
 * «no category» would hide that; looking the word up in the locale files, with
 * the reader's own «no category» underneath, shows it either way.
 */
export function categoryKeyOf(notification: Notification): string | null {
  const value = notification.category
  return typeof value === 'string' && value.trim() !== '' ? value : null
}

export function textOf(payload: NotificationPayload, key: string): string | null {
  const value = payload[key]
  return typeof value === 'string' && value.trim() !== '' ? value : null
}

export function numberOf(payload: NotificationPayload, key: string): number | null {
  const value = payload[key]
  return typeof value === 'number' && Number.isFinite(value) ? value : null
}

export function flagOf(payload: NotificationPayload, key: string): boolean | null {
  const value = payload[key]
  return typeof value === 'boolean' ? value : null
}

/** Whether the message has never been marked read. */
export function isUnread(notification: Notification): boolean {
  return notification.read_at === null || notification.read_at === undefined
}

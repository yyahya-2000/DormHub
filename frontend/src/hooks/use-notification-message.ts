import { useTranslation } from 'react-i18next'

import type { Notification } from '@/api/generated/model'
import { useFormatters } from '@/lib/format'
import {
  NotificationType,
  categoryOf,
  flagOf,
  numberOf,
  textOf,
} from '@/lib/notifications'

/**
 * One message of the personal account, put into a sentence.
 *
 * The API sends a class name and an open body, never a rendered string, and
 * that is the right division: a message composed on the server would arrive in
 * the language the queue worker happened to run in, and it would arrive with
 * the date formatted by whichever library the worker uses. Here the words come
 * from the locale files and the times go through `Intl` — the same rule the
 * visiting journal will live under, where a misread hour is a dispute.
 *
 * A class this build does not recognise still gets a line. It is shown under
 * the name of its category, which the API lifted out of the payload for exactly
 * this purpose, and under the class name if even the category is unknown. The
 * alternative — dropping the row — would hide a message the dormitory decided
 * to send, and an invisible notification is worse than an ugly one.
 *
 * The states of a maintenance request are the one vocabulary here that the
 * contract does not enumerate — FR-38 belongs to a later increment, and the
 * payload is open by design — so `maintenanceStatus.*` translates the codes the
 * API sends today and an unknown one falls through as itself rather than as a
 * blank. That is the same bargain the `defaultValue` on every category label
 * strikes: the server's own word, in the server's own language, is a worse
 * screen than the translated one and a better screen than nothing.
 */
export function NotificationMessage({ notification }: { notification: Notification }) {
  const { t } = useTranslation()
  const formatters = useFormatters()

  const payload = notification.payload
  const category = categoryOf(notification)
  const unknownName = t('notifications.unknownMessage', { type: notification.type })
  const fallback = category === null ? unknownName : t(`notificationCategory.${category}.label`)

  let headline = fallback
  const details: string[] = []

  if (notification.type === NotificationType.guestRequestDecided) {
    const approved = flagOf(payload, 'approved')
    const guest = textOf(payload, 'guest_name') ?? t('notifications.unnamedGuest')
    headline =
      approved === true
        ? t('notifications.requestApproved', { guest })
        : approved === false
          ? t('notifications.requestRefused', { guest })
          : t('notifications.requestDecided', { guest })
    const id = numberOf(payload, 'guest_request_id')
    if (id !== null) {
      // An identifier, not a quantity: no thousands separator. «Заявка № 3 001»
      // is a number somebody will read aloud over the telephone to the duty
      // officer, and a space in the middle of it is a transcription error
      // waiting to happen.
      details.push(t('notifications.requestNumber', { number: String(id) }))
    }
    const comment = textOf(payload, 'comment')
    if (comment !== null) {
      details.push(t('notifications.comment', { comment }))
    }
  } else if (notification.type === NotificationType.guestVisitOverdue) {
    const guest = textOf(payload, 'guest_name') ?? t('notifications.unnamedGuest')
    headline = t('notifications.visitOverdue', { guest })
    const dueAt = textOf(payload, 'due_at')
    if (dueAt !== null) {
      details.push(t('notifications.visitDue', { time: formatters.dateTime(dueAt) }))
    }
    const building = textOf(payload, 'building_name')
    if (building !== null) {
      details.push(t('notifications.visitBuilding', { building }))
    }
    // Clause 2.2 of the rules of internal order is why this category cannot be
    // switched off, and why the message names the person who has to act rather
    // than merely reporting a fact.
    details.push(t('notifications.visitDuty'))
  } else if (notification.type === NotificationType.maintenanceStatusChanged) {
    const to = textOf(payload, 'to_status')
    headline =
      to === null
        ? t('notificationCategory.maintenance_status.label')
        : t('notifications.maintenanceMoved', {
            status: t(`maintenanceStatus.${to}`, { defaultValue: to }),
          })
    const from = textOf(payload, 'from_status')
    if (from !== null) {
      details.push(
        t('notifications.maintenanceFrom', {
          status: t(`maintenanceStatus.${from}`, { defaultValue: from }),
        }),
      )
    }
    const id = numberOf(payload, 'maintenance_request_id')
    if (id !== null) {
      details.push(t('notifications.maintenanceNumber', { number: String(id) }))
    }
    const comment = textOf(payload, 'comment')
    if (comment !== null) {
      details.push(t('notifications.comment', { comment }))
    }
  } else if (notification.type === NotificationType.lostFoundClaimFiled) {
    /*
     * Two messages in one class, told apart by `referred`. Somebody claims an
     * entry you are holding — or a refusal you already gave was not accepted
     * and the warden is now being asked. The marks travel with both, because
     * they are the whole of what the reader has to judge.
     */
    const title = textOf(payload, 'item_title')
    const referred = flagOf(payload, 'referred') === true
    headline =
      title === null
        ? t('notifications.lostFoundClaimed')
        : t(referred ? 'notifications.lostFoundReferred' : 'notifications.lostFoundClaim', {
            title,
          })
    const marks = textOf(payload, 'marks')
    if (marks !== null) {
      details.push(t('notifications.lostFoundMarks', { marks }))
    }
  } else if (notification.type === NotificationType.lostFoundClaimDecided) {
    const title = textOf(payload, 'item_title') ?? t('notifications.lostFoundUnnamed')
    const status = textOf(payload, 'status')
    const byStaff = flagOf(payload, 'decided_by_staff') === true
    headline =
      status === 'accepted'
        ? t(byStaff ? 'notifications.lostFoundUpheld' : 'notifications.lostFoundAccepted', {
            title,
          })
        : status === 'declined'
          ? t(
              byStaff ? 'notifications.lostFoundNotUpheld' : 'notifications.lostFoundDeclined',
              { title },
            )
          : t('notifications.lostFoundDecided', { title })
    // §2.4.4's handover point: the one piece of location this module publishes,
    // and the only thing in the message the claimant has to act on.
    const handover = textOf(payload, 'handover_point')
    if (handover !== null) {
      details.push(t('notifications.lostFoundHandover', { place: handover }))
    }
    const note = textOf(payload, 'note')
    if (note !== null) {
      details.push(t('notifications.comment', { comment: note }))
    }
  } else if (notification.type === NotificationType.documentAwaitingSignature) {
    const title = textOf(payload, 'title')
    headline =
      title === null
        ? t('notificationCategory.document_signature.label')
        : t('notifications.documentAwaiting', { title })
    const dueAt = textOf(payload, 'due_at')
    if (dueAt !== null) {
      details.push(t('notifications.documentDue', { date: formatters.dateTime(dueAt) }))
    }
    const revision = textOf(payload, 'document_revision')
    if (revision !== null) {
      details.push(t('notifications.documentRevision', { revision }))
    }
  }

  return (
    <div className="grid min-w-0 gap-1">
      <p className="m-0 font-medium break-words text-ink">{headline}</p>
      {details.map((line) => (
        <p key={line} className="m-0 break-words text-steel">
          {line}
        </p>
      ))}
    </div>
  )
}

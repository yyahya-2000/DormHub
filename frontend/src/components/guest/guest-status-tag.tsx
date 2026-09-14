import { useTranslation } from 'react-i18next'

import type { GuestRequestStatus, GuestVisitStatus } from '@/api/generated/model'
import { GUEST_STATUS_TONE, VISIT_STATUS_TONE } from '@/lib/guest'
import { cn } from '@/lib/utils'

/**
 * The state of a request, in the reader's language and in one of three tones.
 *
 * `status_label` comes down with every request and is the server's own sentence
 * about the state; it is used as the fallback and never as the first choice, so
 * that a Russian screen does not read «Approved, guest expected at the post».
 * The label is still worth carrying: a state this interface has not been taught
 * yet reads as a sentence instead of as a key.
 */
export function GuestStatusTag({
  status,
  label,
  className,
}: {
  status: GuestRequestStatus
  label?: string
  className?: string
}) {
  const { t } = useTranslation()

  return (
    <span
      className={cn(
        'inline-block border px-2 py-0.5 font-medium',
        GUEST_STATUS_TONE[status] ?? 'border-rule bg-paper text-steel',
        className,
      )}
    >
      {t(`guestStatus.${status}`, { defaultValue: label ?? status })}
    </span>
  )
}

export function VisitStatusTag({
  status,
  label,
  className,
}: {
  status: GuestVisitStatus
  label?: string
  className?: string
}) {
  const { t } = useTranslation()

  return (
    <span
      className={cn(
        'inline-block border px-2 py-0.5 font-medium',
        VISIT_STATUS_TONE[status] ?? 'border-rule bg-paper text-steel',
        className,
      )}
    >
      {t(`visitStatus.${status}`, { defaultValue: label ?? status })}
    </span>
  )
}

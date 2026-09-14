import { useTranslation } from 'react-i18next'

import type {
  MaintenanceRequestStatus,
  MaintenanceUrgency,
} from '@/api/generated/model'
import { STATUS_TONE, URGENCY_TONE } from '@/lib/maintenance'
import { cn } from '@/lib/utils'

/**
 * The three marks a maintenance row carries: its state, its urgency, and
 * whether it is late.
 *
 * `status_label` and `urgency_label` come down with every request and are the
 * server's own sentence about the row. They are the fallback and never the
 * first choice, so a Russian screen does not read «In progress» — but they are
 * worth carrying, because a value this build has not been taught yet then reads
 * as a sentence instead of as a key.
 */
export function MaintenanceStatusTag({
  status,
  label,
  className,
}: {
  status: MaintenanceRequestStatus
  label?: string
  className?: string
}) {
  const { t } = useTranslation()

  return (
    <span
      className={cn(
        'inline-block border px-2 py-0.5 font-medium',
        STATUS_TONE[status] ?? 'border-rule bg-paper text-steel',
        className,
      )}
    >
      {t(`maintenanceStatus.${status}`, { defaultValue: label ?? status })}
    </span>
  )
}

export function MaintenanceUrgencyTag({
  urgency,
  label,
  className,
}: {
  urgency: MaintenanceUrgency
  label?: string
  className?: string
}) {
  const { t } = useTranslation()

  return (
    <span
      className={cn(
        'inline-block border px-2 py-0.5 font-medium',
        URGENCY_TONE[urgency] ?? 'border-rule bg-paper text-steel',
        className,
      )}
    >
      {t(`maintenanceUrgency.${urgency}`, { defaultValue: label ?? urgency })}
    </span>
  )
}

/**
 * FR-40, second criterion. The flag is configuration and not code: it arrives
 * true or false on the row, measured against a threshold a warden can change
 * without a deployment, and this component draws it and never works it out.
 */
export function OverdueTag({ className }: { className?: string }) {
  const { t } = useTranslation()

  return (
    <span
      className={cn(
        'inline-block border border-brick/50 bg-brick-wash px-2 py-0.5 font-medium text-brick',
        className,
      )}
    >
      {t('maintenance.overdue')}
    </span>
  )
}

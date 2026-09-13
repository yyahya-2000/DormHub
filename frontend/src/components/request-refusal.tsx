import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

import { ApiError } from '@/api/http-client'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  asCapacityExceeded,
  asDeletionBlocked,
  asMandatoryCategory,
  asResidencyConflict,
  fieldMessages,
  serverMessage,
} from '@/lib/api-refusal'
import { useFormatters } from '@/lib/format'
import { cn } from '@/lib/utils'

/**
 * The server's refusal, said in the reader's language.
 *
 * A 403 here is not a failure of the interface: it is the role model of FR-07
 * answering, and the page says so plainly instead of showing an empty table.
 *
 * Three refusals of this slice carry more than a status, and each is read out
 * rather than flattened into «server error». A blocked deletion states what is
 * attached (FR-01), a rejected place states the free remainder (FR-02), a
 * refused residency hands back the record in the way (FR-03). The figures come
 * from the body; none of them is computed here.
 */
export function RequestRefusal({
  error,
  className,
}: {
  error: unknown
  className?: string
}) {
  const { t } = useTranslation()
  const formatters = useFormatters()

  const status = error instanceof ApiError ? error.status : null
  const blocked = asDeletionBlocked(error)
  const conflict = asResidencyConflict(error)
  const capacity = status === 422 ? asCapacityExceeded(error) : null
  const mandatory = asMandatoryCategory(error)
  const fields = fieldMessages(error)

  let title = t('refusal.title')
  let body: ReactNode = t('errors.network')

  if (blocked !== null) {
    title = t('refusal.blockedTitle')
    body = (
      <>
        <ul className="m-0 list-none p-0">
          {Object.entries(blocked.blocked_by).map(([kind, count]) => (
            <li key={kind}>
              {t(`refusal.blockedBy.${kind}`, {
                count: Number(count),
                defaultValue: t('refusal.blockedByOther', {
                  kind,
                  count: formatters.count(Number(count)),
                }),
              })}
            </li>
          ))}
        </ul>
        <p className="mt-2 mb-0">{t('refusal.blockedWayOut')}</p>
      </>
    )
  } else if (capacity !== null) {
    title = t('refusal.capacityTitle')
    body = (
      <>
        <p className="m-0">
          {t('refusal.capacityBody', {
            room: capacity.room_number ?? t('common.empty'),
            capacity: formatters.count(capacity.capacity),
            beds: formatters.count(capacity.beds),
          })}
        </p>
        <p className="mt-2 mb-0 font-medium">
          {t('refusal.capacityRemainder', {
            count: capacity.free_places,
          })}
        </p>
      </>
    )
  } else if (conflict !== null) {
    const record = conflict.conflict
    title = t('refusal.conflictTitle')
    body =
      record === null || record === undefined ? (
        <p className="m-0">{t('refusal.conflictUnknown')}</p>
      ) : (
        <>
          <p className="m-0">
            {t('refusal.conflictBody', {
              name: record.resident_name ?? t('refusal.conflictAnonymous'),
              since: formatters.date(record.moved_in_at),
            })}
          </p>
          <p className="mt-2 mb-0">
            {t('refusal.conflictWhere', {
              room: record.room_number ?? t('common.empty'),
              bed: record.bed_label ?? t('common.empty'),
              contract: record.contract_number,
            })}
          </p>
        </>
      )
  } else if (mandatory !== null) {
    /*
     * FR-34. The server refused to switch a category off, and the answer names
     * which one. The ground is repeated here rather than only on the settings
     * screen, because this refusal can also arrive from a stale page whose
     * switch was drawn before the server changed its mind about the category.
     */
    const label = t(`notificationCategory.${mandatory.category}.label`, {
      defaultValue: mandatory.category_label,
    })
    title = t('refusal.mandatoryTitle')
    body = (
      <>
        <p className="m-0">{t('refusal.mandatoryBody', { category: label })}</p>
        <p className="mt-2 mb-0">
          {t(`notificationCategory.${mandatory.category}.ground`, {
            defaultValue: t('notificationSettings.groundUnstated'),
          })}
        </p>
      </>
    )
  } else if (fields.length > 0) {
    title = t('refusal.validationTitle')
    body = (
      <ul className="m-0 list-none p-0">
        {fields.map((entry) => (
          <li key={entry.field}>
            <span className="font-medium">
              {t(`fields.${entry.field}`, { defaultValue: entry.field })}
            </span>
            {': '}
            {entry.messages.join(' ')}
          </li>
        ))}
      </ul>
    )
  } else if (status === 403) {
    title = t('refusal.forbiddenTitle')
    body = t('errors.forbidden')
  } else if (status === 404) {
    title = t('refusal.notFoundTitle')
    body = t('errors.notFound')
  } else if (status !== null && status >= 500) {
    body = t('refusal.serverFault')
  } else if (status !== null) {
    body = t('errors.unexpected', { status })
  }

  /*
   * The server's own sentence is shown for a refusal and withheld for a fault.
   * A 4xx body is written by the application and is evidence of what was
   * decided; a 5xx body is whatever leaked out of the failure, and a driver
   * message carrying a constraint name and the SQL around it does not belong on
   * a warden's screen. The status is still shown, so nothing is hidden.
   */
  const said = status !== null && status >= 500 ? null : serverMessage(error)

  return (
    <Alert
      variant="destructive"
      className={cn('border-l-4 border-brick bg-brick-wash text-ink', className)}
    >
      <AlertTitle className="text-ink">{title}</AlertTitle>
      <AlertDescription className="grid gap-1 text-ink">
        <div>{body}</div>
        {/*
          The server's own sentence, kept underneath rather than in place of the
          translated one. It is written in English by the framework and is not
          an interface string; it is evidence of what was refused, and on a
          disputed record that is worth more than a tidier screen.
        */}
        {said !== null ? (
          <p className="m-0 text-steel">{t('refusal.serverSays', { message: said })}</p>
        ) : null}
      </AlertDescription>
    </Alert>
  )
}

import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

import { ApiError } from '@/api/http-client'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  asCapacityExceeded,
  asConfirmationWindowClosed,
  asConsentRequired,
  asDeletionBlocked,
  asEntryNotPermitted,
  asIllegalTransition,
  asNoAcceptedClaim,
  asQuotaSpent,
  asResidencyConflict,
  asVisitAlreadyClosed,
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
/**
 * Which vocabulary a state code in a refusal is read in.
 *
 * `IllegalTransitionError` is one schema serving two state machines, and its
 * `status` field is typed against the guest one because the guest module was
 * written first. The codes overlap — `rejected`, `completed` and `in_progress`
 * exist in both and do not mean the same thing — so the body alone cannot say
 * which machine refused. The screen that made the call can, and does: a
 * maintenance screen passes `maintenance` and the code is translated from that
 * namespace. Nothing is decided by this; it chooses a noun.
 *
 * `lostFound` is the third, and it is the one that needs two namespaces rather
 * than one: the refused move may be a transition of an entry — published,
 * claimed, resolved — or of a claim against it, and the two enumerations share
 * a schema and no values. The code is looked for in both, in that order, and
 * falls through to the server's own word when it is in neither.
 */
export type StatusVocabulary = 'guest' | 'maintenance' | 'lostFound'

/**
 * The code of a refused transition, read in the vocabulary of the screen that
 * made the call. Never a decision: it chooses which namespace the word is
 * looked up in, and the server has already refused.
 */
function transitionWord(
  vocabulary: StatusVocabulary,
  status: string,
  t: (key: string, options?: Record<string, unknown>) => string,
): string {
  if (vocabulary === 'maintenance') {
    return t(`maintenanceStatus.${status}`, { defaultValue: status })
  }
  if (vocabulary === 'lostFound') {
    return t(`lostFoundStatus.${status}`, {
      defaultValue: t(`lostFoundClaimStatus.${status}`, { defaultValue: status }),
    })
  }
  return t(`guestStatus.${status}`, { defaultValue: status })
}

export function RequestRefusal({
  error,
  className,
  vocabulary = 'guest',
}: {
  error: unknown
  className?: string
  vocabulary?: StatusVocabulary
}) {
  const { t } = useTranslation()
  const formatters = useFormatters()

  const status = error instanceof ApiError ? error.status : null
  const blocked = asDeletionBlocked(error)
  const conflict = asResidencyConflict(error)
  const capacity = status === 422 ? asCapacityExceeded(error) : null
  const entry = asEntryNotPermitted(error)
  const quota = asQuotaSpent(error)
  const consentNeeded = asConsentRequired(error)
  const transition = asIllegalTransition(error)
  const windowClosed = asConfirmationWindowClosed(error)
  const visitClosed = asVisitAlreadyClosed(error)
  const noAcceptedClaim = asNoAcceptedClaim(error)
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
  } else if (entry !== null) {
    /*
     * §2.4.2's second scenario. The sentence the officer needs is not «422»:
     * it is which rule refused the record, and whether the responsible
     * officer's decision may set it aside. Both come from the body.
     */
    title = t('checkpoint.refusal.title')
    body = (
      <>
        <p className="m-0">
          {t(`checkpoint.reason.${entry.reason_code}`, {
            defaultValue: entry.message,
          })}
        </p>
        <p className="mt-2 mb-0">
          {entry.override_available === true
            ? t('checkpoint.refusal.overridable')
            : t('checkpoint.refusal.final')}
        </p>
      </>
    )
  } else if (quota !== null) {
    title = t('guestQueue.quotaTitle')
    body = (
      <p className="m-0">
        {t(`guestQueue.quotaBody.${quota.quota_scope}`, {
          limit: formatters.count(quota.quota_limit),
          date: formatters.date(quota.visit_date),
          defaultValue: quota.message,
        })}
      </p>
    )
  } else if (consentNeeded !== null) {
    title = t('checkpoint.consent.requiredTitle')
    body = (
      <p className="m-0">
        {t('checkpoint.consent.requiredBody', { revision: consentNeeded.revision })}
      </p>
    )
  } else if (visitClosed !== null) {
    title = t('checkpoint.exit.alreadyTitle')
    body = (
      <p className="m-0">
        {t('checkpoint.exit.alreadyBody', {
          time: formatters.dateTime(visitClosed.checked_out_at),
        })}
      </p>
    )
  } else if (windowClosed !== null) {
    /*
     * FR-39. Read before the illegal transition because it is the more specific
     * of the two 409s this route can answer with, and the only one that tells
     * the resident why the same button would have worked a day earlier.
     */
    title = t('maintenance.windowClosedTitle')
    body = (
      <>
        <p className="m-0">
          {t('maintenance.windowClosedBody', {
            count: windowClosed.confirmation_window_days ?? 0,
          })}
        </p>
        {windowClosed.window_closed_on !== null &&
        windowClosed.window_closed_on !== undefined ? (
          <p className="mt-2 mb-0">
            {t('maintenance.windowClosedOn', {
              date: formatters.date(windowClosed.window_closed_on),
            })}
          </p>
        ) : null}
      </>
    )
  } else if (noAcceptedClaim !== null) {
    /*
     * FR-26, first criterion. Read before the illegal transition for the same
     * reason the confirmation window is: the move itself is admissible, and
     * what is missing is somebody to hand the object to.
     */
    title = t('lostFound.noAcceptedTitle')
    body = (
      <>
        <p className="m-0">{t('lostFound.noAcceptedBody')}</p>
        {noAcceptedClaim.outstanding_claims !== undefined ? (
          <p className="mt-2 mb-0">
            {t('lostFound.noAcceptedOutstanding', {
              count: noAcceptedClaim.outstanding_claims,
            })}
          </p>
        ) : null}
      </>
    )
  } else if (transition !== null) {
    title = t('guestQueue.transitionTitle')
    body = (
      <p className="m-0">
        {t('guestQueue.transitionBody', {
          status: transitionWord(vocabulary, transition.status ?? '', t),
        })}
      </p>
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

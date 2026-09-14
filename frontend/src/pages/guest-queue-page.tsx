import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  useApproveGuestRequest,
  useListGuestRequests,
  useRejectGuestRequest,
  type listGuestRequestsResponse,
} from '@/api/generated/dormitory'
import { GuestRequestStatus, type GuestRequest } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { decidesGuestRequests, readsGuestDocument } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { BuildingTabs } from '@/components/building-tabs'
import { FormField, selectClassName } from '@/components/form-field'
import { DocumentNumberReveal } from '@/components/guest/document-number-reveal'
import { GuestStatusTag } from '@/components/guest/guest-status-tag'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { useFormatters } from '@/lib/format'
import { awaitsDecision } from '@/lib/guest'
import { useGuestRefresh } from '@/lib/guest-cache'

/**
 * FR-17, the duty officer's queue.
 *
 * **Whose screen this is.** The decision belongs to the duty officer of this
 * dormitory and to nobody else — not the warden, not the manager, not the
 * administrator. That is the agreement of 13.09.2026, it sits on one capability
 * on the server, and here it decides only whether the two buttons are drawn.
 * The other three roles read the same queue and see it without them; a token
 * that is not the duty officer's is refused by the API, and the refusal is
 * shown where the row was (§3.3.2).
 *
 * **Designed to be worked from a telephone.** The register of stakeholders puts
 * it plainly: the decision has to take seconds and be taken away from a desk.
 * So a row is not a table cell — it is a block with the four facts a decision
 * needs, and the two actions are full-width targets under it. Nothing is behind
 * a menu, nothing needs a second screen, and the whole queue is one column at
 * every width.
 *
 * **A refusal cannot be silent.** FR-17's second criterion is «rejection
 * without a reason is impossible», and it is enforced in three places at once:
 * the route requires the field, the service requires the argument, and the form
 * below will not submit an empty one. The directory is a convenience over the
 * free text and not a replacement for it — a reason nobody foresaw is typed.
 */

/**
 * The reasons a duty officer gives most often, as a directory over the free
 * text field. They are interface strings and not a server enumeration: the API
 * stores whatever sentence the officer sends, which is what keeps an unforeseen
 * reason expressible. Picking one fills the field; it can then be edited.
 */
const REJECTION_REASONS = [
  'quotaSpent',
  'outsideWindow',
  'documentUnclear',
  'residentAbsent',
  'quarantine',
] as const

const QUEUE_FILTERS: (GuestRequestStatus | 'all')[] = [
  GuestRequestStatus.pending_review,
  GuestRequestStatus.approved,
  GuestRequestStatus.in_progress,
  GuestRequestStatus.overdue,
  GuestRequestStatus.rejected,
  'all',
]

export function GuestQueuePage() {
  const { t } = useTranslation()
  const { session } = useSession()
  const params = useParams<{ buildingId: string }>()
  const buildingId = Number(params.buildingId)

  const user = session.status === 'authenticated' ? session.user : null
  const decides = user !== null && decidesGuestRequests(user, buildingId)
  const readsDocument = user !== null && readsGuestDocument(user, buildingId)

  const [status, setStatus] = useState<GuestRequestStatus | 'all'>(
    GuestRequestStatus.pending_review,
  )
  const [visitDate, setVisitDate] = useState('')

  const queue = useListGuestRequests<listGuestRequestsResponse, ApiError>(
    {
      building_id: buildingId,
      ...(status === 'all' ? {} : { status }),
      ...(visitDate === '' ? {} : { visit_date: visitDate }),
    },
    { query: { enabled: Number.isInteger(buildingId), retry: false } },
  )

  const requests = queue.data?.status === 200 ? queue.data.data.data : null

  return (
    <div className="grid grid-cols-1 gap-8">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">{t('guestQueue.heading')}</h1>
        <p className="mt-1 text-steel">
          {decides ? t('guestQueue.leadDuty') : t('guestQueue.leadReadOnly')}
        </p>
      </div>

      <BuildingTabs buildingId={buildingId} />

      <Panel caption={t('guestQueue.filterHeading')}>
        <div className="grid gap-4 px-4 py-4 sm:grid-cols-2">
          <FormField id="queue-status" label={t('guestQueue.filterStatus')}>
            <select
              id="queue-status"
              className={selectClassName}
              value={status}
              onChange={(event) =>
                setStatus(event.target.value as GuestRequestStatus | 'all')
              }
            >
              {QUEUE_FILTERS.map((value) => (
                <option key={value} value={value}>
                  {value === 'all'
                    ? t('guestQueue.filterAll')
                    : t(`guestStatus.${value}`)}
                </option>
              ))}
            </select>
          </FormField>
          <FormField
            id="queue-date"
            label={t('guestQueue.filterDate')}
            note={t('guestQueue.filterDateNote')}
          >
            <Input
              id="queue-date"
              type="date"
              value={visitDate}
              onChange={(event) => setVisitDate(event.target.value)}
            />
          </FormField>
        </div>
      </Panel>

      <Panel
        caption={t('guestQueue.listHeading')}
        aside={
          requests !== null
            ? t('guestQueue.listCount', { count: requests.length })
            : undefined
        }
      >
        {queue.isError ? (
          <div className="px-4 py-4">
            <RequestRefusal error={queue.error} />
          </div>
        ) : null}

        {queue.isPending && !queue.isError ? (
          <div className="grid gap-2 px-4 py-4" aria-hidden="true">
            <Skeleton className="h-32 w-full" />
            <Skeleton className="h-32 w-full" />
          </div>
        ) : null}

        {requests !== null && requests.length === 0 ? (
          <p className="px-4 py-6 text-steel">{t('guestQueue.empty')}</p>
        ) : null}

        {requests !== null && requests.length > 0 ? (
          <ul className="m-0 list-none p-0">
            {requests.map((request) => (
              <li key={request.id} className="border-b border-rule/70 last:border-b-0">
                <QueueRow
                  request={request}
                  decides={decides}
                  readsDocument={readsDocument}
                />
              </li>
            ))}
          </ul>
        ) : null}
      </Panel>
    </div>
  )
}

function QueueRow({
  request,
  decides,
  readsDocument,
}: {
  request: GuestRequest
  decides: boolean
  readsDocument: boolean
}) {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const refresh = useGuestRefresh()

  const [rejecting, setRejecting] = useState(false)
  const [reason, setReason] = useState('')
  const [officerMark, setOfficerMark] = useState('')

  const approve = useApproveGuestRequest<ApiError>()
  const reject = useRejectGuestRequest<ApiError>()

  /*
   * FR-23, second criterion, mirrored so the field is on the screen before the
   * 422 rather than after it. Both halves matter: a foreign document on a visit
   * that ends the same evening creates no place of stay, and asking for the
   * mark there would overstate the norm. The server decides either way.
   */
  const marksRequired =
    request.is_foreign_document === true && request.spans_more_than_one_day === true
  const open = awaitsDecision(request.status)

  return (
    <article className="grid gap-3 px-4 py-4">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h3 className="m-0 min-w-0 text-lg font-semibold break-words text-ink">
          {request.guest_full_name}
        </h3>
        <GuestStatusTag status={request.status} label={request.status_label} />
      </div>

      <dl className="m-0 grid gap-x-4 gap-y-1 sm:grid-cols-[minmax(0,13rem)_minmax(0,1fr)]">
        <dt className="label-caps">{t('guestQueue.fields.when')}</dt>
        <dd className="m-0 text-ink">
          {formatters.date(request.visit_date)} · {request.planned_from}–
          {request.planned_to}
        </dd>
        <dt className="label-caps">{t('guestQueue.fields.host')}</dt>
        <dd className="m-0 break-words text-ink">{request.student_name}</dd>
        <dt className="label-caps">{t('guest.fields.docType')}</dt>
        <dd className="m-0 text-ink">
          {t(`guestDocumentType.${request.guest_doc_type}`, {
            defaultValue: request.guest_doc_type_label ?? '',
          })}{' '}
          <span className="font-mono">{request.guest_doc_number_masked}</span>
        </dd>
        {request.purpose !== null &&
        request.purpose !== undefined &&
        request.purpose !== '' ? (
          <>
            <dt className="label-caps">{t('guest.fields.purpose')}</dt>
            <dd className="m-0 break-words text-ink">{request.purpose}</dd>
          </>
        ) : null}
        {request.decided_at !== null && request.decided_at !== undefined ? (
          <>
            <dt className="label-caps">{t('guestQueue.fields.decidedAt')}</dt>
            <dd className="m-0 text-ink">{formatters.dateTime(request.decided_at)}</dd>
          </>
        ) : null}
      </dl>

      {request.foreign_guest_warning !== null &&
      request.foreign_guest_warning !== undefined ? (
        <section className="border-l-4 border-brass bg-brass-wash px-3 py-2">
          <h4 className="m-0 font-semibold text-ink">{t('guest.foreignTitle')}</h4>
          <p className="mt-1 mb-0 break-words text-ink">
            {request.foreign_guest_warning}
          </p>
        </section>
      ) : null}

      {request.decision_comment !== null &&
      request.decision_comment !== undefined &&
      request.decision_comment !== '' ? (
        <p className="m-0 break-words text-steel">
          {t('guest.decisionComment', { comment: request.decision_comment })}
        </p>
      ) : null}

      {readsDocument ? <DocumentNumberReveal request={request} /> : null}

      {approve.isError ? <RequestRefusal error={approve.error} /> : null}
      {reject.isError ? <RequestRefusal error={reject.error} /> : null}

      {decides && open && !rejecting ? (
        <div className="grid gap-3">
          {marksRequired ? (
            <FormField
              id={`mark-${request.id}`}
              label={t('guestQueue.officerMarkLabel')}
              note={t('guestQueue.officerMarkNote')}
            >
              <Input
                id={`mark-${request.id}`}
                value={officerMark}
                maxLength={255}
                autoComplete="off"
                onChange={(event) => setOfficerMark(event.target.value)}
              />
            </FormField>
          ) : null}
          <div className="grid gap-2 sm:grid-cols-2">
            <Button
              type="button"
              size="lg"
              className="h-auto min-h-12 min-w-0 whitespace-normal"
              disabled={approve.isPending}
              onClick={() =>
                approve.mutate(
                  {
                    guestRequest: request.id,
                    data:
                      officerMark.trim() === ''
                        ? {}
                        : { responsible_officer_mark: officerMark.trim() },
                  },
                  { onSuccess: () => refresh() },
                )
              }
            >
              {approve.isPending ? `${t('common.saving')}…` : t('guestQueue.approve')}
            </Button>
            <Button
              type="button"
              size="lg"
              className="h-auto min-h-12 min-w-0 whitespace-normal"
              variant="outline"
              onClick={() => setRejecting(true)}
            >
              {t('guestQueue.reject')}
            </Button>
          </div>
        </div>
      ) : null}

      {decides && open && rejecting ? (
        <form
          className="grid gap-3 border-l-4 border-brick bg-brick-wash px-3 py-3"
          onSubmit={(event) => {
            event.preventDefault()
            reject.mutate(
              { guestRequest: request.id, data: { reason: reason.trim() } },
              {
                onSuccess: () => {
                  setRejecting(false)
                  setReason('')
                  refresh()
                },
              },
            )
          }}
        >
          <p className="m-0 text-ink">{t('guestQueue.reasonRequired')}</p>

          <FormField
            id={`reason-pick-${request.id}`}
            label={t('guestQueue.reasonDirectory')}
          >
            <select
              id={`reason-pick-${request.id}`}
              className={selectClassName}
              value=""
              onChange={(event) => {
                const key = event.target.value
                if (key !== '') {
                  setReason(t(`guestQueue.reasons.${key}`))
                }
              }}
            >
              <option value="">{t('guestQueue.reasonPick')}</option>
              {REJECTION_REASONS.map((key) => (
                <option key={key} value={key}>
                  {t(`guestQueue.reasons.${key}`)}
                </option>
              ))}
            </select>
          </FormField>

          <FormField
            id={`reason-${request.id}`}
            label={t('guestQueue.reasonLabel')}
            note={t('guestQueue.reasonNote')}
          >
            <textarea
              id={`reason-${request.id}`}
              className={`${selectClassName} h-24 py-2`}
              value={reason}
              minLength={3}
              maxLength={1000}
              required
              onChange={(event) => setReason(event.target.value)}
            />
          </FormField>

          <div className="grid gap-2 sm:grid-cols-2">
            <Button
              type="submit"
              size="lg"
              className="h-auto min-h-12 min-w-0 whitespace-normal"
              variant="destructive"
              disabled={reject.isPending || reason.trim().length < 3}
            >
              {reject.isPending ? `${t('common.saving')}…` : t('guestQueue.rejectConfirm')}
            </Button>
            <Button
              type="button"
              size="lg"
              className="h-auto min-h-12 min-w-0 whitespace-normal"
              variant="outline"
              onClick={() => setRejecting(false)}
            >
              {t('common.cancel')}
            </Button>
          </div>
        </form>
      ) : null}
    </article>
  )
}

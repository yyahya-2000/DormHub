import { useState } from 'react'
import { keepPreviousData } from '@tanstack/react-query'
import { Check, X } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  useApproveGuestRequest,
  useListGuestRequests,
  useRejectGuestRequest,
  type listGuestRequestsResponse,
} from '@/api/generated/dormitory'
import { GuestRequestStatus, type GuestRequest } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { decidesGuestRequests } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { BuildingTabs } from '@/components/building-tabs'
import { FormField, selectClassName } from '@/components/form-field'
import { GuestStatusTag } from '@/components/guest/guest-status-tag'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Pagination } from '@/components/ui/pagination'
import { Skeleton } from '@/components/ui/skeleton'
import { lastPageOf, usePagination } from '@/hooks/use-pagination'
import { useFormatters } from '@/lib/format'
import { awaitsDecision } from '@/lib/guest'
import { useGuestRefresh } from '@/lib/guest-cache'

/**
 * FR-17, the queue of a dormitory.
 *
 * The decision belongs to the duty officer, the warden and the manager of this
 * dormitory — revision 3 of the guest module, 16.09.2026. It sits on one
 * capability on the server, and here it decides only whether the two buttons
 * are drawn. The administrator reads the same list and sees it without them.
 *
 * A refusal cannot be silent: the route requires the reason, the service
 * requires the argument, and the form below will not submit an empty one.
 */

/**
 * The reasons given most often, as a directory over the free text field. They
 * are interface strings and not a server enumeration: the API stores whatever
 * sentence the decider sends. Picking one fills the field; it can then be
 * edited.
 */
const REJECTION_REASONS = [
  'quotaSpent',
  'outsideWindow',
  'residentAbsent',
  'quarantine',
] as const

const QUEUE_FILTERS: (GuestRequestStatus | 'all')[] = [
  GuestRequestStatus.pending_review,
  GuestRequestStatus.approved,
  GuestRequestStatus.in_progress,
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

  const [status, setStatus] = useState<GuestRequestStatus | 'all'>(
    GuestRequestStatus.pending_review,
  )
  const [visitDate, setVisitDate] = useState('')

  const paging = usePagination({ resetKey: `${buildingId}|${status}|${visitDate}` })
  const queue = useListGuestRequests<listGuestRequestsResponse, ApiError>(
    {
      building_id: buildingId,
      ...(status === 'all' ? {} : { status }),
      ...(visitDate === '' ? {} : { visit_date: visitDate }),
      ...paging.params,
    },
    {
      query: {
        enabled: Number.isInteger(buildingId),
        retry: false,
        placeholderData: keepPreviousData,
      },
    },
  )

  const body = queue.data?.status === 200 ? queue.data.data : null
  const requests = body?.data ?? null

  return (
    <div className="grid grid-cols-1 gap-8">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">{t('guestQueue.heading')}</h1>
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
          <FormField id="queue-date" label={t('guestQueue.filterDate')}>
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
          body?.meta?.total === undefined
            ? undefined
            : t('guestQueue.listCount', { count: body.meta.total })
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
                <QueueRow request={request} decides={decides} />
              </li>
            ))}
          </ul>
        ) : null}

        <Pagination
          page={paging.page}
          lastPage={lastPageOf(body?.meta)}
          onPageChange={paging.setPage}
          disabled={queue.isFetching}
        />
      </Panel>
    </div>
  )
}

function QueueRow({ request, decides }: { request: GuestRequest; decides: boolean }) {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const refresh = useGuestRefresh()

  const [rejecting, setRejecting] = useState(false)
  const [reason, setReason] = useState('')

  const approve = useApproveGuestRequest<ApiError>()
  const reject = useRejectGuestRequest<ApiError>()

  const open = awaitsDecision(request.status)
  const host = request.inviting_resident ?? null
  const visit = request.visit ?? null

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
        <dd className="m-0 break-words text-ink">
          {host?.id === null || host?.id === undefined ? (
            (request.student_name ?? t('common.empty'))
          ) : (
            <Link className="text-prussian underline" to={`/residents/${host.id}`}>
              {host.full_name ?? request.student_name}
            </Link>
          )}
          {host?.room === null || host?.room === undefined
            ? null
            : ` · ${t('guestQueue.fields.room', { room: host.room })}`}
        </dd>

        {request.decided_by_name !== null && request.decided_by_name !== undefined ? (
          <>
            <dt className="label-caps">{t('guestQueue.fields.decidedBy')}</dt>
            <dd className="m-0 break-words text-ink">
              {request.decided_by_name}
              {request.decided_at === null || request.decided_at === undefined
                ? null
                : ` · ${formatters.dateTime(request.decided_at)}`}
            </dd>
          </>
        ) : null}

        {visit?.checked_out_by_name === null ||
        visit?.checked_out_by_name === undefined ? null : (
          <>
            <dt className="label-caps">{t('guestQueue.fields.checkedOutBy')}</dt>
            <dd className="m-0 break-words text-ink">
              {visit.checked_out_by_name}
              {visit.checked_out_at === null || visit.checked_out_at === undefined
                ? null
                : ` · ${formatters.dateTime(visit.checked_out_at)}`}
            </dd>
          </>
        )}
      </dl>

      {request.decision_comment !== null &&
      request.decision_comment !== undefined &&
      request.decision_comment !== '' ? (
        <p className="m-0 break-words text-steel">
          {t('guest.decisionComment', { comment: request.decision_comment })}
        </p>
      ) : null}

      {approve.isError ? <RequestRefusal error={approve.error} /> : null}
      {reject.isError ? <RequestRefusal error={reject.error} /> : null}

      {decides && open && !rejecting ? (
        <div className="grid gap-2 sm:grid-cols-2">
          <Button
            type="button"
            size="lg"
            className="h-auto min-h-12 min-w-0 whitespace-normal"
            disabled={approve.isPending}
            onClick={() =>
              approve.mutate(
                { guestRequest: request.id, data: {} },
                { onSuccess: () => refresh() },
              )
            }
          >
            <Check aria-hidden="true" className="size-5" />
            {approve.isPending ? `${t('common.saving')}…` : t('guestQueue.approve')}
          </Button>
          <Button
            type="button"
            size="lg"
            className="h-auto min-h-12 min-w-0 whitespace-normal"
            variant="outline"
            onClick={() => setRejecting(true)}
          >
            <X aria-hidden="true" className="size-5" />
            {t('guestQueue.reject')}
          </Button>
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

          <FormField id={`reason-${request.id}`} label={t('guestQueue.reasonLabel')} required>
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

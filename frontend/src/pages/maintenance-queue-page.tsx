import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { keepPreviousData } from '@tanstack/react-query'

import {
  useShowMaintenanceQueue,
  type showMaintenanceQueueResponse,
} from '@/api/generated/dormitory'
import type { MaintenanceQueueRow } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { BuildingTabs } from '@/components/building-tabs'
import {
  MaintenanceStatusTag,
  MaintenanceUrgencyTag,
  OverdueTag,
} from '@/components/maintenance/maintenance-tags'
import { ScopeTabs, type MaintenanceScope } from '@/components/maintenance/scope-tabs'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Pagination } from '@/components/ui/pagination'
import { Skeleton } from '@/components/ui/skeleton'
import { lastPageOf, usePagination } from '@/hooks/use-pagination'
import { useFormatters } from '@/lib/format'
import { categoryCodeOf, urgencyCodeOf } from '@/lib/maintenance'

/**
 * FR-40, the requests of one dormitory.
 *
 * **A sub-resource of the building, and that is the whole of the horizontal
 * boundary.** The dormitory is in the path, the policy decides on the object,
 * and every query begins from it — there is no code path in which a request of
 * another dormitory is in the result set to be filtered out afterwards.
 *
 * **The order is the one a queue is worked in**, and it is the server's: most
 * pressing urgency first, oldest first inside one urgency. Nothing here
 * re-sorts the rows.
 */
export function MaintenanceQueuePage() {
  const { t } = useTranslation()
  const params = useParams<{ buildingId: string }>()
  const buildingId = Number(params.buildingId)

  const [scope, setScope] = useState<MaintenanceScope>('open')
  const paging = usePagination({ resetKey: scope })

  const queue = useShowMaintenanceQueue<showMaintenanceQueueResponse, ApiError>(
    buildingId,
    { scope, ...paging.params },
    {
      query: {
        enabled: Number.isInteger(buildingId),
        retry: false,
        placeholderData: keepPreviousData,
      },
    },
  )

  const body = queue.data?.status === 200 ? queue.data.data : null
  const rows = body?.data ?? null

  return (
    <div className="grid grid-cols-1 gap-8">
      <h1 className="text-2xl font-semibold text-ink">{t('maintenanceQueue.heading')}</h1>

      <BuildingTabs buildingId={buildingId} />

      <ScopeTabs scope={scope} onChange={setScope} />

      <Panel caption={t('maintenanceQueue.listHeading')}>
        {queue.isError ? (
          <div className="px-4 py-4">
            <RequestRefusal error={queue.error} vocabulary="maintenance" />
          </div>
        ) : null}

        {queue.isPending && !queue.isError ? (
          <div className="grid gap-2 px-4 py-4" aria-hidden="true">
            <Skeleton className="h-28 w-full" />
            <Skeleton className="h-28 w-full" />
          </div>
        ) : null}

        {rows !== null && rows.length === 0 ? (
          <p className="px-4 py-6 text-steel">{t('maintenanceQueue.empty')}</p>
        ) : null}

        {rows !== null && rows.length > 0 ? (
          <ul className="m-0 list-none p-0">
            {rows.map((row) => (
              <li key={row.id} className="border-b border-rule/70 last:border-b-0">
                <QueueRow row={row} />
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

/**
 * One row of the queue. A block and not a table cell: the queue is worked from
 * a telephone in a corridor, and a table of six columns at 360 px is a
 * horizontal scrollbar with the urgency off the right edge.
 *
 * The category and the urgency arrive here as the server's own English words
 * rather than as codes — the queue row is a row of scalars — so they are read
 * back into codes and translated, and the server's word stands where the
 * reading fails.
 */
function QueueRow({ row }: { row: MaintenanceQueueRow }) {
  const { t } = useTranslation()
  const formatters = useFormatters()

  const category = categoryCodeOf(row.category)
  const urgency = urgencyCodeOf(row.urgency)

  return (
    <article className="grid gap-2 px-4 py-4">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h3 className="m-0 min-w-0 text-lg font-semibold break-words text-ink">
          <Link className="text-prussian underline" to={`/maintenance/${row.id}`}>
            {t('maintenanceQueue.rowTitle', {
              number: row.id,
              place: row.place ?? t('common.empty'),
            })}
          </Link>
        </h3>
        <div className="flex flex-wrap gap-2">
          {row.overdue === true ? <OverdueTag /> : null}
          <MaintenanceStatusTag status={row.status} />
        </div>
      </div>

      <dl className="m-0 grid gap-x-4 gap-y-1 sm:grid-cols-[minmax(0,13rem)_minmax(0,1fr)]">
        <dt className="label-caps">{t('maintenance.fields.category')}</dt>
        <dd className="m-0 flex flex-wrap items-baseline gap-2 break-words text-ink">
          <span>
            {category === null
              ? (row.category ?? t('common.empty'))
              : t(`maintenanceCategory.${category}`)}
          </span>
          {urgency === null ? (
            <span>{row.urgency ?? t('common.empty')}</span>
          ) : (
            <MaintenanceUrgencyTag urgency={urgency} />
          )}
        </dd>
        <dt className="label-caps">{t('maintenance.fields.filed')}</dt>
        <dd className="m-0 text-ink">{formatters.dateTime(row.submitted_at)}</dd>
        <dt className="label-caps">{t('maintenance.fields.targetDate')}</dt>
        <dd className="m-0 text-ink">
          {row.target_date === null || row.target_date === undefined
            ? t('common.empty')
            : formatters.date(row.target_date)}
        </dd>
        <dt className="label-caps">{t('maintenance.fields.reporter')}</dt>
        <dd className="m-0 break-words text-ink">{row.reporter ?? t('common.empty')}</dd>
        <dt className="label-caps">{t('maintenance.fields.assignee')}</dt>
        <dd className="m-0 break-words text-ink">
          {row.assignee ?? t('maintenance.unassigned')}
        </dd>
      </dl>
    </article>
  )
}

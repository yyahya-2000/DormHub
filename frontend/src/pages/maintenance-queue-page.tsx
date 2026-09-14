import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  showMaintenanceQueue,
  useShowMaintenanceQueue,
  type showMaintenanceQueueResponse,
} from '@/api/generated/dormitory'
import type {
  MaintenanceCategory,
  MaintenanceQueueRow,
  MaintenanceRequestStatus,
} from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { triagesMaintenance } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { BuildingTabs } from '@/components/building-tabs'
import { FormField, selectClassName } from '@/components/form-field'
import {
  MaintenanceStatusTag,
  MaintenanceUrgencyTag,
  OverdueTag,
} from '@/components/maintenance/maintenance-tags'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { useFormatters, todayIso } from '@/lib/format'
import {
  MAINTENANCE_CATEGORIES,
  MAINTENANCE_STATUSES,
  categoryCodeOf,
  urgencyCodeOf,
} from '@/lib/maintenance'

/**
 * FR-40, the queue of one dormitory: what is open, how old it is, and what is
 * late.
 *
 * **A sub-resource of the building, and that is the whole of the horizontal
 * boundary.** The dormitory is in the path, the policy decides on the object,
 * and every query begins from it — there is no code path in which a request of
 * another dormitory is in the result set to be filtered out afterwards. A warden
 * of block 1 asking for block 2 is 403 and the refusal is in the audit log.
 *
 * **The screen opens on the open requests and says so.** With no status named
 * the route answers the open ones, because FR-40's fourth criterion is «all open
 * requests» and a screen opening on three years of closed ones would be a
 * different screen. Naming a status — `closed` included — says otherwise, and
 * the control below therefore offers «open» as a value of its own rather than
 * as an empty selection.
 *
 * **The order is the one a queue is worked in**, and it is the server's: most
 * pressing urgency first, oldest first inside one urgency. Nothing here re-sorts
 * the rows. Sorting by age alone would bury an emergency reported this morning
 * under a wobbly chair from March.
 *
 * **The age is counted from submission and from nothing else.** Restarting the
 * clock on acceptance would measure the warden rather than the wait.
 *
 * **Late is configuration and not code** (FR-40, second criterion): a request is
 * overdue when its planned date has passed, or when it is older than the
 * configured threshold, which catches the request nobody has triaged and has no
 * date to be late against. The threshold arrives in `meta.overdue_after_days`
 * and is named on the screen, because «late» without «after how long» invites
 * the reader to guess a rule.
 *
 * **The export is this screen with one parameter changed**, not a route of its
 * own: two routes would be two queries with the same chance of disagreeing about
 * what «open» means. Either format is an audited action — an export discloses
 * who reported what and when.
 */

/** U+FEFF, written as a code point so that the source carries no invisible character. */
const BYTE_ORDER_MARK = String.fromCodePoint(0xfe_ff)

/** «Everything still open», which the route means by naming no status at all. */
const OPEN = 'open'

export function MaintenanceQueuePage() {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const { session } = useSession()
  const params = useParams<{ buildingId: string }>()
  const buildingId = Number(params.buildingId)

  const user = session.status === 'authenticated' ? session.user : null
  const triages = user !== null && triagesMaintenance(user, buildingId)

  const [status, setStatus] = useState<MaintenanceRequestStatus | typeof OPEN>(OPEN)
  const [category, setCategory] = useState<MaintenanceCategory | 'all'>('all')
  const [minAge, setMinAge] = useState('')
  const [overdueOnly, setOverdueOnly] = useState(false)
  const [from, setFrom] = useState('')
  const [until, setUntil] = useState('')
  const [page, setPage] = useState(1)
  const [downloading, setDownloading] = useState(false)
  const [downloadError, setDownloadError] = useState<unknown>(null)

  const query = {
    ...(status === OPEN ? {} : { status }),
    ...(category === 'all' ? {} : { category }),
    ...(minAge === '' ? {} : { min_age_days: Number(minAge) }),
    ...(overdueOnly ? { overdue: true } : {}),
    ...(from === '' ? {} : { from }),
    ...(until === '' ? {} : { until }),
  }

  const queue = useShowMaintenanceQueue<showMaintenanceQueueResponse, ApiError>(
    buildingId,
    { ...query, format: 'json' as const, page },
    { query: { enabled: Number.isInteger(buildingId), retry: false } },
  )

  const payload =
    queue.data?.status === 200 && typeof queue.data.data !== 'string'
      ? queue.data.data
      : null
  const rows = payload?.data ?? null
  const meta = payload?.meta ?? null
  const perPage = meta?.per_page ?? 50
  const total = meta?.total ?? 0
  const pages = Math.max(1, Math.ceil(total / Math.max(1, perPage)))

  function narrow(change: () => void) {
    change()
    setPage(1)
  }

  /**
   * The file. Fetched as CSV through the same audited route with the filters as
   * they stand, so the file and the screen can never disagree about what was
   * asked for.
   */
  async function download() {
    setDownloading(true)
    setDownloadError(null)
    try {
      const response = await showMaintenanceQueue(buildingId, {
        ...query,
        format: 'csv',
      })
      if (response.status !== 200 || typeof response.data !== 'string') {
        setDownloadError(new Error('csv'))
        return
      }
      // A byte-order mark ahead of the rows. Without it the spreadsheet most
      // wardens will open this in reads a Cyrillic name as mojibake, and an
      // export nobody can read is not an export.
      const blob = new Blob([BYTE_ORDER_MARK, response.data], {
        type: 'text/csv;charset=utf-8',
      })
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `maintenance-queue-${buildingId}-${from === '' ? 'all' : from}-${
        until === '' ? 'all' : until
      }.csv`
      document.body.append(link)
      link.click()
      link.remove()
      URL.revokeObjectURL(url)
    } catch (error) {
      setDownloadError(error)
    } finally {
      setDownloading(false)
    }
  }

  return (
    <div className="grid grid-cols-1 gap-8">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">
          {t('maintenanceQueue.heading')}
        </h1>
        <p className="mt-1 text-steel">
          {triages
            ? t('maintenanceQueue.leadTriage')
            : t('maintenanceQueue.leadReadOnly')}
        </p>
      </div>

      <BuildingTabs buildingId={buildingId} />

      <Panel caption={t('maintenanceQueue.filterHeading')}>
        <div className="grid gap-4 px-4 py-4 sm:grid-cols-2">
          <FormField id="queue-status" label={t('maintenanceQueue.filterStatus')}>
            <select
              id="queue-status"
              className={selectClassName}
              value={status}
              onChange={(event) =>
                narrow(() =>
                  setStatus(
                    event.target.value as MaintenanceRequestStatus | typeof OPEN,
                  ),
                )
              }
            >
              <option value={OPEN}>{t('maintenanceQueue.filterOpen')}</option>
              {MAINTENANCE_STATUSES.map((value) => (
                <option key={value} value={value}>
                  {t(`maintenanceStatus.${value}`)}
                </option>
              ))}
            </select>
          </FormField>

          <FormField id="queue-category" label={t('maintenanceQueue.filterCategory')}>
            <select
              id="queue-category"
              className={selectClassName}
              value={category}
              onChange={(event) =>
                narrow(() =>
                  setCategory(event.target.value as MaintenanceCategory | 'all'),
                )
              }
            >
              <option value="all">{t('maintenanceQueue.filterAll')}</option>
              {MAINTENANCE_CATEGORIES.map((value) => (
                <option key={value} value={value}>
                  {t(`maintenanceCategory.${value}`)}
                </option>
              ))}
            </select>
          </FormField>

          <FormField
            id="queue-age"
            label={t('maintenanceQueue.filterAge')}
            note={t('maintenanceQueue.filterAgeNote')}
          >
            <Input
              id="queue-age"
              type="number"
              inputMode="numeric"
              min={0}
              max={3650}
              value={minAge}
              onChange={(event) => narrow(() => setMinAge(event.target.value))}
            />
          </FormField>

          <div className="grid min-w-0 gap-1">
            <label className="flex items-start gap-3" htmlFor="queue-overdue">
              <input
                id="queue-overdue"
                type="checkbox"
                className="mt-1 size-5 shrink-0 border border-rule accent-prussian"
                checked={overdueOnly}
                onChange={(event) =>
                  narrow(() => setOverdueOnly(event.target.checked))
                }
              />
              <span className="min-w-0 text-ink">
                {t('maintenanceQueue.filterOverdue')}
              </span>
            </label>
            {meta?.overdue_after_days === undefined ? null : (
              <p className="m-0 text-steel">
                {t('maintenance.overdueThreshold', { count: meta.overdue_after_days })}
              </p>
            )}
          </div>

          <FormField
            id="queue-from"
            label={t('maintenanceQueue.filterFrom')}
            note={t('maintenanceQueue.filterPeriodNote')}
          >
            <Input
              id="queue-from"
              type="date"
              value={from}
              max={todayIso()}
              onChange={(event) => narrow(() => setFrom(event.target.value))}
            />
          </FormField>

          <FormField id="queue-until" label={t('maintenanceQueue.filterUntil')}>
            <Input
              id="queue-until"
              type="date"
              value={until}
              max={todayIso()}
              onChange={(event) => narrow(() => setUntil(event.target.value))}
            />
          </FormField>
        </div>

        <div className="grid gap-3 border-t border-rule px-4 py-4">
          {downloadError !== null ? <RequestRefusal error={downloadError} /> : null}
          <div>
            <Button
              type="button"
              variant="outline"
              size="lg"
              className="h-auto min-h-12 min-w-0 whitespace-normal"
              disabled={downloading || rows === null}
              onClick={() => void download()}
            >
              {downloading ? `${t('common.saving')}…` : t('maintenanceQueue.export')}
            </Button>
          </div>
          <p className="m-0 text-steel">{t('maintenanceQueue.exportNote')}</p>
        </div>
      </Panel>

      <Panel
        caption={t('maintenanceQueue.listHeading')}
        aside={
          meta === null
            ? undefined
            : t('maintenanceQueue.listCount', { count: meta.total ?? 0 })
        }
      >
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
                <QueueRow row={row} threshold={meta?.overdue_after_days} />
              </li>
            ))}
          </ul>
        ) : null}

        {pages > 1 ? (
          <div className="flex flex-wrap items-center justify-between gap-3 border-t border-rule px-4 py-3">
            <span className="text-steel">
              {t('notifications.page', {
                current: formatters.count(page),
                total: formatters.count(pages),
              })}
            </span>
            <div className="flex gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={page <= 1 || queue.isFetching}
                onClick={() => setPage((current) => Math.max(1, current - 1))}
              >
                {t('notifications.previous')}
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={page >= pages || queue.isFetching}
                onClick={() => setPage((current) => current + 1)}
              >
                {t('notifications.next')}
              </Button>
            </div>
          </div>
        ) : null}
      </Panel>
    </div>
  )
}

/**
 * One row of the queue. A block and not a table cell: the queue is worked from a
 * telephone in a corridor, the row carries eight facts, and a table of eight
 * columns at 360 px is a horizontal scrollbar with the urgency off the right
 * edge.
 *
 * The category and the urgency arrive here as the server's own English words
 * rather than as codes — the queue row is a row of scalars for the CSV's sake —
 * so they are read back into codes and translated, and the server's word stands
 * where the reading fails. The urgency keeps its tag, because the colour of the
 * emergency is what the queue is scanned for.
 */
function QueueRow({
  row,
  threshold,
}: {
  row: MaintenanceQueueRow
  threshold?: number
}) {
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
          {row.overdue === true ? <OverdueTag afterDays={threshold} /> : null}
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
        <dd className="m-0 text-ink">
          {formatters.dateTime(row.submitted_at)}
          {row.age_days === undefined
            ? null
            : ` · ${t('maintenance.ageDays', { count: row.age_days })}`}
        </dd>
        <dt className="label-caps">{t('maintenance.fields.targetDate')}</dt>
        <dd className="m-0 text-ink">
          {row.target_date === null || row.target_date === undefined
            ? t('maintenance.noTargetYet')
            : formatters.date(row.target_date)}
        </dd>
        <dt className="label-caps">{t('maintenance.fields.reporter')}</dt>
        <dd className="m-0 break-words text-ink">{row.reporter ?? t('common.empty')}</dd>
        <dt className="label-caps">{t('maintenance.fields.assignee')}</dt>
        <dd className="m-0 break-words text-ink">
          {row.assignee ?? t('maintenance.unassigned')}
        </dd>
        {row.reopen_count !== undefined && row.reopen_count > 0 ? (
          <>
            <dt className="label-caps">{t('maintenance.fields.reopened')}</dt>
            <dd className="m-0 text-ink">
              {t('maintenance.reopenCount', { count: row.reopen_count })}
            </dd>
          </>
        ) : null}
      </dl>
    </article>
  )
}

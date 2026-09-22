import { useMemo, useState } from 'react'
import { keepPreviousData } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'

import { useListAuditLogs, type listAuditLogsResponse } from '@/api/generated/dormitory'
import { AuditAction, AuditResult, type AuditEntry } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { FormField, selectClassName } from '@/components/form-field'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Pagination } from '@/components/ui/pagination'
import { Skeleton } from '@/components/ui/skeleton'
import { lastPageOf, usePagination } from '@/hooks/use-pagination'
import { useFormatters } from '@/lib/format'
import { cn } from '@/lib/utils'

const RESULT_TONE: Record<string, string> = {
  [AuditResult.success]: 'border-rule bg-paper text-steel',
  [AuditResult.failure]: 'border-brass/45 bg-brass-wash text-brass',
  [AuditResult.denied]: 'border-brick/40 bg-brick-wash text-brick',
}

/**
 * FR-33, the administrator's end of it: the log read from a screen.
 *
 * Six of the record's seven fields are drawn. `payload` is the one left out —
 * whatever the service that wrote the row thought worth keeping, and printing
 * it would turn a list somebody is scanning for one event into a wall of JSON.
 *
 * The filter is the action, which is also the only one the route takes:
 * «show me the refusals» and «show me who read a card» are the questions this
 * screen is opened with.
 */
export function AuditLogPage() {
  const { t } = useTranslation()
  const [action, setAction] = useState<AuditAction | ''>('')

  const paging = usePagination({ resetKey: action })
  const log = useListAuditLogs<listAuditLogsResponse, ApiError>(
    { page: paging.page, ...(action === '' ? {} : { action }) },
    { query: { retry: false, placeholderData: keepPreviousData } },
  )

  const body = log.data?.status === 200 ? log.data.data : null
  const rows = body?.data ?? null
  const meta = body?.meta

  // Sorted by what the reader sees rather than by the wire value, which would
  // group the list by an English prefix on a Russian screen.
  const choices = useMemo(() => {
    const collator = new Intl.Collator()
    return Object.values(AuditAction)
      .map((value) => ({ value, label: labelOf(value, t) }))
      .sort((a, b) => collator.compare(a.label, b.label))
  }, [t])

  return (
    <div className="grid grid-cols-1 gap-8">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">{t('audit.heading')}</h1>
        <p className="mt-1 text-steel">{t('audit.lead')}</p>
      </div>

      <Panel caption={t('audit.filter')}>
        <div className="px-4 py-4">
          <FormField id="audit-action" label={t('audit.columns.action')}>
            <select
              id="audit-action"
              className={selectClassName}
              value={action}
              onChange={(event) => setAction(event.target.value as AuditAction | '')}
            >
              <option value="">{t('audit.filterAll')}</option>
              {choices.map((choice) => (
                <option key={choice.value} value={choice.value}>
                  {choice.label}
                </option>
              ))}
            </select>
          </FormField>
        </div>
      </Panel>

      <Panel
        caption={t('audit.heading')}
        aside={
          meta?.total === undefined ? undefined : t('audit.total', { count: meta.total })
        }
      >
        {log.isError ? (
          <div className="px-4 py-4">
            <RequestRefusal error={log.error} />
          </div>
        ) : null}

        {log.isPending && !log.isError ? (
          <div className="grid gap-2 px-4 py-4" aria-hidden="true">
            <Skeleton className="h-20 w-full" />
            <Skeleton className="h-20 w-full" />
          </div>
        ) : null}

        {rows !== null && rows.length === 0 ? (
          <p className="px-4 py-6 text-steel">{t('audit.empty')}</p>
        ) : null}

        {rows !== null && rows.length > 0 ? (
          <ul className="m-0 list-none p-0">
            {rows.map((entry) => (
              <li key={entry.id} className="border-b border-rule/70 last:border-b-0">
                <LogRow entry={entry} />
              </li>
            ))}
          </ul>
        ) : null}

        <Pagination
          page={paging.page}
          lastPage={lastPageOf(meta)}
          onPageChange={paging.setPage}
          disabled={log.isFetching}
        />
      </Panel>
    </div>
  )
}

/**
 * One record, as a block rather than as a table row: six columns do not survive
 * 360 px, and the visit register is drawn this way for the same reason (NFR-11).
 */
function LogRow({ entry }: { entry: AuditEntry }) {
  const { t } = useTranslation()
  const formatters = useFormatters()

  const subject =
    entry.subject.type === null || entry.subject.type === undefined
      ? null
      : t(`audit.subjects.${entry.subject.type}`, { defaultValue: entry.subject.type })

  return (
    <article className="grid gap-2 px-4 py-4">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h2 className="m-0 min-w-0 font-semibold break-words text-ink">
          {labelOf(entry.action, t)}
        </h2>
        <span
          className={cn(
            'inline-block border px-2 py-0.5 font-medium',
            RESULT_TONE[entry.result] ?? 'border-rule bg-paper text-steel',
          )}
        >
          {t(`audit.results.${entry.result}`, { defaultValue: entry.result })}
        </span>
      </div>

      <dl className="m-0 grid gap-x-4 gap-y-1 sm:grid-cols-[minmax(0,10rem)_minmax(0,1fr)]">
        <dt className="label-caps">{t('audit.columns.who')}</dt>
        <dd className="m-0 break-words text-ink">
          {entry.user.full_name ?? t('audit.anonymous')}
        </dd>

        {subject !== null ? (
          <>
            <dt className="label-caps">{t('audit.columns.subject')}</dt>
            <dd className="m-0 break-words text-ink">
              {t('audit.subjectOf', { type: subject, id: entry.subject.id ?? '—' })}
            </dd>
          </>
        ) : null}

        <dt className="label-caps">{t('audit.columns.time')}</dt>
        <dd className="m-0 text-ink">{formatters.dateTime(entry.created_at)}</dd>

        <dt className="label-caps">{t('audit.columns.address')}</dt>
        <dd className="m-0 text-ink tabular-nums">
          {entry.ip_address ?? t('common.empty')}
        </dd>
      </dl>
    </article>
  )
}

/** The wire value is the fallback: an action this build predates still reads. */
function labelOf(
  action: AuditAction,
  t: (key: string, options?: Record<string, unknown>) => string,
): string {
  return t(`audit.actions.${action}`, { defaultValue: action })
}

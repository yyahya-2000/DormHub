import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { useListAuditLogs, type listAuditLogsResponse } from '@/api/generated/dormitory'
import { AuditAction, type AuditEntry, type AuditResult } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { useFormatters } from '@/lib/format'
import { cn } from '@/lib/utils'

const RESULT_TONE: Record<AuditResult, string> = {
  success: 'text-prussian',
  failure: 'text-brick',
  denied: 'text-brass',
}

/**
 * FR-33, and the reason the audit link is drawn for the administrator alone.
 * The page is reachable by anyone who types the address; the server answers
 * 403 to everyone else, and that answer is what appears here.
 *
 * Every timestamp goes through `Intl`. This table is the ancestor of the
 * visiting journal of §2.1.2 — arrival and departure of a guest — where a
 * misread time is a dispute rather than a cosmetic defect.
 */
export function AuditLogPage() {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const [action, setAction] = useState<string>('')
  const [page, setPage] = useState(1)

  const logs = useListAuditLogs<listAuditLogsResponse, ApiError>(
    {
      ...(action === '' ? {} : { action: action as AuditAction }),
      page,
    },
    { query: { retry: false } },
  )

  const payload = logs.data?.status === 200 ? logs.data.data : null
  const entries = payload?.data ?? []
  const meta = payload?.meta

  return (
    // `grid-cols-1` is a single track of `minmax(0, 1fr)`, and it is load
    // bearing: an implicit track takes its width from the widest item, so the
    // six columns of the journal below used to set the width of the heading,
    // of the filter and of the page itself. NFR-11 asks the interface to work
    // at 360 px, and the only thing that may scroll sideways there is the
    // table inside its own container.
    <div className="grid grid-cols-1 gap-6">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">{t('audit.heading')}</h1>
        <p className="mt-1 text-steel">{t('audit.lead')}</p>
      </div>

      {logs.isError ? <RequestRefusal error={logs.error} /> : null}

      {logs.isError ? null : (
      <div className="flex min-w-0 flex-wrap items-end gap-3">
        {/* The widest option of the list — a whole sentence in Russian — is
            what a select is sized by, so the field is told it may shrink and
            let the browser clip the name of the event instead of the page. */}
        <label className="grid min-w-0 gap-1">
          <span className="label-caps">{t('audit.filter')}</span>
          <select
            className="h-11 w-full border border-rule bg-paper-raised px-3 text-base text-ink"
            value={action}
            onChange={(event) => {
              setAction(event.target.value)
              setPage(1)
            }}
          >
            <option value="">{t('audit.filterAll')}</option>
            {Object.values(AuditAction).map((value) => (
              <option key={value} value={value}>
                {t(`audit.actions.${value}`)}
              </option>
            ))}
          </select>
        </label>
      </div>
      )}

      {logs.isError ? null : (
      <Panel
        className="min-w-0"
        caption={t('audit.heading')}
        aside={
          meta !== undefined
            ? t('audit.page', {
                current: formatters.count(meta.current_page),
                total: formatters.count(meta.last_page),
              })
            : undefined
        }
      >
        {entries.length === 0 && !logs.isPending ? (
          <p className="px-4 py-6 text-steel">{t('audit.empty')}</p>
        ) : (
          <AuditRecords entries={entries} />
        )}

        {meta !== undefined ? (
          <div className="flex flex-wrap items-center justify-between gap-3 border-t border-rule px-4 py-3">
            <span className="text-steel">
              {t('audit.page', {
                current: formatters.count(meta.current_page),
                total: formatters.count(meta.last_page),
              })}
            </span>
            <div className="flex gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={(meta.current_page ?? 1) <= 1 || logs.isFetching}
                onClick={() => setPage((current) => Math.max(1, current - 1))}
              >
                {t('audit.previous')}
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={
                  (meta.current_page ?? 1) >= (meta.last_page ?? 1) || logs.isFetching
                }
                onClick={() => setPage((current) => current + 1)}
              >
                {t('audit.next')}
              </Button>
            </div>
          </div>
        ) : null}
      </Panel>
      )}
    </div>
  )
}

/**
 * Two renderings of the same entries: the ruled table from 640 px up, a stack
 * of records below it — the choice the building roll already makes. Six
 * columns at 360 px are a horizontal scrollbar rather than a layout, and three
 * of them hold text that must not be broken across lines: a timestamp, the
 * name of an object and an address are read as single tokens or misread.
 *
 * Between 640 px and the natural width of the table the scrolling belongs to
 * the container in `ui/table.tsx` and to nothing above it.
 */
function AuditRecords({ entries }: { entries: AuditEntry[] }) {
  const { t } = useTranslation()
  const formatters = useFormatters()

  function subjectOf(entry: AuditEntry): string {
    return entry.subject.type === null || entry.subject.type === undefined
      ? t('common.empty')
      : `${entry.subject.type} ${formatters.count(entry.subject.id)}`
  }

  return (
    <>
      <div className="hidden sm:block">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="label-caps">{t('audit.columns.time')}</TableHead>
              <TableHead className="label-caps">{t('audit.columns.action')}</TableHead>
              <TableHead className="label-caps">{t('audit.columns.who')}</TableHead>
              <TableHead className="label-caps">{t('audit.columns.subject')}</TableHead>
              <TableHead className="label-caps">{t('audit.columns.result')}</TableHead>
              <TableHead className="label-caps">{t('audit.columns.address')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {entries.map((entry) => (
              <TableRow key={entry.id}>
                <TableCell className="whitespace-nowrap">
                  {formatters.dateTime(entry.created_at)}
                </TableCell>
                <TableCell>{t(`audit.actions.${entry.action}`)}</TableCell>
                <TableCell>{entry.user.full_name ?? t('audit.anonymous')}</TableCell>
                <TableCell className="whitespace-nowrap">{subjectOf(entry)}</TableCell>
                <TableCell className={cn('font-medium', RESULT_TONE[entry.result])}>
                  {t(`audit.results.${entry.result}`)}
                </TableCell>
                <TableCell className="whitespace-nowrap text-steel">
                  {entry.ip_address ?? t('common.empty')}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>

      <ul className="sm:hidden">
        {entries.map((entry) => (
          <li
            key={entry.id}
            className="border-b border-rule/70 px-4 py-4 last:border-b-0"
          >
            <p className="font-medium text-ink">{t(`audit.actions.${entry.action}`)}</p>
            <p className="text-steel">{formatters.dateTime(entry.created_at)}</p>
            <dl className="mt-2 grid grid-cols-[minmax(0,auto)_minmax(0,1fr)] gap-x-3 gap-y-1">
              <dt className="label-caps pt-0.5">{t('audit.columns.who')}</dt>
              <dd className="m-0 break-words">
                {entry.user.full_name ?? t('audit.anonymous')}
              </dd>
              <dt className="label-caps pt-0.5">{t('audit.columns.subject')}</dt>
              <dd className="m-0 break-words">{subjectOf(entry)}</dd>
              <dt className="label-caps pt-0.5">{t('audit.columns.result')}</dt>
              <dd className={cn('m-0 font-medium', RESULT_TONE[entry.result])}>
                {t(`audit.results.${entry.result}`)}
              </dd>
              <dt className="label-caps pt-0.5">{t('audit.columns.address')}</dt>
              <dd className="m-0 break-words text-steel">
                {entry.ip_address ?? t('common.empty')}
              </dd>
            </dl>
          </li>
        ))}
      </ul>
    </>
  )
}

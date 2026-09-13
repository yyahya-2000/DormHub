import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { useListAuditLogs, type listAuditLogsResponse } from '@/api/generated/dormitory'
import { AuditAction, type AuditResult } from '@/api/generated/model'
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
    <div className="grid gap-6">
      <div>
        <h1 className="text-2xl font-semibold text-ink">{t('audit.heading')}</h1>
        <p className="mt-1 text-steel">{t('audit.lead')}</p>
      </div>

      {logs.isError ? <RequestRefusal error={logs.error} /> : null}

      {logs.isError ? null : (
      <div className="flex flex-wrap items-end gap-3">
        <label className="grid gap-1">
          <span className="label-caps">{t('audit.filter')}</span>
          <select
            className="h-11 border border-rule bg-paper-raised px-3 text-base text-ink"
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
                  <TableCell className="whitespace-nowrap">
                    {entry.subject.type === null || entry.subject.type === undefined
                      ? t('common.empty')
                      : `${entry.subject.type} ${formatters.count(entry.subject.id)}`}
                  </TableCell>
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

import { useState, type FormEvent } from 'react'
import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  exportVisitRegister,
  useCorrectGuestVisit,
  useExportVisitRegister,
  type exportVisitRegisterResponse,
} from '@/api/generated/dormitory'
import type { VisitRegisterEntry } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { readsVisitRegister } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { BuildingTabs } from '@/components/building-tabs'
import { FormField, selectClassName } from '@/components/form-field'
import { VisitStatusTag } from '@/components/guest/guest-status-tag'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { useFormatters, todayIso } from '@/lib/format'
import { useGuestRefresh } from '@/lib/guest-cache'

/**
 * FR-21 and §3.9.6: the visitor register over a period, and its export.
 *
 * **What this replaces.** Clause 2.1.2 of the HSE rules of internal order makes
 * the security service keep a journal by hand — guest, time of arrival, time of
 * departure, premises, whom they are visiting, details of the document — and
 * the seven columns below are that journal plus the operator who recorded each
 * entry, which a paper page carried by existing and an electronic one has to
 * say in words. The order of the columns is the server's, taken from
 * `meta.columns`, so the file and the screen list them the way the clause does.
 *
 * **The export carries no claim.** Constraint C-04 makes it the substitute for
 * the access-control integration this iteration does not build, and §3.9.6 is
 * explicit that the format is presentational and answers no particular
 * reporting obligation. The screen repeats that rather than leaving a warden to
 * assume otherwise.
 *
 * **The document number is exported masked**, which departs from the paper
 * journal on purpose: an export is a file that leaves the system and is copied.
 * The number in full is available one request at a time, on an audited route,
 * and not from here.
 *
 * **Nothing on this page edits a row.** An entry is immutable: `checked_out_at`
 * is written once, guarded by a database trigger and by the service, and a
 * mistake is put right by a correcting entry that travels beside the row it
 * corrects. That is why the form at the foot of a row asks for a sentence and
 * not for a field and a new value — a correction that could be applied would be
 * an edit with extra steps.
 */

/** U+FEFF, written as a code point so that the source carries no invisible character. */
const BYTE_ORDER_MARK = String.fromCodePoint(0xfe_ff)

function firstOfMonth(): string {
  const now = new Date()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  return `${now.getFullYear()}-${month}-01`
}

export function VisitRegisterPage() {
  const { t } = useTranslation()
  const { session } = useSession()
  const formatters = useFormatters()
  const params = useParams<{ buildingId: string }>()
  const buildingId = Number(params.buildingId)

  const user = session.status === 'authenticated' ? session.user : null
  const reads = user !== null && readsVisitRegister(user, buildingId)

  const [from, setFrom] = useState(firstOfMonth)
  const [until, setUntil] = useState(todayIso)
  const [page, setPage] = useState(1)
  const [downloading, setDownloading] = useState(false)
  const [downloadError, setDownloadError] = useState<unknown>(null)

  const register = useExportVisitRegister<exportVisitRegisterResponse, ApiError>(
    buildingId,
    { from, until, format: 'json', page },
    { query: { enabled: Number.isInteger(buildingId) && reads, retry: false } },
  )

  const payload =
    register.data?.status === 200 && typeof register.data.data !== 'string'
      ? register.data.data
      : null
  const rows = payload?.data ?? null
  const meta = payload?.meta ?? null
  const columns = meta?.columns ?? null
  const perPage = meta?.per_page ?? 100
  const total = meta?.total ?? 0
  const pages = Math.max(1, Math.ceil(total / Math.max(1, perPage)))

  /**
   * The file. Fetched as CSV through the same audited route, then handed to the
   * browser as a download; the export is itself recorded as
   * `visit_register.exported`, so a file that left the system has a line behind
   * it naming who asked for it.
   */
  async function download() {
    setDownloading(true)
    setDownloadError(null)
    try {
      const response = await exportVisitRegister(buildingId, {
        from,
        until,
        format: 'csv',
      })
      if (response.status !== 200 || typeof response.data !== 'string') {
        setDownloadError(new Error('csv'))
        return
      }
      // A byte-order mark ahead of the rows. Without it the spreadsheet most
      // wardens will open this in reads a Cyrillic name as mojibake, and a
      // register nobody can read is not an export.
      const blob = new Blob([BYTE_ORDER_MARK, response.data], {
        type: 'text/csv;charset=utf-8',
      })
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `visit-register-${buildingId}-${from}-${until}.csv`
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
        <h1 className="text-2xl font-semibold text-ink">{t('visitRegister.heading')}</h1>
        <p className="mt-1 text-steel">{t('visitRegister.lead')}</p>
      </div>

      <BuildingTabs buildingId={buildingId} />

      <p className="border-l-4 border-brass bg-brass-wash px-4 py-3 text-ink">
        {t('visitRegister.noClaim')}
      </p>

      <Panel caption={t('visitRegister.periodHeading')}>
        <form
          className="grid gap-4 px-4 py-4"
          onSubmit={(event: FormEvent<HTMLFormElement>) => {
            event.preventDefault()
            setPage(1)
            void register.refetch()
          }}
        >
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField id="register-from" label={t('visitRegister.from')}>
              <Input
                id="register-from"
                type="date"
                value={from}
                onChange={(event) => setFrom(event.target.value)}
              />
            </FormField>
            <FormField id="register-until" label={t('visitRegister.until')}>
              <Input
                id="register-until"
                type="date"
                value={until}
                onChange={(event) => setUntil(event.target.value)}
              />
            </FormField>
          </div>
          <p className="m-0 text-steel">{t('visitRegister.periodNote')}</p>
          <div className="grid gap-3 sm:grid-cols-2">
            <Button type="submit" disabled={register.isFetching}>
              {register.isFetching ? `${t('common.loading')}…` : t('visitRegister.show')}
            </Button>
            <Button
              type="button"
              variant="outline"
              disabled={downloading || !reads}
              onClick={() => void download()}
            >
              {downloading ? `${t('common.loading')}…` : t('visitRegister.export')}
            </Button>
          </div>
          <p className="m-0 text-steel">{t('visitRegister.exportNote')}</p>
        </form>
      </Panel>

      {downloadError !== null ? <RequestRefusal error={downloadError} /> : null}

      <Panel
        caption={t('visitRegister.tableHeading')}
        aside={
          meta !== null
            ? t('visitRegister.total', { count: total })
            : undefined
        }
      >
        {register.isError ? (
          <div className="px-4 py-4">
            <RequestRefusal error={register.error} />
          </div>
        ) : null}

        {register.isPending && !register.isError ? (
          <div className="grid gap-2 px-4 py-4" aria-hidden="true">
            <Skeleton className="h-24 w-full" />
            <Skeleton className="h-24 w-full" />
          </div>
        ) : null}

        {rows !== null && rows.length === 0 ? (
          <p className="px-4 py-6 text-steel">{t('visitRegister.empty')}</p>
        ) : null}

        {rows !== null && rows.length > 0 ? (
          <ul className="m-0 list-none p-0">
            {rows.map((row) => (
              <li
                key={row.guest_visit_id}
                className="border-b border-rule/70 last:border-b-0"
              >
                <RegisterRow row={row} columns={columns} />
              </li>
            ))}
          </ul>
        ) : null}

        {pages > 1 ? (
          <div className="flex flex-wrap items-center justify-between gap-3 border-t border-rule px-4 py-3">
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={page <= 1}
              onClick={() => setPage((current) => Math.max(1, current - 1))}
            >
              {t('audit.previous')}
            </Button>
            <p className="m-0 text-steel">
              {t('audit.page', {
                current: formatters.count(page),
                total: formatters.count(pages),
              })}
            </p>
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={page >= pages}
              onClick={() => setPage((current) => Math.min(pages, current + 1))}
            >
              {t('audit.next')}
            </Button>
          </div>
        ) : null}
      </Panel>
    </div>
  )
}

/**
 * One entry of the register, drawn as a block rather than as a table row.
 *
 * Seven columns of a journal do not survive 360 px as a table, and a register
 * behind a horizontal scrollbar is a register nobody reads on a telephone
 * (NFR-11). The labels and their order come from `meta.columns`, so the block
 * and the exported file list the same things in the same sequence; the words
 * themselves are translated, with the server's own as the fallback.
 */
function RegisterRow({
  row,
  columns,
}: {
  row: VisitRegisterEntry
  columns: Record<string, string> | null
}) {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const refresh = useGuestRefresh()
  const [correcting, setCorrecting] = useState(false)
  const [text, setText] = useState('')
  const correct = useCorrectGuestVisit<ApiError>()

  /*
   * The server composes the document cell as «type + masked number», and the
   * type half is written in English. On a Russian screen that reads as a hole,
   * so the type is translated from `guest_doc_type` and only the masked tail is
   * taken from the string — the last whitespace-separated token, which is what
   * the server put there. Where the type is missing the server's cell stands as
   * it came, because a half-translated line is worse than an untranslated one.
   */
  const maskedTail = (row.guest_document ?? '').split(/\s+/).at(-1) ?? ''
  const document =
    row.guest_doc_type === undefined
      ? (row.guest_document ?? '—')
      : `${t(`guestDocumentType.${row.guest_doc_type}`, {
          defaultValue: row.guest_doc_type,
        })} ${maskedTail}`

  const values: Record<string, string> = {
    guest_full_name: row.guest_full_name ?? '—',
    guest_document: document,
    inviting_resident: row.inviting_resident ?? '—',
    room: row.room ?? '—',
    checked_in_at: formatters.dateTime(row.checked_in_at),
    checked_out_at:
      row.checked_out_at === null || row.checked_out_at === undefined
        ? t('visitRegister.stillIn')
        : formatters.dateTime(row.checked_out_at),
    operator: row.operator ?? row.recorded_by ?? '—',
  }
  const order = columns === null ? Object.keys(values) : Object.keys(columns)

  return (
    <article className="grid gap-2 px-4 py-4">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h3 className="m-0 min-w-0 text-lg font-semibold break-words text-ink">
          {row.guest_full_name}
        </h3>
        {row.status !== undefined ? <VisitStatusTag status={row.status} /> : null}
      </div>

      <dl className="m-0 grid gap-x-4 gap-y-1 sm:grid-cols-[minmax(0,13rem)_minmax(0,1fr)]">
        {order.map((key) => (
          <div key={key} className="contents">
            <dt className="label-caps">
              {t(`visitRegister.columns.${key}`, {
                defaultValue: columns?.[key] ?? key,
              })}
            </dt>
            <dd className="m-0 break-words text-ink">{values[key] ?? '—'}</dd>
          </div>
        ))}
      </dl>

      {row.admitted_on_decision === true ? (
        <p className="m-0 border-l-4 border-brick bg-brick-wash px-3 py-2 break-words text-ink">
          {t('visitRegister.admittedOnDecision', {
            note: row.admission_note ?? t('common.empty'),
          })}
        </p>
      ) : null}

      {/*
        The corrections travel with the row they correct. An export that showed
        the entries and hid these would present a record the register itself
        does not stand behind.
      */}
      {row.corrections !== undefined && row.corrections.length > 0 ? (
        <ul className="m-0 list-none border-l-4 border-brass bg-brass-wash p-0">
          {row.corrections.map((correction, index) => (
            <li key={index} className="px-3 py-2">
              <p className="label-caps m-0">
                {t('visitRegister.correctionAt', {
                  time: formatters.dateTime(correction.recorded_at),
                })}
              </p>
              <p className="m-0 break-words text-ink">{correction.correction}</p>
            </li>
          ))}
        </ul>
      ) : null}

      {correct.isError ? <RequestRefusal error={correct.error} /> : null}

      {!correcting ? (
        <div>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => setCorrecting(true)}
          >
            {t('visitRegister.correct')}
          </Button>
        </div>
      ) : (
        <form
          className="grid gap-3 border border-rule bg-paper px-3 py-3"
          onSubmit={(event) => {
            event.preventDefault()
            if (row.guest_visit_id === undefined) {
              return
            }
            correct.mutate(
              { guestVisit: row.guest_visit_id, data: { correction: text.trim() } },
              {
                onSuccess: () => {
                  setCorrecting(false)
                  setText('')
                  refresh()
                },
              },
            )
          }}
        >
          <p className="m-0 text-ink">{t('visitRegister.correctNote')}</p>
          <FormField
            id={`correction-${row.guest_visit_id}`}
            label={t('visitRegister.correctLabel')}
          >
            <textarea
              id={`correction-${row.guest_visit_id}`}
              className={`${selectClassName} h-24 py-2`}
              value={text}
              minLength={3}
              maxLength={2000}
              required
              onChange={(event) => setText(event.target.value)}
            />
          </FormField>
          <div className="grid gap-2 sm:grid-cols-2">
            <Button type="submit" disabled={correct.isPending || text.trim().length < 3}>
              {correct.isPending ? `${t('common.saving')}…` : t('visitRegister.correctSave')}
            </Button>
            <Button type="button" variant="outline" onClick={() => setCorrecting(false)}>
              {t('common.cancel')}
            </Button>
          </div>
        </form>
      )}
    </article>
  )
}

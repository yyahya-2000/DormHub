import { useState, type FormEvent } from 'react'
import { keepPreviousData } from '@tanstack/react-query'
import { PencilLine } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  useCorrectGuestVisit,
  useListVisitRegister,
  type listVisitRegisterResponse,
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
import { Pagination } from '@/components/ui/pagination'
import { Skeleton } from '@/components/ui/skeleton'
import { lastPageOf, usePagination } from '@/hooks/use-pagination'
import { useFormatters, todayIso } from '@/lib/format'
import { useGuestRefresh } from '@/lib/guest-cache'

/**
 * FR-21: the visitor register over a period.
 *
 * Clause 2.1.2 of the HSE rules of internal order makes the security service
 * keep a journal by hand — guest, time of arrival, time of departure, premises,
 * whom they are visiting — and the block below is that journal plus the two
 * officers who recorded the entry and the exit.
 *
 * Nothing here edits a row. An entry is immutable: `checked_out_at` is written
 * once, guarded by a database trigger and by the service, and a mistake is put
 * right by a correcting entry that travels beside the row it corrects.
 */

function firstOfMonth(): string {
  const now = new Date()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  return `${now.getFullYear()}-${month}-01`
}

export function VisitRegisterPage() {
  const { t } = useTranslation()
  const { session } = useSession()
  const params = useParams<{ buildingId: string }>()
  const buildingId = Number(params.buildingId)

  const user = session.status === 'authenticated' ? session.user : null
  const reads = user !== null && readsVisitRegister(user, buildingId)

  const [from, setFrom] = useState(firstOfMonth)
  const [until, setUntil] = useState(todayIso)

  const paging = usePagination({ resetKey: `${buildingId}|${from}|${until}` })
  const register = useListVisitRegister<listVisitRegisterResponse, ApiError>(
    buildingId,
    { from, until, ...paging.params },
    {
      query: {
        enabled: Number.isInteger(buildingId) && reads,
        retry: false,
        placeholderData: keepPreviousData,
      },
    },
  )

  const body = register.data?.status === 200 ? register.data.data : null
  const rows = body?.data ?? null
  const meta = body?.meta ?? null

  return (
    <div className="grid grid-cols-1 gap-8">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">{t('visitRegister.heading')}</h1>
      </div>

      <BuildingTabs buildingId={buildingId} />

      <Panel caption={t('visitRegister.periodHeading')}>
        <form
          className="grid gap-4 px-4 py-4"
          onSubmit={(event: FormEvent<HTMLFormElement>) => {
            event.preventDefault()
            paging.reset()
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
          <div>
            <Button type="submit" disabled={register.isFetching}>
              {register.isFetching ? `${t('common.loading')}…` : t('visitRegister.show')}
            </Button>
          </div>
        </form>
      </Panel>

      <Panel
        caption={t('visitRegister.tableHeading')}
        aside={
          meta?.total === undefined
            ? undefined
            : t('visitRegister.total', { count: meta.total })
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
                <RegisterRow row={row} />
              </li>
            ))}
          </ul>
        ) : null}

        <Pagination
          page={paging.page}
          lastPage={lastPageOf(meta)}
          onPageChange={paging.setPage}
          disabled={register.isFetching}
        />
      </Panel>
    </div>
  )
}

/**
 * One entry of the register, drawn as a block rather than as a table row: the
 * columns of a journal do not survive 360 px as a table, and a register behind
 * a horizontal scrollbar is a register nobody reads on a telephone (NFR-11).
 */
function RegisterRow({ row }: { row: VisitRegisterEntry }) {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const refresh = useGuestRefresh()
  const [correcting, setCorrecting] = useState(false)
  const [text, setText] = useState('')
  const correct = useCorrectGuestVisit<ApiError>()

  const hostId = row.inviting_resident_id ?? null

  return (
    <article className="grid gap-2 px-4 py-4">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h3 className="m-0 min-w-0 text-lg font-semibold break-words text-ink">
          {row.guest_full_name}
        </h3>
        {row.status !== undefined ? <VisitStatusTag status={row.status} /> : null}
      </div>

      <dl className="m-0 grid gap-x-4 gap-y-1 sm:grid-cols-[minmax(0,13rem)_minmax(0,1fr)]">
        <dt className="label-caps">{t('visitRegister.columns.inviting_resident')}</dt>
        <dd className="m-0 break-words text-ink">
          {hostId === null ? (
            (row.inviting_resident ?? t('common.empty'))
          ) : (
            <Link className="text-prussian underline" to={`/residents/${hostId}`}>
              {row.inviting_resident ?? t('common.empty')}
            </Link>
          )}
        </dd>

        <dt className="label-caps">{t('visitRegister.columns.room')}</dt>
        <dd className="m-0 break-words text-ink">{row.room ?? t('common.empty')}</dd>

        <dt className="label-caps">{t('visitRegister.columns.checked_in_at')}</dt>
        <dd className="m-0 break-words text-ink">
          {formatters.dateTime(row.checked_in_at)}
          {row.recorded_by === null || row.recorded_by === undefined
            ? null
            : ` · ${row.recorded_by}`}
        </dd>

        <dt className="label-caps">{t('visitRegister.columns.checked_out_at')}</dt>
        <dd className="m-0 break-words text-ink">
          {row.checked_out_at === null || row.checked_out_at === undefined
            ? t('visitRegister.stillIn')
            : formatters.dateTime(row.checked_out_at)}
          {row.closed_by === null || row.closed_by === undefined
            ? null
            : ` · ${row.closed_by}`}
        </dd>
      </dl>

      {row.admitted_on_decision === true ? (
        <p className="m-0 border-l-4 border-brick bg-brick-wash px-3 py-2 break-words text-ink">
          {t('visitRegister.admittedOnDecision', {
            note: row.admission_note ?? t('common.empty'),
          })}
        </p>
      ) : null}

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

      {correcting ? (
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
          <FormField
            id={`correction-${row.guest_visit_id}`}
            label={t('visitRegister.correctLabel')}
            required
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
      ) : (
        <div>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => setCorrecting(true)}
          >
            <PencilLine aria-hidden="true" className="size-4" />
            {t('visitRegister.correct')}
          </Button>
        </div>
      )}
    </article>
  )
}

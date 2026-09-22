import { useState, type FormEvent } from 'react'
import { keepPreviousData } from '@tanstack/react-query'
import { X } from 'lucide-react'
import { useTranslation } from 'react-i18next'

import {
  useCancelGuestRequest,
  useListBuildings,
  useListGuestRequests,
  useSubmitGuestRequest,
  type listBuildingsResponse,
  type listGuestRequestsResponse,
} from '@/api/generated/dormitory'
import type { Building, GuestRequest, GuestRequestInput } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { guestRequestBuildingsOf } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { FormField, selectClassName } from '@/components/form-field'
import { GuestStatusTag } from '@/components/guest/guest-status-tag'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Pagination } from '@/components/ui/pagination'
import { Skeleton } from '@/components/ui/skeleton'
import { lastPageOf, usePagination } from '@/hooks/use-pagination'
import { useFormatters, todayIso } from '@/lib/format'
import { clockBound, insideWindow, withdrawable } from '@/lib/guest'
import { useGuestRefresh } from '@/lib/guest-cache'

/**
 * FR-16, the resident's half of the guest module: file a request, watch it,
 * withdraw it, and read the access code once it has been decided.
 *
 * The visiting window is the building's own: `visiting_from` and `visiting_to`
 * come down on the building row and become the `min` and `max` of the two time
 * fields, so the hours 08:00–23:00 appear nowhere in this source.
 *
 * The guest is a name and nothing else — the register keeps no document of
 * theirs, so the form asks for none.
 */

type RequestFields = {
  guestFullName: string
  visitDate: string
  plannedFrom: string
  plannedTo: string
}

function emptyForm(): RequestFields {
  return {
    guestFullName: '',
    visitDate: todayIso(),
    plannedFrom: '',
    plannedTo: '',
  }
}

function payloadOf(form: RequestFields, buildingId: number): GuestRequestInput {
  return {
    building_id: buildingId,
    guest_full_name: form.guestFullName.trim(),
    visit_date: form.visitDate,
    planned_from: form.plannedFrom,
    planned_to: form.plannedTo,
  }
}

export function GuestRequestsPage() {
  const { t } = useTranslation()
  const { session } = useSession()

  const user = session.status === 'authenticated' ? session.user : null
  const buildings = user === null ? [] : guestRequestBuildingsOf(user)
  const [chosen, setChosen] = useState<number | null>(null)
  const buildingId = chosen ?? buildings[0] ?? null

  const register = useListBuildings<listBuildingsResponse, ApiError>({
    query: { retry: false },
  })
  const rows = register.data?.status === 200 ? register.data.data.data : null
  const building = rows?.find((row) => row.id === buildingId) ?? null

  const paging = usePagination({ resetKey: buildingId })
  const mine = useListGuestRequests<listGuestRequestsResponse, ApiError>(
    { ...paging.params },
    { query: { retry: false, placeholderData: keepPreviousData } },
  )
  const body = mine.data?.status === 200 ? mine.data.data : null
  const requests = body?.data ?? null

  return (
    <div className="grid grid-cols-1 gap-8">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">{t('guest.heading')}</h1>
      </div>

      {buildings.length > 1 ? (
        <FormField id="guest-building" label={t('guest.buildingChoice')}>
          <select
            id="guest-building"
            className={selectClassName}
            value={String(buildingId ?? '')}
            onChange={(event) => setChosen(Number(event.target.value))}
          >
            {buildings.map((id) => (
              <option key={id} value={id}>
                {rows?.find((row) => row.id === id)?.name ??
                  t('roles.scopeBuilding', { id })}
              </option>
            ))}
          </select>
        </FormField>
      ) : null}

      {buildingId === null ? (
        <section className="border border-rule bg-paper-raised px-4 py-6">
          <p className="m-0 text-ink">{t('guest.noResidency')}</p>
        </section>
      ) : (
        <SubmissionForm buildingId={buildingId} building={building} />
      )}

      <Panel
        caption={t('guest.mineHeading')}
        aside={
          body?.meta?.total === undefined
            ? undefined
            : t('guest.mineCount', { count: body.meta.total })
        }
      >
        {mine.isError ? (
          <div className="px-4 py-4">
            <RequestRefusal error={mine.error} />
          </div>
        ) : null}

        {mine.isPending && !mine.isError ? (
          <div className="grid gap-2 px-4 py-4" aria-hidden="true">
            <Skeleton className="h-24 w-full" />
            <Skeleton className="h-24 w-full" />
          </div>
        ) : null}

        {requests !== null && requests.length === 0 ? (
          <p className="px-4 py-6 text-steel">{t('guest.mineEmpty')}</p>
        ) : null}

        {requests !== null && requests.length > 0 ? (
          <ul className="m-0 list-none p-0">
            {requests.map((request) => (
              <li key={request.id} className="border-b border-rule/70 last:border-b-0">
                <MyRequestRow request={request} />
              </li>
            ))}
          </ul>
        ) : null}

        <Pagination
          page={paging.page}
          lastPage={lastPageOf(body?.meta)}
          onPageChange={paging.setPage}
          disabled={mine.isFetching}
        />
      </Panel>
    </div>
  )
}

/**
 * The form. Stacked fields throughout, which is what carries it to 360 px
 * without a media query (NFR-11), and native date and time fields — the
 * resident fills this in on a telephone.
 */
function SubmissionForm({
  buildingId,
  building,
}: {
  buildingId: number
  building: Building | null
}) {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const refresh = useGuestRefresh()

  const [form, setForm] = useState<RequestFields>(emptyForm)
  const [filed, setFiled] = useState<GuestRequest | null>(null)
  const submit = useSubmitGuestRequest<ApiError>()

  const windowFrom = clockBound(building?.visiting_from)
  const windowTo = clockBound(building?.visiting_to)
  const outOfWindow =
    !insideWindow(form.plannedFrom, windowFrom, windowTo) ||
    !insideWindow(form.plannedTo, windowFrom, windowTo)

  function set<K extends keyof RequestFields>(key: K, value: RequestFields[K]) {
    setForm((current) => ({ ...current, [key]: value }))
  }

  function send(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    submit.mutate(
      { data: payloadOf(form, buildingId) },
      {
        onSuccess: (response) => {
          if (response.status !== 201) {
            return
          }
          setFiled(response.data.data)
          setForm(emptyForm())
          refresh()
        },
      },
    )
  }

  return (
    <div className="grid gap-6">
      {filed !== null ? (
        <section
          className="border-l-4 border-prussian bg-prussian-wash px-4 py-4"
          role="status"
        >
          <h2 className="m-0 text-lg font-semibold text-ink">
            {t('guest.filedTitle', { name: filed.guest_full_name })}
          </h2>
        </section>
      ) : null}

      <Panel
        caption={t('guest.formHeading')}
        aside={
          building !== null
            ? t('guest.windowAside', {
                from: formatters.clock(building.visiting_from),
                to: formatters.clock(building.visiting_to),
              })
            : undefined
        }
      >
        <form className="grid gap-4 px-4 py-4" onSubmit={send}>
          {submit.isError ? <RequestRefusal error={submit.error} /> : null}

          <FormField id="guest-name" label={t('guest.fields.guestName')} required>
            <Input
              id="guest-name"
              value={form.guestFullName}
              minLength={2}
              maxLength={255}
              autoComplete="off"
              required
              onChange={(event) => set('guestFullName', event.target.value)}
            />
          </FormField>

          <FormField id="guest-date" label={t('guest.fields.visitDate')} required>
            <Input
              id="guest-date"
              type="date"
              value={form.visitDate}
              min={todayIso()}
              required
              onChange={(event) => set('visitDate', event.target.value)}
            />
          </FormField>

          <div className="grid gap-4 sm:grid-cols-2">
            <FormField id="guest-from" label={t('guest.fields.plannedFrom')} required>
              <Input
                id="guest-from"
                type="time"
                value={form.plannedFrom}
                min={windowFrom}
                max={windowTo}
                required
                onChange={(event) => set('plannedFrom', event.target.value)}
              />
            </FormField>
            <FormField id="guest-to" label={t('guest.fields.plannedTo')} required>
              <Input
                id="guest-to"
                type="time"
                value={form.plannedTo}
                min={windowFrom}
                max={windowTo}
                required
                onChange={(event) => set('plannedTo', event.target.value)}
              />
            </FormField>
          </div>

          {outOfWindow && building !== null ? (
            <p
              className="m-0 border-l-4 border-brick bg-brick-wash px-3 py-2 text-ink"
              role="status"
            >
              {t('guest.windowBreached', {
                from: formatters.clock(building.visiting_from),
                to: formatters.clock(building.visiting_to),
              })}
            </p>
          ) : null}

          <div>
            <Button type="submit" disabled={submit.isPending}>
              {submit.isPending ? `${t('common.saving')}…` : t('guest.submit')}
            </Button>
          </div>
        </form>
      </Panel>
    </div>
  )
}

/**
 * One request of the author's own. The code is the row's whole point once the
 * decision is in, so it is set in the monospaced face and given the width of a
 * line; a refusal shows the reason written with the decision.
 */
function MyRequestRow({ request }: { request: GuestRequest }) {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const refresh = useGuestRefresh()
  const cancel = useCancelGuestRequest<ApiError>()

  const visit = request.visit ?? null

  return (
    <article className="grid gap-2 px-4 py-4">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h3 className="m-0 min-w-0 text-lg font-semibold break-words text-ink">
          {request.guest_full_name}
        </h3>
        <GuestStatusTag status={request.status} label={request.status_label} />
      </div>

      <dl className="m-0 grid gap-x-4 gap-y-1 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)]">
        <dt className="label-caps">{t('guest.fields.visitDate')}</dt>
        <dd className="m-0 text-ink">
          {formatters.date(request.visit_date)} · {request.planned_from}–
          {request.planned_to}
        </dd>

        {request.decided_by_name !== null && request.decided_by_name !== undefined ? (
          <>
            <dt className="label-caps">{t('guest.fields.decidedBy')}</dt>
            <dd className="m-0 break-words text-ink">{request.decided_by_name}</dd>
          </>
        ) : null}

        {visit !== null ? (
          <>
            <dt className="label-caps">{t('guest.fields.checkedIn')}</dt>
            <dd className="m-0 break-words text-ink">
              {formatters.dateTime(visit.checked_in_at)}
              {visit.checked_in_by_name === null || visit.checked_in_by_name === undefined
                ? null
                : ` · ${visit.checked_in_by_name}`}
            </dd>
            <dt className="label-caps">{t('guest.fields.checkedOut')}</dt>
            <dd className="m-0 break-words text-ink">
              {visit.checked_out_at === null || visit.checked_out_at === undefined
                ? t('guest.visitStillIn')
                : formatters.dateTime(visit.checked_out_at)}
              {visit.checked_out_by_name === null ||
              visit.checked_out_by_name === undefined
                ? null
                : ` · ${visit.checked_out_by_name}`}
            </dd>
          </>
        ) : null}
      </dl>

      {request.access_code !== null && request.access_code !== undefined ? (
        <div className="border border-prussian/30 bg-prussian-wash px-4 py-3">
          <p className="label-caps m-0">{t('guest.codeLabel')}</p>
          <p className="m-0 font-mono text-2xl tracking-[0.2em] break-all text-prussian">
            {request.access_code}
          </p>
        </div>
      ) : null}

      {request.decision_comment !== null &&
      request.decision_comment !== undefined &&
      request.decision_comment !== '' ? (
        <p className="m-0 border-l-4 border-brick bg-brick-wash px-3 py-2 break-words text-ink">
          {t('guest.decisionComment', { comment: request.decision_comment })}
        </p>
      ) : null}

      {cancel.isError ? <RequestRefusal error={cancel.error} /> : null}

      {withdrawable(request.status) ? (
        <div>
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={cancel.isPending}
            onClick={() =>
              cancel.mutate(
                { guestRequest: request.id },
                { onSuccess: () => refresh() },
              )
            }
          >
            <X aria-hidden="true" className="size-4" />
            {cancel.isPending ? `${t('common.saving')}…` : t('guest.withdraw')}
          </Button>
        </div>
      ) : null}
    </article>
  )
}

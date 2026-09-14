import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'

import {
  useCancelGuestRequest,
  useListBuildings,
  useListGuestRequests,
  useSubmitGuestRequest,
  type listBuildingsResponse,
  type listGuestRequestsResponse,
} from '@/api/generated/dormitory'
import {
  GuestDocumentType,
  type Building,
  type GuestRequest,
  type GuestRequestInput,
} from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { guestRequestBuildingsOf } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { FormField, selectClassName } from '@/components/form-field'
import { GuestStatusTag } from '@/components/guest/guest-status-tag'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { useFormatters, todayIso } from '@/lib/format'
import { clockBound, insideWindow, isForeignDocument, withdrawable } from '@/lib/guest'
import { useGuestRefresh } from '@/lib/guest-cache'

/**
 * FR-16, the resident's half of the guest module: file a request, watch it,
 * withdraw it, and read the access code once it has been decided.
 *
 * **The visiting window is not in this file.** `visiting_from` and
 * `visiting_to` come down on the building row and become the `min` and `max` of
 * the two time fields, so a dormitory that closes at 22:00 changes a column and
 * this form changes with it — NFR-09 carried into the interface. The hours
 * 08:00–23:00 appear nowhere in this source, and clause 2.2 of the rules of
 * internal order is the default of a column rather than a constant in a screen.
 *
 * The bounds are a courtesy and not a check: the browser refuses an hour
 * outside them, the form says so in words for a browser that does not, and the
 * server refuses it again with the reason attached (§3.3.2). The lead time is
 * the case that proves the point — it is not on the building row the client can
 * read, so a request filed too late is accepted by this form and refused by the
 * API, and the refusal is what the screen then shows.
 *
 * **The access code appears only after a decision.** It does not exist before
 * one: it is drawn at approval, and until then there is nothing to present at
 * the post. The screen therefore has no placeholder for it and no «code is
 * being prepared» — a request awaiting review shows what it is, and nothing
 * else.
 */

type RequestFields = {
  guestFullName: string
  docType: GuestDocumentType
  docNumber: string
  visitDate: string
  plannedFrom: string
  plannedTo: string
  purpose: string
}

function emptyForm(): RequestFields {
  return {
    guestFullName: '',
    docType: GuestDocumentType.internal_passport,
    docNumber: '',
    visitDate: todayIso(),
    plannedFrom: '',
    plannedTo: '',
    purpose: '',
  }
}

function payloadOf(form: RequestFields, buildingId: number): GuestRequestInput {
  const purpose = form.purpose.trim()
  return {
    building_id: buildingId,
    guest_full_name: form.guestFullName.trim(),
    guest_doc_type: form.docType,
    guest_doc_number: form.docNumber.trim(),
    visit_date: form.visitDate,
    planned_from: form.plannedFrom,
    planned_to: form.plannedTo,
    ...(purpose === '' ? {} : { purpose }),
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

  const mine = useListGuestRequests<listGuestRequestsResponse, ApiError>(undefined, {
    query: { retry: false },
  })
  const requests = mine.data?.status === 200 ? mine.data.data.data : null

  return (
    <div className="grid grid-cols-1 gap-8">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">{t('guest.heading')}</h1>
        <p className="mt-1 text-steel">{t('guest.lead')}</p>
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
          requests !== null ? t('guest.mineCount', { count: requests.length }) : undefined
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
      </Panel>
    </div>
  )
}

/**
 * The form. Stacked fields throughout, which is what carries it to 360 px
 * without a media query (NFR-11), and a native select and native date and time
 * fields — the resident fills this in on a telephone, and the platform's own
 * pickers are better than anything this project would script.
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
  const foreign = isForeignDocument(form.docType)
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
          <p className="mt-2 mb-0 text-ink">{t('guest.filedBody')}</p>
          {filed.foreign_guest_warning !== null &&
          filed.foreign_guest_warning !== undefined ? (
            <p className="mt-2 mb-0 text-ink">{filed.foreign_guest_warning}</p>
          ) : null}
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

          <FormField
            id="guest-name"
            label={t('guest.fields.guestName')}
            note={t('guest.fields.guestNameNote')}
          >
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

          <FormField id="guest-doc-type" label={t('guest.fields.docType')}>
            <select
              id="guest-doc-type"
              className={selectClassName}
              value={form.docType}
              onChange={(event) =>
                set('docType', event.target.value as GuestDocumentType)
              }
            >
              {Object.values(GuestDocumentType).map((code) => (
                <option key={code} value={code}>
                  {t(`guestDocumentType.${code}`)}
                </option>
              ))}
            </select>
          </FormField>

          {/*
            FR-23, first criterion, said before the form is sent rather than
            only in the answer. The flag itself is the server's: it is derived
            from the type and is not accepted from the client, so this notice is
            a warning and never the thing that sets it.
          */}
          {foreign ? (
            <section className="border-l-4 border-brass bg-brass-wash px-4 py-3">
              <h3 className="m-0 text-lg font-semibold text-ink">
                {t('guest.foreignTitle')}
              </h3>
              <p className="mt-2 mb-0 text-ink">{t('guest.foreignBody')}</p>
              <p className="mt-2 mb-0 text-steel">{t('guest.foreignNotSubmitted')}</p>
            </section>
          ) : null}

          <FormField
            id="guest-doc-number"
            label={t('guest.fields.docNumber')}
            note={t('guest.fields.docNumberNote')}
          >
            <Input
              id="guest-doc-number"
              value={form.docNumber}
              minLength={4}
              maxLength={64}
              autoComplete="off"
              inputMode="text"
              required
              onChange={(event) => set('docNumber', event.target.value)}
            />
          </FormField>

          <FormField id="guest-date" label={t('guest.fields.visitDate')}>
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
            <FormField id="guest-from" label={t('guest.fields.plannedFrom')}>
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
            <FormField id="guest-to" label={t('guest.fields.plannedTo')}>
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

          {building !== null ? (
            <p className="m-0 text-steel">
              {t('guest.windowNote', {
                from: formatters.clock(building.visiting_from),
                to: formatters.clock(building.visiting_to),
              })}
            </p>
          ) : null}

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

          <FormField
            id="guest-purpose"
            label={t('guest.fields.purpose')}
            note={t('guest.fields.purposeNote')}
          >
            <Input
              id="guest-purpose"
              value={form.purpose}
              maxLength={255}
              autoComplete="off"
              onChange={(event) => set('purpose', event.target.value)}
            />
          </FormField>

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
 * line; a refusal shows the reason the duty officer wrote, because «rejected»
 * without it answers nothing.
 */
function MyRequestRow({ request }: { request: GuestRequest }) {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const refresh = useGuestRefresh()
  const cancel = useCancelGuestRequest<ApiError>()

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
        <dt className="label-caps">{t('guest.fields.docType')}</dt>
        <dd className="m-0 text-ink">
          {t(`guestDocumentType.${request.guest_doc_type}`, {
            defaultValue: request.guest_doc_type_label ?? '',
          })}{' '}
          <span className="font-mono">{request.guest_doc_number_masked}</span>
        </dd>
        {request.purpose !== null && request.purpose !== undefined ? (
          <>
            <dt className="label-caps">{t('guest.fields.purpose')}</dt>
            <dd className="m-0 break-words text-ink">{request.purpose}</dd>
          </>
        ) : null}
      </dl>

      {/*
        The code exists only after the decision, so there is nothing to draw
        before one. Shown in full width and in the monospaced face: it is read
        aloud over a telephone and typed at the post.
      */}
      {request.access_code !== null && request.access_code !== undefined ? (
        <div className="border border-prussian/30 bg-prussian-wash px-4 py-3">
          <p className="label-caps m-0">{t('guest.codeLabel')}</p>
          <p className="m-0 font-mono text-2xl tracking-[0.2em] break-all text-prussian">
            {request.access_code}
          </p>
          <p className="mt-1 mb-0 text-steel">{t('guest.codeNote')}</p>
        </div>
      ) : null}

      {request.decision_comment !== null &&
      request.decision_comment !== undefined &&
      request.decision_comment !== '' ? (
        <p className="m-0 border-l-4 border-brick bg-brick-wash px-3 py-2 break-words text-ink">
          {t('guest.decisionComment', { comment: request.decision_comment })}
        </p>
      ) : null}

      {request.visit !== null && request.visit !== undefined ? (
        <p className="m-0 text-steel">
          {t('guest.visitRecorded', {
            entered: formatters.dateTime(request.visit.checked_in_at),
            left:
              request.visit.checked_out_at === null ||
              request.visit.checked_out_at === undefined
                ? t('guest.visitStillIn')
                : formatters.dateTime(request.visit.checked_out_at),
          })}
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
            {cancel.isPending ? `${t('common.saving')}…` : t('guest.withdraw')}
          </Button>
        </div>
      ) : null}
    </article>
  )
}

import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  useFileMaintenanceRequest,
  useListBuildings,
  useListMyMaintenanceRequests,
  type listBuildingsResponse,
  type listMyMaintenanceRequestsResponse,
} from '@/api/generated/dormitory'
import {
  MaintenanceCategory,
  MaintenanceLocation,
  MaintenanceUrgency,
  type MaintenanceRequest,
  type MaintenanceRequestInput,
} from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { maintenanceBuildingsOf } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { FormField, selectClassName } from '@/components/form-field'
import {
  MaintenanceStatusTag,
  MaintenanceUrgencyTag,
  OverdueTag,
} from '@/components/maintenance/maintenance-tags'
import { PhotoPicker } from '@/components/maintenance/photo-picker'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { useFormatters } from '@/lib/format'
import {
  MAINTENANCE_CATEGORIES,
  MAINTENANCE_URGENCIES,
  isOverdue,
} from '@/lib/maintenance'
import { useMaintenanceRefresh } from '@/lib/maintenance-cache'

/**
 * FR-36, the resident's half of the maintenance module: report a defect, and
 * watch what happens to it.
 *
 * **The room is not on this form, and that is the design.** For a defect in the
 * resident's own room the request is bound to the room of their active
 * residency record, read from the housing register by the server — a client
 * that could name a room could name somebody else's. The form therefore asks
 * *which kind of place* and not *which place*: the own room, which the register
 * resolves, or a common area, which has no row in the register at all and is
 * named in words. The screen says which room the request will be bound to only
 * after the server has said it, on the card that comes back.
 *
 * **A resident with no active residency record cannot file**, and the answer is
 * 403 rather than 422 — nothing about the body is wrong, and the check is a
 * question put to the register. The form is still drawn for anybody the mirror
 * believes lives here, because the mirror cannot read that register (FR-05):
 * better a refusal with the reason on it than a screen missing without
 * explanation.
 *
 * **The list here takes no parameter naming a person.** The route filters by the
 * identifier of the token, so there is no way to phrase a request for somebody
 * else's defects. The staff queue is a different route, a sub-resource of the
 * building, and it carries filters, an age, an overdue flag and an export —
 * none of which means anything on «my own three requests».
 */

type ReportFields = {
  building: string
  category: MaintenanceCategory
  location: MaintenanceLocation
  locationNote: string
  title: string
  description: string
  urgency: MaintenanceUrgency
}

/** FR-36's ceiling, repeated here and enforced by the route and by a CHECK constraint. */
const PHOTO_LIMIT = 3

export function MaintenanceRequestsPage() {
  const { t } = useTranslation()
  const { session } = useSession()

  const user = session.status === 'authenticated' ? session.user : null
  const buildings = user === null ? [] : maintenanceBuildingsOf(user)

  const mine = useListMyMaintenanceRequests<listMyMaintenanceRequestsResponse, ApiError>(
    undefined,
    { query: { retry: false } },
  )
  const requests = mine.data?.status === 200 ? mine.data.data.data : null

  return (
    <div className="grid grid-cols-1 gap-8">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">{t('maintenance.heading')}</h1>
        <p className="mt-1 text-steel">{t('maintenance.lead')}</p>
      </div>

      {buildings.length === 0 ? (
        <section className="border border-rule bg-paper-raised px-4 py-6">
          <p className="m-0 text-ink">{t('maintenance.noResidency')}</p>
        </section>
      ) : (
        <ReportForm buildings={buildings} />
      )}

      <Panel
        caption={t('maintenance.mineHeading')}
        aside={
          requests !== null
            ? t('maintenance.mineCount', { count: requests.length })
            : undefined
        }
      >
        {mine.isError ? (
          <div className="px-4 py-4">
            <RequestRefusal error={mine.error} vocabulary="maintenance" />
          </div>
        ) : null}

        {mine.isPending && !mine.isError ? (
          <div className="grid gap-2 px-4 py-4" aria-hidden="true">
            <Skeleton className="h-28 w-full" />
            <Skeleton className="h-28 w-full" />
          </div>
        ) : null}

        {requests !== null && requests.length === 0 ? (
          <p className="px-4 py-6 text-steel">{t('maintenance.mineEmpty')}</p>
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
 * The form. Stacked fields and native controls throughout — the resident fills
 * this in on a telephone in the room the tap is dripping in, and the platform's
 * own file picker reaches the camera in a way nothing scripted here would.
 */
function ReportForm({ buildings }: { buildings: number[] }) {
  const { t } = useTranslation()
  const refresh = useMaintenanceRefresh()

  const register = useListBuildings<listBuildingsResponse, ApiError>({
    query: { retry: false },
  })
  const rows = register.data?.status === 200 ? register.data.data.data : null

  const [form, setForm] = useState<ReportFields>(() => ({
    building: String(buildings[0] ?? ''),
    category: MaintenanceCategory.plumbing,
    location: MaintenanceLocation.own_room,
    locationNote: '',
    title: '',
    description: '',
    urgency: MaintenanceUrgency.routine,
  }))
  const [photos, setPhotos] = useState<File[]>([])
  const [filed, setFiled] = useState<MaintenanceRequest | null>(null)
  const submit = useFileMaintenanceRequest<ApiError>()

  const commonArea = form.location === MaintenanceLocation.common_area

  function set<K extends keyof ReportFields>(key: K, value: ReportFields[K]) {
    setForm((current) => ({ ...current, [key]: value }))
  }

  function send(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const note = form.locationNote.trim()
    const title = form.title.trim()
    const payload: MaintenanceRequestInput = {
      building_id: Number(form.building),
      category: form.category,
      location: form.location,
      description: form.description.trim(),
      urgency: form.urgency,
      // Sent only where it means something. A note beside a room resolved from
      // the register would be a second and unreliable answer to «where», and the
      // route ignores it there — so the client does not send it either.
      ...(commonArea && note !== '' ? { location_note: note } : {}),
      ...(title === '' ? {} : { title }),
      ...(photos.length === 0 ? {} : { photos }),
    }
    submit.mutate(
      { data: payload },
      {
        onSuccess: (response) => {
          if (response.status !== 201) {
            return
          }
          setFiled(response.data.data)
          setForm((current) => ({
            ...current,
            locationNote: '',
            title: '',
            description: '',
          }))
          setPhotos([])
          refresh()
        },
      },
    )
  }

  return (
    <div className="grid gap-6">
      {filed !== null ? (
        <section className="border-l-4 border-prussian bg-prussian-wash px-4 py-4" role="status">
          <h2 className="m-0 text-lg font-semibold text-ink">
            {t('maintenance.filedTitle', { number: filed.id })}
          </h2>
          {/*
            The place as the server resolved it, and the first time the resident
            sees it. `place` is the register's answer for an own-room request and
            the typed note for a common area, so one field says both.
          */}
          <p className="mt-2 mb-0 text-ink">
            {t('maintenance.filedBody', {
              place: filed.place ?? t('common.empty'),
            })}
          </p>
          <p className="mt-2 mb-0">
            <Link
              className="font-medium text-prussian underline"
              to={`/maintenance/${filed.id}`}
            >
              {t('maintenance.openCard')}
            </Link>
          </p>
        </section>
      ) : null}

      <Panel caption={t('maintenance.formHeading')}>
        <form className="grid gap-4 px-4 py-4" onSubmit={send}>
          {submit.isError ? (
            <RequestRefusal error={submit.error} vocabulary="maintenance" />
          ) : null}

          {buildings.length > 1 ? (
            <FormField id="maintenance-building" label={t('maintenance.fields.building')}>
              <select
                id="maintenance-building"
                className={selectClassName}
                value={form.building}
                onChange={(event) => set('building', event.target.value)}
              >
                {buildings.map((id) => (
                  <option key={id} value={String(id)}>
                    {rows?.find((row) => row.id === id)?.name ??
                      t('roles.scopeBuilding', { id })}
                  </option>
                ))}
              </select>
            </FormField>
          ) : null}

          <FormField
            id="maintenance-category"
            label={t('maintenance.fields.category')}
            note={t('maintenance.fields.categoryNote')}
          >
            <select
              id="maintenance-category"
              className={selectClassName}
              value={form.category}
              onChange={(event) =>
                set('category', event.target.value as MaintenanceCategory)
              }
            >
              {MAINTENANCE_CATEGORIES.map((value) => (
                <option key={value} value={value}>
                  {t(`maintenanceCategory.${value}`)}
                </option>
              ))}
            </select>
          </FormField>

          <FormField
            id="maintenance-location"
            label={t('maintenance.fields.location')}
            note={
              commonArea
                ? t('maintenance.fields.locationNoteCommon')
                : t('maintenance.fields.locationNoteOwn')
            }
          >
            <select
              id="maintenance-location"
              className={selectClassName}
              value={form.location}
              onChange={(event) =>
                set('location', event.target.value as MaintenanceLocation)
              }
            >
              <option value={MaintenanceLocation.own_room}>
                {t('maintenanceLocation.own_room')}
              </option>
              <option value={MaintenanceLocation.common_area}>
                {t('maintenanceLocation.common_area')}
              </option>
            </select>
          </FormField>

          {commonArea ? (
            <FormField
              id="maintenance-note"
              label={t('maintenance.fields.locationName')}
              note={t('maintenance.fields.locationNameNote')}
            >
              <Input
                id="maintenance-note"
                value={form.locationNote}
                maxLength={255}
                autoComplete="off"
                required
                onChange={(event) => set('locationNote', event.target.value)}
              />
            </FormField>
          ) : null}

          <FormField
            id="maintenance-title"
            label={t('maintenance.fields.title')}
            note={t('maintenance.fields.titleNote')}
          >
            <Input
              id="maintenance-title"
              value={form.title}
              maxLength={255}
              autoComplete="off"
              onChange={(event) => set('title', event.target.value)}
            />
          </FormField>

          <FormField
            id="maintenance-description"
            label={t('maintenance.fields.description')}
            note={t('maintenance.fields.descriptionNote')}
          >
            <textarea
              id="maintenance-description"
              className={`${selectClassName} h-32 py-2`}
              value={form.description}
              minLength={10}
              maxLength={2000}
              required
              onChange={(event) => set('description', event.target.value)}
            />
          </FormField>

          <FormField
            id="maintenance-urgency"
            label={t('maintenance.fields.urgency')}
            note={t('maintenance.fields.urgencyNote')}
          >
            <select
              id="maintenance-urgency"
              className={selectClassName}
              value={form.urgency}
              onChange={(event) =>
                set('urgency', event.target.value as MaintenanceUrgency)
              }
            >
              {MAINTENANCE_URGENCIES.map((value) => (
                <option key={value} value={value}>
                  {t(`maintenanceUrgency.${value}`)}
                </option>
              ))}
            </select>
          </FormField>

          <FormField
            id="maintenance-photos"
            label={t('maintenance.fields.photos')}
            note={t('maintenance.fields.photosNote')}
          >
            <PhotoPicker
              id="maintenance-photos"
              value={photos}
              max={PHOTO_LIMIT}
              onChange={setPhotos}
            />
          </FormField>

          <div>
            <Button
              type="submit"
              size="lg"
              className="h-auto min-h-12 min-w-0 whitespace-normal"
              disabled={submit.isPending}
            >
              {submit.isPending ? `${t('common.saving')}…` : t('maintenance.submit')}
            </Button>
          </div>
        </form>
      </Panel>
    </div>
  )
}

/** One request of the reporter's own, as a row that leads to the card. */
function MyRequestRow({ request }: { request: MaintenanceRequest }) {
  const { t } = useTranslation()
  const formatters = useFormatters()

  return (
    <article className="grid gap-2 px-4 py-4">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h3 className="m-0 min-w-0 text-lg font-semibold break-words text-ink">
          <Link className="text-prussian underline" to={`/maintenance/${request.id}`}>
            {request.title !== null && request.title !== undefined && request.title !== ''
              ? request.title
              : t(`maintenanceCategory.${request.category}`, {
                  defaultValue: request.category_label ?? request.category,
                })}
          </Link>
        </h3>
        <div className="flex flex-wrap gap-2">
          {isOverdue(request) ? (
            <OverdueTag afterDays={request.overdue_after_days} />
          ) : null}
          <MaintenanceStatusTag
            status={request.status}
            label={request.status_label}
          />
        </div>
      </div>

      <dl className="m-0 grid gap-x-4 gap-y-1 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)]">
        <dt className="label-caps">{t('maintenance.fields.place')}</dt>
        <dd className="m-0 break-words text-ink">{request.place ?? t('common.empty')}</dd>
        <dt className="label-caps">{t('maintenance.fields.filed')}</dt>
        <dd className="m-0 text-ink">
          {formatters.dateTime(request.created_at)}
          {request.age_days === undefined
            ? null
            : ` · ${t('maintenance.ageDays', { count: request.age_days })}`}
        </dd>
        {request.target_date !== null && request.target_date !== undefined ? (
          <>
            <dt className="label-caps">{t('maintenance.fields.targetDate')}</dt>
            <dd className="m-0 text-ink">{formatters.date(request.target_date)}</dd>
          </>
        ) : null}
      </dl>

      <div className="flex flex-wrap gap-2">
        {request.urgency !== undefined ? (
          <MaintenanceUrgencyTag
            urgency={request.urgency}
            label={request.urgency_label}
          />
        ) : null}
      </div>
    </article>
  )
}

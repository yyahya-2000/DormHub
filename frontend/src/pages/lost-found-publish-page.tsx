import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  useListBuildings,
  usePublishLostFoundItem,
  type listBuildingsResponse,
} from '@/api/generated/dormitory'
import {
  LostFoundCustody,
  LostFoundItemKind,
  type LostFoundItem,
  type LostFoundItemInput,
} from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { depositsLostFoundItems, lostFoundBuildingsOf } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { FormField, selectClassName } from '@/components/form-field'
import { PhotoPicker } from '@/components/maintenance/photo-picker'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useLostFoundRefresh } from '@/lib/lost-found-cache'
import { LOST_FOUND_KINDS, latestFindingDate } from '@/lib/lost-found'

type PublishFields = {
  building: string
  kind: LostFoundItemKind
  custody: LostFoundCustody
  title: string
  description: string
  place: string
  happenedOn: string
  declaredOn: string
}

/** FR-24, third criterion: the photograph is optional, and there is at most one. */
const PHOTO_LIMIT = 1

/** FR-24: publishing a find — or a loss, which is the same notice read from the other end. */
export function LostFoundPublishPage() {
  const { t } = useTranslation()
  const { session } = useSession()

  const user = session.status === 'authenticated' ? session.user : null
  const buildings = user === null ? [] : lostFoundBuildingsOf(user)

  return (
    <div className="grid grid-cols-1 gap-6">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">
          {t('lostFound.publishHeading')}
        </h1>
      </div>

      <div>
        <Button
          asChild
          variant="outline"
          className="h-auto min-h-11 min-w-0 whitespace-normal"
        >
          <Link to="/lost-found">{t('lostFound.backToFeed')}</Link>
        </Button>
      </div>

      {buildings.length === 0 ? (
        <section className="border border-rule bg-paper-raised px-4 py-6">
          <p className="m-0 text-ink">{t('lostFound.noBuilding')}</p>
        </section>
      ) : (
        <PublishForm buildings={buildings} />
      )}
    </div>
  )
}

function PublishForm({ buildings }: { buildings: number[] }) {
  const { t } = useTranslation()
  const { session } = useSession()
  const refresh = useLostFoundRefresh()

  const user = session.status === 'authenticated' ? session.user : null

  const register = useListBuildings<listBuildingsResponse, ApiError>({
    query: { retry: false },
  })
  const rows = register.data?.status === 200 ? register.data.data.data : null

  const [form, setForm] = useState<PublishFields>(() => ({
    building: String(buildings[0] ?? ''),
    kind: LostFoundItemKind.found,
    custody: LostFoundCustody.finder,
    title: '',
    description: '',
    place: '',
    happenedOn: latestFindingDate(),
    declaredOn: '',
  }))
  const [photos, setPhotos] = useState<File[]>([])
  const [published, setPublished] = useState<LostFoundItem | null>(null)
  const submit = usePublishLostFoundItem<ApiError>()

  const buildingId = Number(form.building)
  /*
   * Asked per building and not once, because the safekeeping capability is held
   * in a dormitory: a warden of block A choosing block B in the selector is
   * offered nothing, which is the same answer the server would give.
   */
  const deposits =
    user !== null && Number.isInteger(buildingId) && depositsLostFoundItems(user, buildingId)
  const deposited = deposits && form.custody === LostFoundCustody.administration

  function set<K extends keyof PublishFields>(key: K, value: PublishFields[K]) {
    setForm((current) => ({ ...current, [key]: value }))
  }

  function send(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const description = form.description.trim()
    const payload: LostFoundItemInput = {
      building_id: buildingId,
      title: form.title.trim(),
      place: form.place.trim(),
      happened_on: form.happenedOn,
      kind: form.kind,
      ...(description === '' ? {} : { description }),
      // Sent only where the account may state it. The default is the ordinary
      // path, and a client that sent it explicitly would be saying the same
      // thing in more words.
      ...(deposited ? { custody: LostFoundCustody.administration } : {}),
      // A declaration date on an entry that names no declaration is a date
      // nobody made, so it travels only on the path that offers the field.
      ...(deposited && form.declaredOn !== '' ? { declared_on: form.declaredOn } : {}),
      ...(photos[0] === undefined ? {} : { photo: photos[0] }),
    }
    submit.mutate(
      { data: payload },
      {
        onSuccess: (response) => {
          if (response.status !== 201) {
            return
          }
          setPublished(response.data.data)
          setForm((current) => ({
            ...current,
            title: '',
            description: '',
            place: '',
            declaredOn: '',
          }))
          setPhotos([])
          refresh()
        },
      },
    )
  }

  return (
    <div className="grid gap-6">
      {published !== null ? (
        <section
          className="border-l-4 border-prussian bg-prussian-wash px-4 py-4"
          role="status"
        >
          <h2 className="m-0 text-lg font-semibold text-ink">
            {t('lostFound.publishedTitle')}
          </h2>
          <p className="mt-2 mb-0 text-ink">
            {t('lostFound.publishedBody', { title: published.title })}
          </p>
          <p className="mt-2 mb-0">
            <Link
              className="font-medium text-prussian underline"
              to={`/lost-found/${published.id}`}
            >
              {t('lostFound.openEntry')}
            </Link>
          </p>
        </section>
      ) : null}

      <Panel caption={t('lostFound.formHeading')}>
        <form className="grid gap-4 px-4 py-4" onSubmit={send}>
          {submit.isError ? (
            <RequestRefusal error={submit.error} vocabulary="lostFound" />
          ) : null}

          {buildings.length > 1 ? (
            <FormField
              id="lost-found-building"
              label={t('lostFound.fields.building')}
              required
            >
              <select
                id="lost-found-building"
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
            id="lost-found-kind-field"
            label={t('lostFound.fields.kind')}
            required
          >
            <select
              id="lost-found-kind-field"
              className={selectClassName}
              value={form.kind}
              onChange={(event) => {
                const kind = event.target.value as LostFoundItemKind
                setForm((current) => ({
                  ...current,
                  kind,
                  // A loss is a notice by somebody holding nothing, so there is
                  // no custody to assert and no declaration to record.
                  ...(kind === LostFoundItemKind.lost
                    ? { custody: LostFoundCustody.finder, declaredOn: '' }
                    : {}),
                }))
              }}
            >
              {LOST_FOUND_KINDS.map((value) => (
                <option key={value} value={value}>
                  {t(`lostFoundKind.${value}`)}
                </option>
              ))}
            </select>
          </FormField>

          {deposits && form.kind === LostFoundItemKind.found ? (
            <FormField
              id="lost-found-custody"
              label={t('lostFound.fields.custody')}
              required
            >
              <select
                id="lost-found-custody"
                className={selectClassName}
                value={form.custody}
                onChange={(event) =>
                  set('custody', event.target.value as LostFoundCustody)
                }
              >
                <option value={LostFoundCustody.finder}>
                  {t('lostFoundCustody.finder')}
                </option>
                <option value={LostFoundCustody.administration}>
                  {t('lostFoundCustody.administration')}
                </option>
              </select>
            </FormField>
          ) : null}

          <FormField id="lost-found-title" label={t('lostFound.fields.title')} required>
            <Input
              id="lost-found-title"
              value={form.title}
              minLength={3}
              maxLength={255}
              autoComplete="off"
              required
              onChange={(event) => set('title', event.target.value)}
            />
          </FormField>

          <FormField
            id="lost-found-place"
            label={t(`lostFound.fields.placeLabel.${form.kind}`)}
            required
          >
            <Input
              id="lost-found-place"
              value={form.place}
              minLength={3}
              maxLength={255}
              autoComplete="off"
              required
              onChange={(event) => set('place', event.target.value)}
            />
          </FormField>

          <FormField
            id="lost-found-happened"
            label={t(`lostFound.fields.happenedOn.${form.kind}`)}
            required
          >
            <Input
              id="lost-found-happened"
              type="date"
              value={form.happenedOn}
              max={latestFindingDate()}
              required
              onChange={(event) => set('happenedOn', event.target.value)}
            />
          </FormField>

          {deposited ? (
            <FormField id="lost-found-declared" label={t('lostFound.fields.declaredOn')}>
              <Input
                id="lost-found-declared"
                type="date"
                value={form.declaredOn}
                min={form.happenedOn}
                max={latestFindingDate()}
                onChange={(event) => set('declaredOn', event.target.value)}
              />
            </FormField>
          ) : null}

          <FormField
            id="lost-found-description"
            label={t('lostFound.fields.description')}
          >
            <textarea
              id="lost-found-description"
              className={`${selectClassName} h-32 py-2`}
              value={form.description}
              maxLength={2000}
              onChange={(event) => set('description', event.target.value)}
            />
          </FormField>

          <FormField id="lost-found-photo" label={t('lostFound.fields.photo')}>
            <PhotoPicker
              id="lost-found-photo"
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
              {submit.isPending ? `${t('common.saving')}…` : t('lostFound.publish')}
            </Button>
          </div>
        </form>
      </Panel>
    </div>
  )
}

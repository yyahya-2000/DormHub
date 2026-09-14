import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  useListBuildings,
  usePublishAnnouncement,
  type listBuildingsResponse,
} from '@/api/generated/dormitory'
import type { Announcement, AnnouncementInput } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { addressesEveryBuilding, announcementBuildingsOf } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { FormField, selectClassName } from '@/components/form-field'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useAnnouncementCategories } from '@/hooks/use-announcement-categories'
import { useAnnouncementRefresh } from '@/lib/announcement-cache'
import {
  CATEGORY_MAX_LENGTH,
  OTHER_CATEGORY,
  expiryDefault,
  expiryInstant,
} from '@/lib/announcements'

type PublicationFields = {
  building: string
  title: string
  body: string
  category: string
  ownCategory: string
  expires: string
}

/** The addressee «every dormitory», as a value a native select can carry. */
const EVERY_BUILDING = 'every'

/**
 * FR-09, publication: the manager of the dormitory named, or the administrator.
 *
 * The addressee is a dormitory and the field says so. The administrator picks
 * one from the register or addresses them all; a manager publishes where the
 * appointment puts them, so the field states the dormitory and offers nothing
 * to choose.
 */
export function AnnouncementPublishPage() {
  const { t } = useTranslation()
  const { session } = useSession()
  const refresh = useAnnouncementRefresh()

  const user = session.status === 'authenticated' ? session.user : null
  const own = user === null ? [] : announcementBuildingsOf(user)
  const everyBuilding = user !== null && addressesEveryBuilding(user)
  const publishes = everyBuilding || own.length > 0

  const register = useListBuildings<listBuildingsResponse, ApiError>({
    query: { retry: false, enabled: publishes },
  })
  const buildings = register.data?.status === 200 ? register.data.data.data : null
  const choices = everyBuilding ? (buildings ?? []).map((row) => row.id) : own

  const categories = useAnnouncementCategories(publishes)

  const [form, setForm] = useState<PublicationFields>(() => ({
    building: '',
    title: '',
    body: '',
    category: '',
    ownCategory: '',
    expires: expiryDefault(14),
  }))
  const [published, setPublished] = useState<Announcement | null>(null)
  const publish = usePublishAnnouncement<ApiError>()

  // One dormitory and no right to address the rest: there is nothing to choose,
  // so the field reports the addressee instead of asking for it.
  const fixed = !everyBuilding && choices.length === 1
  const fallback = everyBuilding
    ? EVERY_BUILDING
    : (choices[0] !== undefined ? String(choices[0]) : '')
  const building = form.building === '' ? fallback : form.building

  const ownCategory = form.category === OTHER_CATEGORY
  const category = ownCategory ? form.ownCategory.trim() : form.category

  function set<K extends keyof PublicationFields>(key: K, value: PublicationFields[K]) {
    setForm((current) => ({ ...current, [key]: value }))
  }

  function nameOf(id: number): string {
    return buildings?.find((row) => row.id === id)?.name ?? t('roles.scopeBuilding', { id })
  }

  function send(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const expires = expiryInstant(form.expires)
    const payload: AnnouncementInput = {
      building_id: building === EVERY_BUILDING ? null : Number(building),
      title: form.title.trim(),
      body: form.body.trim(),
      category,
      ...(expires === null ? {} : { expires_at: expires }),
    }
    publish.mutate(
      { data: payload },
      {
        onSuccess: (response) => {
          if (response.status !== 201) {
            return
          }
          setPublished(response.data.data)
          setForm((current) => ({ ...current, title: '', body: '' }))
          refresh()
        },
      },
    )
  }

  if (!publishes) {
    return (
      <div className="grid grid-cols-1 gap-8">
        <h1 className="text-2xl font-semibold text-ink">
          {t('announcements.publishHeading')}
        </h1>
        <div className="flex flex-wrap gap-2">
          <Button
            asChild
            variant="outline"
            className="h-auto min-h-11 min-w-0 whitespace-normal"
          >
            <Link to="/announcements">{t('announcements.backToFeed')}</Link>
          </Button>
        </div>
      </div>
    )
  }

  return (
    <div className="grid grid-cols-1 gap-8">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">
          {t('announcements.publishHeading')}
        </h1>
      </div>

      <div className="flex flex-wrap gap-2">
        <Button asChild variant="outline" className="h-auto min-h-11 min-w-0 whitespace-normal">
          <Link to="/announcements">{t('announcements.backToFeed')}</Link>
        </Button>
      </div>

      {published !== null ? (
        <p
          className="m-0 border-l-4 border-prussian bg-prussian-wash px-4 py-3 text-ink"
          role="status"
        >
          {t('announcements.publishedTitle', { title: published.title })}
        </p>
      ) : null}

      <Panel caption={t('announcements.formHeading')}>
        <form className="grid gap-4 px-4 py-4" onSubmit={send}>
          {publish.isError ? <RequestRefusal error={publish.error} /> : null}

          {fixed ? (
            <FormField
              id="announcement-building"
              label={t('announcements.fields.building')}
            >
              <Input
                id="announcement-building"
                value={nameOf(choices[0] ?? 0)}
                readOnly
                className="bg-paper text-steel"
              />
            </FormField>
          ) : (
            <FormField
              id="announcement-building"
              label={t('announcements.fields.building')}
              required
            >
              <select
                id="announcement-building"
                className={selectClassName}
                value={building}
                onChange={(event) => set('building', event.target.value)}
              >
                {everyBuilding ? (
                  <option value={EVERY_BUILDING}>{t('announcements.everyBuilding')}</option>
                ) : null}
                {choices.map((id) => (
                  <option key={id} value={String(id)}>
                    {nameOf(id)}
                  </option>
                ))}
              </select>
            </FormField>
          )}

          <FormField
            id="announcement-title"
            label={t('announcements.fields.title')}
            required
          >
            <Input
              id="announcement-title"
              value={form.title}
              minLength={3}
              maxLength={255}
              autoComplete="off"
              required
              onChange={(event) => set('title', event.target.value)}
            />
          </FormField>

          <FormField
            id="announcement-body"
            label={t('announcements.fields.body')}
            required
          >
            <textarea
              id="announcement-body"
              className={`${selectClassName} h-40 py-2`}
              value={form.body}
              minLength={3}
              maxLength={20_000}
              required
              onChange={(event) => set('body', event.target.value)}
            />
          </FormField>

          <FormField
            id="announcement-form-category"
            label={t('announcements.fields.category')}
            required
          >
            <select
              id="announcement-form-category"
              className={selectClassName}
              value={form.category}
              required
              onChange={(event) => set('category', event.target.value)}
            >
              <option value="">{t('announcements.categoryPick')}</option>
              {categories.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
              <option value={OTHER_CATEGORY}>{t('announcements.categoryOther')}</option>
            </select>
          </FormField>

          {ownCategory ? (
            <FormField
              id="announcement-own-category"
              label={t('announcements.fields.ownCategory')}
              required
            >
              <Input
                id="announcement-own-category"
                value={form.ownCategory}
                maxLength={CATEGORY_MAX_LENGTH}
                autoComplete="off"
                required
                onChange={(event) => set('ownCategory', event.target.value)}
              />
            </FormField>
          ) : null}

          <FormField id="announcement-expires" label={t('announcements.fields.expires')}>
            <Input
              id="announcement-expires"
              type="datetime-local"
              value={form.expires}
              onChange={(event) => set('expires', event.target.value)}
            />
          </FormField>

          <div>
            <Button
              type="submit"
              size="lg"
              className="h-auto min-h-12 min-w-0 whitespace-normal"
              disabled={publish.isPending || building === '' || category === ''}
            >
              {publish.isPending ? `${t('common.saving')}…` : t('announcements.publish')}
            </Button>
          </div>
        </form>
      </Panel>
    </div>
  )
}

import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  useListBuildings,
  usePublishAnnouncement,
  type listBuildingsResponse,
} from '@/api/generated/dormitory'
import {
  AnnouncementCategory,
  type Announcement,
  type AnnouncementInput,
} from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { addressesEveryBuilding, announcementBuildingsOf } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { FormField, selectClassName } from '@/components/form-field'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useAnnouncementRefresh } from '@/lib/announcement-cache'
import {
  ANNOUNCEMENT_CATEGORIES,
  expiryDefault,
  expiryInstant,
} from '@/lib/announcements'
import { useFormatters } from '@/lib/format'

/**
 * FR-09, publication. The warden or the manager of the dormitory named, and the
 * administrator.
 *
 * **«Every dormitory» is an audience and not an empty field** (§3.4.2), and it
 * belongs to the administrator alone. A warden permitted to leave the addressee
 * out would be addressing buildings his grant does not name — FR-07's boundary
 * crossed by an omission rather than by a leak — so the option is absent from
 * the list for everybody else rather than present and refused. The server
 * refuses it anyway, with 403, for a client that sends it regardless (§3.3.2).
 *
 * **There is no publication date on this form.** `published_at` is not accepted
 * by the route: publication happens now. A settable date would let a notice be
 * back-dated, and the evidence FR-12 rests on is that the resident was told on a
 * day they can check.
 *
 * **The obligation is a separate question from the subject.** `category` says
 * what the notice is about and `is_mandatory` whether the reader must
 * acknowledge it — two columns in the model and two controls here. A water
 * shutoff is `utilities` and is normally mandatory; a film evening is `events`
 * and never is. Marking a notice mandatory also makes it a message the resident
 * cannot switch off (FR-34), which the form says in words before it is sent
 * rather than leaving it to be discovered.
 */

type PublicationFields = {
  audience: string
  title: string
  body: string
  category: AnnouncementCategory
  mandatory: boolean
  expires: string
}

/** The addressee «every dormitory», as a value a native select can carry. */
const EVERY_BUILDING = 'every'

export function AnnouncementPublishPage() {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const { session } = useSession()
  const refresh = useAnnouncementRefresh()

  const user = session.status === 'authenticated' ? session.user : null
  const own = user === null ? [] : announcementBuildingsOf(user)
  const everyBuilding = user !== null && addressesEveryBuilding(user)

  /*
   * The administrator's grant names no building, so his own list is empty and
   * the register is what he chooses from. Everybody else takes the list from
   * their grants and never asks the register at all — the names are a courtesy
   * over identifiers the account already holds.
   */
  const register = useListBuildings<listBuildingsResponse, ApiError>({
    query: { retry: false, enabled: everyBuilding || own.length > 0 },
  })
  const buildings = register.data?.status === 200 ? register.data.data.data : null
  const choices = everyBuilding
    ? (buildings ?? []).map((row) => row.id)
    : own
  /*
   * An account that publishes nowhere — the duty officer and the security
   * officer, who reach this address only by typing it — is told so in a
   * sentence. The alternative is an empty addressee list above a button that
   * cannot be pressed, which is a dead end wearing the costume of a form; and a
   * form that submitted anyway would trade the sentence for a 403. §3.3.2 draws
   * the line at what is offered, and nothing is offered here.
   */
  const publishesNowhere = !everyBuilding && own.length === 0

  const [form, setForm] = useState<PublicationFields>(() => ({
    audience: '',
    title: '',
    body: '',
    category: AnnouncementCategory.general,
    mandatory: false,
    expires: expiryDefault(14),
  }))
  const [published, setPublished] = useState<Announcement | null>(null)
  const publish = usePublishAnnouncement<ApiError>()

  // The addressee falls back to the only dormitory the account may publish in,
  // and to «every dormitory» for an administrator who has not chosen one. Both
  // are defaults of the control, and both travel in the body as an explicit
  // value — never as a field left out.
  const audience =
    form.audience !== ''
      ? form.audience
      : (choices[0] !== undefined
          ? String(choices[0])
          : everyBuilding
            ? EVERY_BUILDING
            : '')

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
      building_id: audience === EVERY_BUILDING ? null : Number(audience),
      title: form.title.trim(),
      body: form.body.trim(),
      category: form.category,
      is_mandatory: form.mandatory,
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
          setForm((current) => ({ ...current, title: '', body: '', mandatory: false }))
          refresh()
        },
      },
    )
  }

  return (
    <div className="grid grid-cols-1 gap-8">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">
          {t('announcements.publishHeading')}
        </h1>
        <p className="mt-1 text-steel">{t('announcements.publishLead')}</p>
      </div>

      <div className="flex flex-wrap gap-2">
        <Button asChild variant="outline" className="h-auto min-h-11 min-w-0 whitespace-normal">
          <Link to="/announcements">{t('announcements.backToFeed')}</Link>
        </Button>
      </div>

      {publishesNowhere ? (
        <section className="border border-rule bg-paper-raised px-4 py-6">
          <p className="m-0 text-ink">{t('announcements.publishesNowhere')}</p>
        </section>
      ) : null}

      {published !== null ? (
        <section className="border-l-4 border-prussian bg-prussian-wash px-4 py-4" role="status">
          <h2 className="m-0 text-lg font-semibold text-ink">
            {t('announcements.publishedTitle', { title: published.title })}
          </h2>
          <p className="mt-2 mb-0 text-ink">
            {t('announcements.publishedBody', {
              time: formatters.dateTime(published.published_at),
              audience:
                published.addresses_every_building === true
                  ? t('announcements.everyBuilding')
                  : (published.building_name ?? nameOf(published.building_id ?? 0)),
            })}
          </p>
          {published.is_mandatory ? (
            <p className="mt-2 mb-0">
              <Link
                className="font-medium text-prussian underline"
                to={`/announcements/${published.id}/readers`}
              >
                {t('announcements.readersLink')}
              </Link>
            </p>
          ) : null}
        </section>
      ) : null}

      {publishesNowhere ? null : (
      <Panel caption={t('announcements.formHeading')}>
        <form className="grid gap-4 px-4 py-4" onSubmit={send}>
          {publish.isError ? <RequestRefusal error={publish.error} /> : null}

          <FormField
            id="announcement-audience"
            label={t('announcements.fields.audience')}
            note={
              everyBuilding
                ? t('announcements.fields.audienceNoteAdmin')
                : t('announcements.fields.audienceNoteWarden')
            }
          >
            <select
              id="announcement-audience"
              className={selectClassName}
              value={audience}
              onChange={(event) => set('audience', event.target.value)}
            >
              {/*
                Present for the administrator and absent for everyone else. Not
                disabled: a disabled option says «not now» and invites the
                reader to look for the condition that would enable it, and there
                is none — the audience is his and nobody else's.
              */}
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

          <FormField id="announcement-title" label={t('announcements.fields.title')}>
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
            note={t('announcements.fields.bodyNote')}
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
            note={t('announcements.fields.categoryNote')}
          >
            <select
              id="announcement-form-category"
              className={selectClassName}
              value={form.category}
              onChange={(event) =>
                set('category', event.target.value as AnnouncementCategory)
              }
            >
              {ANNOUNCEMENT_CATEGORIES.map((value) => (
                <option key={value} value={value}>
                  {t(`announcementCategory.${value}`)}
                </option>
              ))}
            </select>
          </FormField>

          <div className="grid min-w-0 gap-1">
            <label className="flex items-start gap-3" htmlFor="announcement-mandatory">
              <input
                id="announcement-mandatory"
                type="checkbox"
                className="mt-1 size-5 shrink-0 border border-rule accent-prussian"
                checked={form.mandatory}
                onChange={(event) => set('mandatory', event.target.checked)}
              />
              <span className="min-w-0 text-ink">
                {t('announcements.fields.mandatory')}
              </span>
            </label>
            <p className="m-0 text-steel">{t('announcements.fields.mandatoryNote')}</p>
          </div>

          <FormField
            id="announcement-expires"
            label={t('announcements.fields.expires')}
            note={t('announcements.fields.expiresNote')}
          >
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
              disabled={publish.isPending || audience === ''}
            >
              {publish.isPending ? `${t('common.saving')}…` : t('announcements.publish')}
            </Button>
          </div>
        </form>
      </Panel>
      )}
    </div>
  )
}

import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import { useAcknowledgeAnnouncement } from '@/api/generated/dormitory'
import type { Announcement } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { useAnnouncementRefresh } from '@/lib/announcement-cache'
import { CATEGORY_TONE, acknowledged, awaitsAcknowledgement } from '@/lib/announcements'
import { useFormatters } from '@/lib/format'
import { cn } from '@/lib/utils'

/**
 * One announcement in the feed (FR-11), with the acknowledgement of FR-12 on it.
 *
 * **The unread mark is a rule down the side and not a dot.** FR-11's second
 * criterion asks for unread items to be visually marked, and a feed read on a
 * telephone in a corridor needs that mark to survive being glanced at: a full
 * edge of the block in the dark blue reads at arm's length, where a badge in a
 * corner does not. It is drawn from `is_unread`, which the server computes per
 * reader — one resident acknowledging a notice does not mark it read for the
 * dormitory.
 *
 * **Acknowledgement is idempotent, and the button says so by disappearing.**
 * The route answers 200 on the first call as on the twentieth and returns the
 * acknowledgement already on record, so the screen has nothing to guard against
 * — a second press writes no second row. What it does instead is show the
 * moment the notice was read, because that moment is the evidence FR-12 is
 * about.
 *
 * **Any announcement may be acknowledged, not only a mandatory one**: the same
 * row is what clears the unread mark. The obligation changes the wording and
 * the prominence, not the availability.
 */
export function AnnouncementCard({
  announcement,
  readersLink,
}: {
  announcement: Announcement
  readersLink: boolean
}) {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const refresh = useAnnouncementRefresh()
  const acknowledge = useAcknowledgeAnnouncement<ApiError>()

  const unread = announcement.is_unread
  const read = acknowledged(announcement)
  const mustAcknowledge = awaitsAcknowledgement(announcement)

  return (
    <article
      className={cn(
        'grid gap-3 border-l-4 px-4 py-4',
        unread ? 'border-prussian bg-prussian-wash/40' : 'border-transparent',
      )}
    >
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h3 className="m-0 min-w-0 text-lg font-semibold break-words text-ink">
          {announcement.title}
        </h3>
        {unread ? (
          <span className="inline-block border border-prussian bg-prussian px-2 py-0.5 font-medium text-white">
            {t('announcements.unread')}
          </span>
        ) : null}
      </div>

      <div className="flex flex-wrap items-baseline gap-2">
        <span
          className={cn(
            'inline-block border px-2 py-0.5 font-medium',
            CATEGORY_TONE[announcement.category] ?? 'border-rule bg-paper text-steel',
          )}
        >
          {t(`announcementCategory.${announcement.category}`, {
            defaultValue: announcement.category_label ?? announcement.category,
          })}
        </span>
        {/*
          FR-12's flag, drawn beside the category and never as one of them. The
          subject of a notice and the obligation to read it vary independently —
          two columns in the model, two marks on the screen.
        */}
        {announcement.is_mandatory ? (
          <span className="inline-block border border-brick/40 bg-brick-wash px-2 py-0.5 font-medium text-brick">
            {t('announcements.mandatory')}
          </span>
        ) : null}
        <span className="inline-block border border-rule bg-paper px-2 py-0.5 text-steel">
          {announcement.addresses_every_building === true
            ? t('announcements.everyBuilding')
            : (announcement.building_name ??
              t('roles.scopeBuilding', { id: announcement.building_id }))}
        </span>
      </div>

      <p className="m-0 whitespace-pre-line break-words text-ink">{announcement.body}</p>

      <dl className="m-0 grid gap-x-4 gap-y-1 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)]">
        <dt className="label-caps">{t('announcements.fields.published')}</dt>
        <dd className="m-0 text-ink">
          {formatters.dateTime(announcement.published_at)}
          {announcement.author_name === undefined
            ? null
            : ` · ${announcement.author_name}`}
        </dd>
        <dt className="label-caps">{t('announcements.fields.expires')}</dt>
        <dd className="m-0 text-ink">
          {announcement.expires_at === null || announcement.expires_at === undefined
            ? t('announcements.neverExpires')
            : formatters.dateTime(announcement.expires_at)}
        </dd>
        {read ? (
          <>
            <dt className="label-caps">{t('announcements.fields.acknowledged')}</dt>
            <dd className="m-0 text-ink">
              {formatters.dateTime(announcement.acknowledged_at)}
            </dd>
          </>
        ) : null}
      </dl>

      {acknowledge.isError ? <RequestRefusal error={acknowledge.error} /> : null}

      {mustAcknowledge ? (
        <p className="m-0 border-l-4 border-brick bg-brick-wash px-3 py-2 text-ink">
          {t('announcements.mandatoryNote')}
        </p>
      ) : null}

      <div className="flex flex-wrap gap-2">
        {read ? null : (
          <Button
            type="button"
            size="lg"
            variant={announcement.is_mandatory ? 'default' : 'outline'}
            className="h-auto min-h-12 min-w-0 whitespace-normal"
            disabled={acknowledge.isPending}
            onClick={() =>
              acknowledge.mutate(
                { announcement: announcement.id },
                { onSuccess: () => refresh() },
              )
            }
          >
            {acknowledge.isPending
              ? `${t('common.saving')}…`
              : announcement.is_mandatory
                ? t('announcements.acknowledgeMandatory')
                : t('announcements.acknowledge')}
          </Button>
        )}

        {/*
          FR-12's second criterion is a screen of personal data — the residents
          who have not complied with an instruction — so the way into it is
          drawn only for accounts that hold the publishing capability here. The
          route stays reachable and the server refuses everybody else with 403,
          recorded as `access.denied` (§3.3.2).
        */}
        {readersLink ? (
          <Button
            asChild
            variant="outline"
            size="lg"
            className="h-auto min-h-12 min-w-0 whitespace-normal"
          >
            <Link to={`/announcements/${announcement.id}/readers`}>
              {t('announcements.readersLink')}
            </Link>
          </Button>
        ) : null}
      </div>
    </article>
  )
}

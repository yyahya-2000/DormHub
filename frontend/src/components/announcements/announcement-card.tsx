import type { ReactNode } from 'react'
import { Building2, CalendarClock, CalendarX, UserRound, type LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'

import type { Announcement } from '@/api/generated/model'
import { AnnouncementBody } from '@/components/announcements/announcement-body'
import { categoryLabel } from '@/lib/announcements'
import { useFormatters } from '@/lib/format'

/**
 * One announcement in the feed (FR-11).
 *
 * The card is read top down and in one width: what it is about, what it says,
 * and who put it there. The dates carry no printed caption — an icon and the
 * date itself, with the caption in `title` and for a screen reader — because
 * two words in capitals above every timestamp were louder than the timestamps.
 *
 * The expiry is shown to the people who set it and to nobody else. A resident
 * reading the feed is being told what is in force now; a date after which the
 * notice will quietly leave the feed is a fact about the feed's machinery and
 * not about the water being off on Thursday.
 */
export function AnnouncementCard({
  announcement,
  showsExpiry,
}: {
  announcement: Announcement
  /** True for the accounts that publish: they set the expiry, so they read it. */
  showsExpiry: boolean
}) {
  const { t } = useTranslation()
  const formatters = useFormatters()

  const building =
    announcement.addresses_every_building === true
      ? t('announcements.everyBuilding')
      : (announcement.building_name ??
        t('roles.scopeBuilding', { id: announcement.building_id }))

  const expires =
    announcement.expires_at === null || announcement.expires_at === undefined
      ? null
      : announcement.expires_at

  return (
    <article className="grid min-w-0 gap-4 border border-rule bg-paper-raised px-5 py-6 sm:px-6">
      <div className="min-w-0">
        <span className="inline-block max-w-full border border-rule bg-paper px-2 py-0.5 break-words text-steel">
          {categoryLabel(announcement)}
        </span>
      </div>

      <h3 className="m-0 min-w-0 text-xl font-semibold break-words text-ink">
        {announcement.title}
      </h3>

      <AnnouncementBody body={announcement.body} />

      <footer className="flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-rule/70 pt-4 text-steel">
        <Stamp icon={CalendarClock} caption={t('announcements.fields.published')}>
          <time dateTime={announcement.published_at}>
            {formatters.dateTime(announcement.published_at)}
          </time>
        </Stamp>

        {announcement.author_name === undefined ? null : (
          <Stamp icon={UserRound} caption={t('announcements.fields.author')}>
            {announcement.author_name}
          </Stamp>
        )}

        <Stamp icon={Building2} caption={t('announcements.fields.building')}>
          {building}
        </Stamp>

        {showsExpiry ? (
          <Stamp icon={CalendarX} caption={t('announcements.fields.expires')}>
            {expires === null ? (
              t('announcements.neverExpires')
            ) : (
              <time dateTime={expires}>{formatters.dateTime(expires)}</time>
            )}
          </Stamp>
        ) : null}
      </footer>
    </article>
  )
}

/**
 * An icon, and the fact beside it. What the fact is stays in `title` for the
 * pointer and in `sr-only` for assistive software: the meaning is not thrown
 * away with the caption, only taken out of the reading line.
 */
function Stamp({
  icon: Icon,
  caption,
  children,
}: {
  icon: LucideIcon
  caption: string
  children: ReactNode
}) {
  return (
    <span className="inline-flex min-w-0 items-center gap-2" title={caption}>
      <Icon aria-hidden="true" className="size-5 shrink-0" />
      <span className="sr-only">{caption}: </span>
      <span className="min-w-0 break-words">{children}</span>
    </span>
  )
}

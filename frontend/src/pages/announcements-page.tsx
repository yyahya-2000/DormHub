import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  useListAnnouncements,
  type listAnnouncementsResponse,
} from '@/api/generated/dormitory'
import type { AnnouncementCategory } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { announcementBuildingsOf, isSystemAdministrator } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { AnnouncementCard } from '@/components/announcements/announcement-card'
import { FormField, selectClassName } from '@/components/form-field'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { ANNOUNCEMENT_CATEGORIES, awaitsAcknowledgement } from '@/lib/announcements'
import { useFormatters } from '@/lib/format'
import { cn } from '@/lib/utils'

/**
 * FR-11, the feed — the announcements of the dormitories this account is
 * attached to, plus those addressed to every one of them.
 *
 * **There is no building chooser on this screen, and the absence is the point.**
 * The route carries no building parameter: the audience is computed from the
 * grants of the token, so there is no way to phrase a request for another
 * dormitory's feed. FR-07's horizontal boundary here is a missing parameter
 * rather than a check somebody has to remember to write, and a control that
 * offered a building would be the first step towards putting the parameter
 * back. FR-05 rides on top of it — a resident whose departure date has passed
 * stops seeing that dormitory's notices, and neither this screen nor the route
 * has to know it.
 *
 * **Nothing archives an announcement.** There is no archiving job and no status
 * column; the archive is the other side of the `expires_at` comparison, which
 * is why the control below is a switch between two readings of one query rather
 * than a filter over a state. An expired notice leaves the feed the moment the
 * clock passes it, with nobody moving it.
 *
 * **The unread mark is per reader.** `is_unread` is a left join against the
 * acknowledgements, so one resident reading a notice does not mark it read for
 * the corridor. The count of outstanding mandatory notices is drawn from the
 * page in hand and says so — it is what is on this page, not a total.
 */
export function AnnouncementsPage() {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const { session } = useSession()

  const user = session.status === 'authenticated' ? session.user : null
  /*
   * The way into the publication form, and into the readers report on each
   * card. Drawn from the capability and from nothing else: an account that
   * holds it in any dormitory has somewhere to publish, and the administrator —
   * whose grant names no building — is asked about separately, because
   * `buildingsWith` returns nothing for a grant that names none.
   */
  const publishes =
    user !== null && (announcementBuildingsOf(user).length > 0 || isSystemAdministrator(user))

  const [category, setCategory] = useState<AnnouncementCategory | 'all'>('all')
  const [archived, setArchived] = useState(false)
  const [page, setPage] = useState(1)

  const feed = useListAnnouncements<listAnnouncementsResponse, ApiError>(
    {
      ...(category === 'all' ? {} : { category }),
      ...(archived ? { archived: true } : {}),
      page,
    },
    { query: { retry: false } },
  )

  const payload = feed.data?.status === 200 ? feed.data.data : null
  const rows = payload?.data ?? null
  const meta = payload?.meta
  const outstanding = (rows ?? []).filter((row) => awaitsAcknowledgement(row)).length

  function reread(next: { category?: AnnouncementCategory | 'all'; archived?: boolean }) {
    if (next.category !== undefined) {
      setCategory(next.category)
    }
    if (next.archived !== undefined) {
      setArchived(next.archived)
    }
    setPage(1)
  }

  return (
    <div className="grid grid-cols-1 gap-8">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">{t('announcements.heading')}</h1>
        <p className="mt-1 text-steel">{t('announcements.lead')}</p>
      </div>

      {publishes ? (
        <div className="flex flex-wrap gap-2">
          <Button asChild size="lg" className="h-auto min-h-12 min-w-0 whitespace-normal">
            <Link to="/announcements/new">{t('announcements.publishLink')}</Link>
          </Button>
        </div>
      ) : null}

      {/*
        FR-12 on the feed rather than only on the card: a mandatory notice left
        unacknowledged is an obligation outstanding, and burying it among a
        dozen ordinary ones is how it stays outstanding. Counted over the page
        in hand, and worded so.
      */}
      {outstanding > 0 ? (
        <section className="border-l-4 border-brick bg-brick-wash px-4 py-3" role="status">
          <p className="m-0 text-ink">
            {t('announcements.outstanding', { count: outstanding })}
          </p>
        </section>
      ) : null}

      <Panel caption={t('announcements.filterHeading')}>
        <div className="grid gap-4 px-4 py-4 sm:grid-cols-2">
          <FormField
            id="announcement-category"
            label={t('announcements.filterCategory')}
          >
            <select
              id="announcement-category"
              className={selectClassName}
              value={category}
              onChange={(event) =>
                reread({ category: event.target.value as AnnouncementCategory | 'all' })
              }
            >
              <option value="all">{t('announcements.filterAll')}</option>
              {ANNOUNCEMENT_CATEGORIES.map((value) => (
                <option key={value} value={value}>
                  {t(`announcementCategory.${value}`)}
                </option>
              ))}
            </select>
          </FormField>

          <fieldset className="m-0 grid min-w-0 gap-1 border-0 p-0">
            <legend className="label-caps p-0">{t('announcements.filterWhich')}</legend>
            <div className="flex flex-wrap gap-2">
              <Button
                type="button"
                variant={archived ? 'outline' : 'default'}
                className={cn('h-auto min-h-11 min-w-0 whitespace-normal')}
                aria-pressed={!archived}
                onClick={() => reread({ archived: false })}
              >
                {t('announcements.current')}
              </Button>
              <Button
                type="button"
                variant={archived ? 'default' : 'outline'}
                className={cn('h-auto min-h-11 min-w-0 whitespace-normal')}
                aria-pressed={archived}
                onClick={() => reread({ archived: true })}
              >
                {t('announcements.archive')}
              </Button>
            </div>
            <p className="m-0 text-steel">{t('announcements.archiveNote')}</p>
          </fieldset>
        </div>
      </Panel>

      <Panel
        caption={archived ? t('announcements.archive') : t('announcements.feedHeading')}
        aside={
          rows !== null ? t('announcements.feedCount', { count: rows.length }) : undefined
        }
      >
        {feed.isError ? (
          <div className="px-4 py-4">
            <RequestRefusal error={feed.error} />
          </div>
        ) : null}

        {feed.isPending && !feed.isError ? (
          <div className="grid gap-2 px-4 py-4" aria-hidden="true">
            <Skeleton className="h-32 w-full" />
            <Skeleton className="h-32 w-full" />
          </div>
        ) : null}

        {rows !== null && rows.length === 0 ? (
          <p className="px-4 py-6 text-steel">
            {archived ? t('announcements.emptyArchive') : t('announcements.empty')}
          </p>
        ) : null}

        {rows !== null && rows.length > 0 ? (
          <ul className="m-0 list-none p-0">
            {rows.map((row) => (
              <li key={row.id} className="border-b border-rule/70 last:border-b-0">
                <AnnouncementCard announcement={row} readersLink={publishes} />
              </li>
            ))}
          </ul>
        ) : null}

        {meta !== undefined && (meta.last_page ?? 1) > 1 ? (
          <div className="flex flex-wrap items-center justify-between gap-3 border-t border-rule px-4 py-3">
            <span className="text-steel">
              {t('notifications.page', {
                current: formatters.count(meta.current_page),
                total: formatters.count(meta.last_page),
              })}
            </span>
            <div className="flex gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={(meta.current_page ?? 1) <= 1 || feed.isFetching}
                onClick={() => setPage((current) => Math.max(1, current - 1))}
              >
                {t('notifications.previous')}
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={
                  (meta.current_page ?? 1) >= (meta.last_page ?? 1) || feed.isFetching
                }
                onClick={() => setPage((current) => current + 1)}
              >
                {t('notifications.next')}
              </Button>
            </div>
          </div>
        ) : null}
      </Panel>
    </div>
  )
}

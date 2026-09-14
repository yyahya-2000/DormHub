import { useState } from 'react'
import { Link } from 'react-router-dom'
import { keepPreviousData } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useTranslation } from 'react-i18next'

import {
  useListAnnouncements,
  type listAnnouncementsResponse,
} from '@/api/generated/dormitory'
import type { ApiError } from '@/api/http-client'
import { announcementBuildingsOf, isSystemAdministrator } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { AnnouncementCard } from '@/components/announcements/announcement-card'
import { FormField, selectClassName } from '@/components/form-field'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Pagination } from '@/components/ui/pagination'
import { Skeleton } from '@/components/ui/skeleton'
import { useAnnouncementCategories } from '@/hooks/use-announcement-categories'
import { lastPageOf, usePagination } from '@/hooks/use-pagination'
import { cn } from '@/lib/utils'

/**
 * FR-11, the feed: the announcements of the dormitories this account is
 * attached to. The audience is computed from the token, so the route carries no
 * building parameter and this screen offers no building chooser.
 *
 * The list is a column of separated cards rather than rows ruled off from one
 * another: one notice is one thing to read, and the space between them is what
 * says so.
 */
export function AnnouncementsPage() {
  const { t } = useTranslation()
  const { session } = useSession()

  const user = session.status === 'authenticated' ? session.user : null
  const publishes =
    user !== null && (announcementBuildingsOf(user).length > 0 || isSystemAdministrator(user))

  // The empty string is «every category»: a category is a free string now, so
  // the sentinel has to be a value the field itself can never hold.
  const [category, setCategory] = useState('')
  const [archived, setArchived] = useState(false)
  const paging = usePagination({ resetKey: `${category}:${String(archived)}` })

  const categories = useAnnouncementCategories()

  const feed = useListAnnouncements<listAnnouncementsResponse, ApiError>(
    {
      ...(category === '' ? {} : { category }),
      ...(archived ? { archived: true } : {}),
      page: paging.page,
    },
    { query: { retry: false, placeholderData: keepPreviousData } },
  )

  const payload = feed.data?.status === 200 ? feed.data.data : null
  const rows = payload?.data ?? null

  return (
    <div className="grid grid-cols-1 gap-8">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">{t('announcements.heading')}</h1>
      </div>

      {publishes ? (
        <div className="flex flex-wrap gap-2">
          <Button asChild size="lg" className="h-auto min-h-12 min-w-0 whitespace-normal">
            <Link to="/announcements/new">
              <Plus aria-hidden="true" className="size-5" />
              {t('announcements.publishLink')}
            </Link>
          </Button>
        </div>
      ) : null}

      <Panel caption={t('announcements.filterHeading')}>
        <div className="grid gap-4 px-4 py-4 sm:grid-cols-2">
          <FormField id="announcement-category" label={t('announcements.filterCategory')}>
            <select
              id="announcement-category"
              className={selectClassName}
              value={category}
              onChange={(event) => setCategory(event.target.value)}
            >
              <option value="">{t('announcements.filterAll')}</option>
              {categories.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
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
                onClick={() => setArchived(false)}
              >
                {t('announcements.current')}
              </Button>
              <Button
                type="button"
                variant={archived ? 'default' : 'outline'}
                className={cn('h-auto min-h-11 min-w-0 whitespace-normal')}
                aria-pressed={archived}
                onClick={() => setArchived(true)}
              >
                {t('announcements.archive')}
              </Button>
            </div>
          </fieldset>
        </div>
      </Panel>

      <section className="grid min-w-0 gap-6">
        <h2 className="label-caps m-0 border-b border-rule pb-2">
          {archived ? t('announcements.archive') : t('announcements.feedHeading')}
        </h2>

        {feed.isError ? <RequestRefusal error={feed.error} /> : null}

        {feed.isPending && !feed.isError ? (
          <div className="grid gap-6" aria-hidden="true">
            <Skeleton className="h-44 w-full" />
            <Skeleton className="h-44 w-full" />
          </div>
        ) : null}

        {rows !== null && rows.length === 0 ? (
          <p className="m-0 border border-rule bg-paper-raised px-5 py-8 text-steel">
            {archived ? t('announcements.emptyArchive') : t('announcements.empty')}
          </p>
        ) : null}

        {rows !== null && rows.length > 0 ? (
          <ul className="m-0 grid list-none gap-6 p-0 sm:gap-8">
            {rows.map((row) => (
              <li key={row.id} className="min-w-0">
                <AnnouncementCard announcement={row} showsExpiry={publishes} />
              </li>
            ))}
          </ul>
        ) : null}

        <Pagination
          page={paging.page}
          lastPage={lastPageOf(payload?.meta)}
          onPageChange={paging.setPage}
          disabled={feed.isFetching}
        />
      </section>
    </div>
  )
}

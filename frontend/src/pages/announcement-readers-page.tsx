import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  useShowAnnouncementReaders,
  type showAnnouncementReadersResponse,
} from '@/api/generated/dormitory'
import type { AnnouncementReader } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { sharePercent } from '@/lib/announcements'
import { useFormatters } from '@/lib/format'

/**
 * FR-12, second criterion: the acknowledged share, and a named list of those who
 * have not read — **within the caller's own dormitory**.
 *
 * **This screen shows personal data assembled for a purpose**, which is the
 * residents who have not complied with an instruction, so it says so at the top
 * rather than presenting a roll of names as an ordinary table. The narrowing is
 * the server's and is done twice: the caller must hold the publishing capability
 * in the dormitory the announcement names, and for a notice addressed to every
 * dormitory the names are cut back to the buildings the caller's own grant
 * covers. `building_ids` says which dormitories the figures are about, and this
 * page repeats it — a share of 60 % means nothing without «of whom».
 *
 * **The share is read, never divided out here.** The audience is the register's
 * count less those it says have moved out (FR-05), and the two lists below are
 * the caller's own scope; dividing one by the other would print a different
 * number from the one the server computed. An empty audience arrives as 1 —
 * «nobody to chase» — rather than as a nought in red.
 *
 * **Reading this list is itself an event of the audit log**
 * (`announcement.readers_viewed`), and the page says so, because a screen that
 * records its own reader ought to admit it.
 */
export function AnnouncementReadersPage() {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const params = useParams<{ announcementId: string }>()
  const announcementId = Number(params.announcementId)

  const report = useShowAnnouncementReaders<showAnnouncementReadersResponse, ApiError>(
    announcementId,
    { query: { enabled: Number.isInteger(announcementId), retry: false } },
  )

  const data = report.data?.status === 200 ? report.data.data.data : null
  const scope = data?.building_ids ?? []

  return (
    <div className="grid grid-cols-1 gap-8">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">
          {t('announcements.readersHeading')}
        </h1>
        <p className="mt-1 break-words text-steel">
          {data?.title ?? t('announcements.readersLead')}
        </p>
      </div>

      <div className="flex flex-wrap gap-2">
        <Button asChild variant="outline" className="h-auto min-h-11 min-w-0 whitespace-normal">
          <Link to="/announcements">{t('announcements.backToFeed')}</Link>
        </Button>
      </div>

      {report.isError ? <RequestRefusal error={report.error} /> : null}

      {report.isPending && !report.isError ? (
        <div className="grid gap-2" aria-hidden="true">
          <Skeleton className="h-28 w-full" />
          <Skeleton className="h-48 w-full" />
        </div>
      ) : null}

      {data !== null ? (
        <>
          <Panel caption={t('announcements.shareHeading')}>
            <div className="grid gap-3 px-4 py-4">
              <p className="m-0 text-2xl font-semibold text-prussian">
                {t('announcements.sharePercent', {
                  percent: formatters.count(sharePercent(data.acknowledged_share)),
                })}
              </p>
              {/*
                A bar rather than a ring: a straight run of colour reads at a
                glance and, at 360 px, does not compete with the names below it
                for the width they need.
              */}
              <div
                className="h-3 w-full border border-rule bg-paper"
                role="img"
                aria-label={t('announcements.shareOf', {
                  read: formatters.count(data.acknowledged_count),
                  audience: formatters.count(data.audience_size),
                })}
              >
                <div
                  className="h-full bg-prussian"
                  style={{ width: `${sharePercent(data.acknowledged_share)}%` }}
                />
              </div>
              <p className="m-0 text-ink">
                {t('announcements.shareOf', {
                  read: formatters.count(data.acknowledged_count),
                  audience: formatters.count(data.audience_size),
                })}
              </p>
              <p className="m-0 text-steel">
                {scope.length === 0
                  ? t('announcements.scopeAll')
                  : t('announcements.scopeBuildings', {
                      buildings: scope
                        .map((id) => t('roles.scopeBuilding', { id }))
                        .join(', '),
                    })}
              </p>
              <p className="m-0 text-steel">{t('announcements.readersAudited')}</p>
            </div>
          </Panel>

          <Panel
            caption={t('announcements.notReadHeading')}
            aside={t('announcements.readerCount', { count: data.not_acknowledged.length })}
          >
            <p className="m-0 border-b border-rule/70 px-4 py-3 text-steel">
              {t('announcements.notReadNote')}
            </p>
            {data.not_acknowledged.length === 0 ? (
              <p className="px-4 py-6 text-steel">{t('announcements.everybodyRead')}</p>
            ) : (
              <ReaderList readers={data.not_acknowledged} />
            )}
          </Panel>

          <Panel
            caption={t('announcements.readHeading')}
            aside={t('announcements.readerCount', { count: data.acknowledged.length })}
          >
            {data.acknowledged.length === 0 ? (
              <p className="px-4 py-6 text-steel">{t('announcements.nobodyRead')}</p>
            ) : (
              <ReaderList readers={data.acknowledged} withTime />
            )}
          </Panel>
        </>
      ) : null}
    </div>
  )
}

/**
 * A list and not a table: two facts per person, one of which is often absent,
 * and a column head over a single name is noise at 360 px.
 */
function ReaderList({
  readers,
  withTime = false,
}: {
  readers: AnnouncementReader[]
  withTime?: boolean
}) {
  const formatters = useFormatters()

  return (
    <ul className="m-0 list-none p-0">
      {readers.map((reader) => (
        <li
          key={reader.user_id}
          className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-rule/70 px-4 py-3 last:border-b-0"
        >
          <span className="min-w-0 break-words text-ink">{reader.full_name}</span>
          {withTime && reader.acknowledged_at !== undefined ? (
            <time className="text-steel" dateTime={reader.acknowledged_at}>
              {formatters.dateTime(reader.acknowledged_at)}
            </time>
          ) : null}
        </li>
      ))}
    </ul>
  )
}

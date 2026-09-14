import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  useListNotifications,
  useMarkNotificationRead,
  type listNotificationsResponse,
} from '@/api/generated/dormitory'
import type { Notification } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { NotificationMessage } from '@/components/notification-message'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { useAccountRefresh } from '@/lib/account-cache'
import { useFormatters } from '@/lib/format'
import { ClaimReferral } from '@/components/lost-found/claim-referral'
import { categoryKeyOf, isUnread } from '@/lib/notifications'
import { NotificationType } from '@/lib/notifications'
import { cn } from '@/lib/utils'

/**
 * FR-34, the in-app half: the messages of one personal account.
 *
 * The route is scoped to the token and takes no parameter naming anyone else,
 * so there is no capability to ask `auth/navigation.ts` about and no tab to
 * hide — everyone who can sign in has a personal account. §3.3.2 still holds:
 * the server decides, and what it decides here is that the account the token
 * names is the only account there is.
 *
 * **Unread is a filter, not a sort.** The list arrives newest first and stays
 * that way in both positions of the filter, because a message read this morning
 * and one read in March are not usefully ordered by when they were opened. What
 * the filter changes is which rows the server sends, and it sends them: the
 * client never partitions a page it already has, or the count in the title bar
 * — which comes from `meta.total` of the same query — would disagree with the
 * rows underneath it as soon as the list ran past its first page.
 *
 * Marking read is deliberate and singular. There is no «mark everything read»,
 * because the API has no route for one and inventing a loop of twenty-five
 * requests to stand in for it would be a bulk action with none of a bulk
 * action's guarantees: a failure halfway through leaves a state nobody asked
 * for and the screen cannot describe.
 */
export function NotificationsPage() {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const [unreadOnly, setUnreadOnly] = useState(false)
  const [page, setPage] = useState(1)

  const notifications = useListNotifications<listNotificationsResponse, ApiError>(
    { unread: unreadOnly, page },
    { query: { retry: false } },
  )

  const payload = notifications.data?.status === 200 ? notifications.data.data : null
  const rows = payload?.data ?? []
  const meta = payload?.meta

  function choose(filter: boolean) {
    setUnreadOnly(filter)
    setPage(1)
  }

  return (
    <div className="grid grid-cols-1 gap-6">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">{t('notifications.heading')}</h1>
        <p className="mt-1 text-steel">{t('notifications.lead')}</p>
      </div>

      <nav aria-label={t('notifications.filterLabel')}>
        <ul className="m-0 flex list-none flex-wrap gap-2 p-0">
          <li>
            <Button
              type="button"
              variant={unreadOnly ? 'outline' : 'default'}
              size="sm"
              aria-pressed={!unreadOnly}
              onClick={() => choose(false)}
            >
              {t('notifications.filterAll')}
            </Button>
          </li>
          <li>
            <Button
              type="button"
              variant={unreadOnly ? 'default' : 'outline'}
              size="sm"
              aria-pressed={unreadOnly}
              onClick={() => choose(true)}
            >
              {t('notifications.filterUnread')}
            </Button>
          </li>
          <li>
            <Link
              className="inline-block border border-rule bg-paper-raised px-3 py-2 font-medium text-prussian underline-offset-4 hover:underline"
              to="/notifications/settings"
            >
              {t('notifications.toSettings')}
            </Link>
          </li>
        </ul>
      </nav>

      {notifications.isError ? <RequestRefusal error={notifications.error} /> : null}

      {notifications.isError ? null : (
        <Panel
          className="min-w-0"
          caption={
            unreadOnly ? t('notifications.captionUnread') : t('notifications.captionAll')
          }
          aside={
            meta?.total === undefined
              ? undefined
              : t('notifications.total', { count: meta.total })
          }
        >
          {notifications.isPending ? (
            <div className="grid gap-2 px-4 py-4" aria-hidden="true">
              <Skeleton className="h-16 w-full" />
              <Skeleton className="h-16 w-full" />
            </div>
          ) : null}

          {!notifications.isPending && rows.length === 0 ? (
            <p className="px-4 py-6 text-steel">
              {unreadOnly ? t('notifications.emptyUnread') : t('notifications.empty')}
            </p>
          ) : null}

          {rows.length > 0 ? (
            <ul className="m-0 list-none p-0">
              {rows.map((row) => (
                <NotificationRow key={row.id} notification={row} />
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
                  disabled={(meta.current_page ?? 1) <= 1 || notifications.isFetching}
                  onClick={() => setPage((current) => Math.max(1, current - 1))}
                >
                  {t('notifications.previous')}
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={
                    (meta.current_page ?? 1) >= (meta.last_page ?? 1) ||
                    notifications.isFetching
                  }
                  onClick={() => setPage((current) => current + 1)}
                >
                  {t('notifications.next')}
                </Button>
              </div>
            </div>
          ) : null}

          {/*
            The one category that is listed on the settings screen and never
            appears in this list. The credential of FR-42 goes out by mail and
            leaves no row behind — a one-time secret written into a table is a
            secret that outlives its use — so a person looking here for the code
            they were sent is told where it went instead of finding nothing.
          */}
          <p className="border-t border-rule px-4 py-3 text-steel">
            {t('notifications.credentialNote')}
          </p>
        </Panel>
      )}
    </div>
  )
}

function NotificationRow({ notification }: { notification: Notification }) {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const refresh = useAccountRefresh()
  const markRead = useMarkNotificationRead<ApiError>()

  const unread = isUnread(notification)
  const category = categoryKeyOf(notification)
  const lostFound =
    notification.type === NotificationType.lostFoundClaimFiled ||
    notification.type === NotificationType.lostFoundClaimDecided

  return (
    <li
      className={cn(
        'grid gap-2 border-b border-rule/70 px-4 py-4 last:border-b-0',
        // Unread is carried by a rule down the left edge and by a word, never
        // by colour alone: the security post reads this on whatever monitor is
        // bolted to the desk, and one of them is a decade old.
        unread ? 'border-l-4 border-l-prussian bg-prussian-wash/40' : null,
      )}
    >
      <div className="flex min-w-0 flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <span className="label-caps">
          {category === null
            ? t('notifications.noCategory')
            : t(`notificationCategory.${category}.label`, {
                defaultValue: t('notifications.noCategory'),
              })}
        </span>
        <span className="text-steel">{formatters.dateTime(notification.created_at)}</span>
      </div>

      <NotificationMessage notification={notification} />

      {/*
        FR-26's offer, and the one action in this list that is not «mark read».
        A claimant cannot read the claims on an entry, so the message is the
        only place the decision on their own claim reaches them — and the only
        place from which they can put a refusal to the warden.
      */}
      {lostFound ? <ClaimReferral notification={notification} /> : null}

      <div className="flex flex-wrap items-center gap-3">
        {unread ? (
          <>
            <span className="inline-block border border-prussian/40 bg-prussian-wash px-2 py-0.5 font-medium text-prussian">
              {t('notifications.unread')}
            </span>
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={markRead.isPending}
              onClick={() =>
                markRead.mutate(
                  { notification: notification.id },
                  { onSuccess: () => refresh() },
                )
              }
            >
              {markRead.isPending ? `${t('common.saving')}…` : t('notifications.markRead')}
            </Button>
          </>
        ) : (
          <span className="text-steel">
            {t('notifications.readAt', {
              time: formatters.dateTime(notification.read_at),
            })}
          </span>
        )}
      </div>

      {markRead.isError ? <RequestRefusal error={markRead.error} /> : null}
    </li>
  )
}

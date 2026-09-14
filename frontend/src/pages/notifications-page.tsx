import { useId, useState } from 'react'
import { keepPreviousData } from '@tanstack/react-query'
import { ChevronDown, ChevronUp } from 'lucide-react'
import { useTranslation } from 'react-i18next'

import {
  useListNotifications,
  useMarkNotificationRead,
  type listNotificationsResponse,
} from '@/api/generated/dormitory'
import type { Notification } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { ClaimReferral } from '@/components/lost-found/claim-referral'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Pagination } from '@/components/ui/pagination'
import { Skeleton } from '@/components/ui/skeleton'
import { useNotificationMessage } from '@/hooks/use-notification-message'
import { lastPageOf, usePagination } from '@/hooks/use-pagination'
import { useAccountRefresh } from '@/lib/account-cache'
import { useFormatters } from '@/lib/format'
import { NotificationType, categoryKeyOf, isUnread } from '@/lib/notifications'
import { cn } from '@/lib/utils'

/**
 * FR-34, the in-app half: the messages of one personal account.
 *
 * One list, newest first, and no way to narrow it. The screen had a filter over
 * read and unread and a second screen of category switches behind it; both are
 * gone. A dormitory account collects a few messages a week, and a list that
 * short is read by scrolling — a filter over it only asks the reader to state
 * what they were going to see anyway.
 *
 * What is left in its place is the row itself. An unopened message is marked,
 * opening it shows the rest of the text, and opening it is what marks it read:
 * there is no button for that any more, because pressing one after having just
 * read the message is a second statement of something the first already said.
 */
export function NotificationsPage() {
  const { t } = useTranslation()
  const paging = usePagination()

  const notifications = useListNotifications<listNotificationsResponse, ApiError>(
    { page: paging.page },
    { query: { retry: false, placeholderData: keepPreviousData } },
  )

  const payload = notifications.data?.status === 200 ? notifications.data.data : null
  const rows = payload?.data ?? []
  const meta = payload?.meta

  return (
    <div className="grid grid-cols-1 gap-6">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">{t('notifications.heading')}</h1>
      </div>

      {notifications.isError ? <RequestRefusal error={notifications.error} /> : null}

      {notifications.isError ? null : (
        <Panel
          className="min-w-0"
          caption={t('notifications.caption')}
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
            <p className="px-4 py-6 text-steel">{t('notifications.empty')}</p>
          ) : null}

          {rows.length > 0 ? (
            <ul className="m-0 list-none p-0">
              {rows.map((row) => (
                <NotificationRow key={row.id} notification={row} />
              ))}
            </ul>
          ) : null}

          <Pagination
            page={paging.page}
            lastPage={lastPageOf(meta)}
            onPageChange={paging.setPage}
            disabled={notifications.isFetching}
          />
        </Panel>
      )}
    </div>
  )
}

/**
 * One row: category, headline and date folded, the text of the message and
 * whatever it offers to do unfolded.
 *
 * The fold follows `announcement-body.tsx` — a button carrying `aria-expanded`
 * and `aria-controls`, and a body that stays in the document either way, so the
 * control and the thing it controls are one pair rather than two states.
 *
 * Unread is said four times over and only once in colour: the row is tinted
 * and ruled down its left edge in `prussian`, the headline is set bold, a
 * square sits before the category, and the word itself is there for a screen
 * reader. Colour alone would leave a reader who cannot separate the two tints
 * with no difference at all.
 *
 * Opening marks the message read, and the mutation is fired once: `isUnread`
 * still answers true until the list comes back from the server, so a second
 * click on a row opened a moment ago would otherwise send a second request.
 */
function NotificationRow({ notification }: { notification: Notification }) {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const refresh = useAccountRefresh()
  const markRead = useMarkNotificationRead<ApiError>()

  const [open, setOpen] = useState(false)
  const bodyId = useId()

  const message = useNotificationMessage(notification)
  const unread = isUnread(notification) && !markRead.isSuccess && !markRead.isPending
  const category = categoryKeyOf(notification)
  const lostFound =
    notification.type === NotificationType.lostFoundClaimFiled ||
    notification.type === NotificationType.lostFoundClaimDecided

  function toggle() {
    const next = !open
    setOpen(next)
    if (next && unread) {
      markRead.mutate({ notification: notification.id }, { onSuccess: () => refresh() })
    }
  }

  return (
    <li
      className={cn(
        'border-b border-rule/70 last:border-b-0',
        unread ? 'border-l-4 border-l-prussian bg-prussian-wash/40' : null,
      )}
    >
      <button
        type="button"
        className="flex w-full min-w-0 items-start justify-between gap-3 px-4 py-4 text-left hover:bg-prussian-wash/60"
        aria-expanded={open}
        aria-controls={bodyId}
        onClick={toggle}
      >
        <span className="grid min-w-0 gap-1">
          <span className="label-caps">
            {unread ? (
              <>
                <span
                  aria-hidden="true"
                  className="mr-2 inline-block size-2.5 bg-prussian align-baseline"
                />
                <span className="sr-only">{t('notifications.unread')}: </span>
              </>
            ) : null}
            {category === null
              ? t('notifications.noCategory')
              : t(`notificationCategory.${category}.label`, {
                  defaultValue: t('notifications.noCategory'),
                })}
          </span>

          <span
            className={cn(
              'min-w-0 break-words text-ink',
              unread ? 'font-semibold' : 'font-normal',
            )}
          >
            {message.headline}
          </span>

          <span className="text-steel">{formatters.dateTime(notification.created_at)}</span>
        </span>

        {open ? (
          <ChevronUp aria-hidden="true" className="mt-1 size-5 shrink-0 text-steel" />
        ) : (
          <ChevronDown aria-hidden="true" className="mt-1 size-5 shrink-0 text-steel" />
        )}
      </button>

      <div id={bodyId} className={cn('min-w-0 gap-2 px-4 pb-4', open ? 'grid' : 'hidden')}>
        {message.details.length > 0 ? (
          message.details.map((line) => (
            <p key={line} className="m-0 break-words text-ink">
              {line}
            </p>
          ))
        ) : (
          <p className="m-0 text-steel">{t('notifications.noDetail')}</p>
        )}

        {lostFound ? <ClaimReferral notification={notification} /> : null}

        {markRead.isError ? <RequestRefusal error={markRead.error} /> : null}
      </div>
    </li>
  )
}

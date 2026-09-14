import { useListNotifications, type listNotificationsResponse } from '@/api/generated/dormitory'
import type { ApiError } from '@/api/http-client'

/**
 * How many messages of the personal account have not been read.
 *
 * The figure is `meta.unread_count` of `GET /notifications`, counted by the
 * server over the whole table rather than over the page it answered with. It
 * used to be `meta.total` of a second request filtered to the unread ones; that
 * parameter went with the filter on the screen, and the count moved into the
 * envelope of the listing the account was already asking for.
 *
 * The first page is what it asks for, and deliberately: the notifications
 * screen asks for the same page under the same key, so the badge and the list
 * are one request while the reader is on the first page of their messages.
 *
 * `null` means the answer is not in yet, or the request failed. A counter is a
 * decoration on a title bar, and a failed one has to disappear rather than
 * print a zero: «no unread messages» and «we could not ask» are different
 * statements, and only one of them is true.
 */
export function useUnreadNotifications(enabled: boolean): number | null {
  const notifications = useListNotifications<listNotificationsResponse, ApiError>(
    { page: 1 },
    {
      query: {
        enabled,
        retry: false,
        // The badge is read at a glance and rewritten by every mark-as-read,
        // which invalidates this key through `useAccountRefresh`. A minute of
        // staleness in between costs nothing and saves a request per screen.
        staleTime: 60_000,
      },
    },
  )

  if (notifications.data?.status !== 200) {
    return null
  }
  return notifications.data.data.meta?.unread_count ?? null
}

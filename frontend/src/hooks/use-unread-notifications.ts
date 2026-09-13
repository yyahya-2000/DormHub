import { useListNotifications, type listNotificationsResponse } from '@/api/generated/dormitory'
import type { ApiError } from '@/api/http-client'

/**
 * How many messages of the personal account have not been read.
 *
 * The figure comes from the pagination envelope of `GET /notifications?unread=1`
 * — `meta.total` — and never from counting the rows on the page. The list is
 * paginated at twenty-five, so a count taken from `data.length` would stop at
 * twenty-five and quietly understate the badge for exactly the person who needs
 * it most.
 *
 * `null` means the answer is not in yet, or the request failed. A counter is a
 * decoration on a title bar, and a failed one has to disappear rather than
 * print a zero: «no unread messages» and «we could not ask» are different
 * statements, and only one of them is true.
 */
export function useUnreadNotifications(enabled: boolean): number | null {
  const unread = useListNotifications<listNotificationsResponse, ApiError>(
    { unread: true },
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

  if (unread.data?.status !== 200) {
    return null
  }
  return unread.data.data.meta?.total ?? null
}

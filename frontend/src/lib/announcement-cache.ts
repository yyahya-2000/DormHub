import { useCallback } from 'react'
import { useQueryClient } from '@tanstack/react-query'

/**
 * What to forget after a write in the announcements module.
 *
 * One prefix covers the feed: a published notice belongs at the head of the
 * feed of everyone it addresses, and the copy this client is holding was read
 * before it existed.
 *
 * `/notifications` is included, and that is the fan-out of FR-09 arriving: a
 * published notice is also a message in the personal account, so the unread
 * count in the title bar is stale the moment one goes out.
 */
const ANNOUNCEMENT_PATHS = ['/announcements', '/notifications']

export function useAnnouncementRefresh(): () => void {
  const queryClient = useQueryClient()

  return useCallback(() => {
    void queryClient.invalidateQueries({
      predicate: (query) => {
        const head = query.queryKey[0]
        if (typeof head !== 'string') {
          return false
        }
        return ANNOUNCEMENT_PATHS.some(
          (path) => head === path || head.startsWith(`${path}/`),
        )
      },
    })
  }, [queryClient])
}

import { useCallback } from 'react'
import { useQueryClient } from '@tanstack/react-query'

/**
 * What to forget after a write in the announcements module.
 *
 * One prefix covers both reads: the feed and the readers report are the same
 * resource seen from the two ends, and an acknowledgement moves both at once —
 * the row loses its unread mark and the share rises by one reader. Publishing
 * touches the same prefix, because the new notice belongs at the head of the
 * feed of everyone it addresses.
 *
 * `/notifications` is included, and that is the fan-out of FR-09 arriving: a
 * mandatory notice is also a message the resident cannot switch off, so the
 * unread count in the title bar is stale the moment one is published.
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

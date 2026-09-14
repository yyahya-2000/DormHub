import { useCallback } from 'react'
import { useQueryClient } from '@tanstack/react-query'

/**
 * What to forget after a write in the maintenance module.
 *
 * A single transition moves three reads at once: the card the button was
 * pressed on, the reporter's own list, and the queue of the dormitory, where
 * the row changes state and may leave the default «open» filter altogether.
 * The first two share the `/maintenance-requests` prefix; the queue is a
 * sub-resource of the building, which is why the second prefix is there.
 *
 * `/notifications` is included because FR-38's fourth criterion makes every
 * change a message to the submitter, so the unread count in the title bar is
 * stale as soon as a warden accepts anything.
 */
const MAINTENANCE_PATHS = ['/maintenance-requests', '/buildings', '/notifications']

export function useMaintenanceRefresh(): () => void {
  const queryClient = useQueryClient()

  return useCallback(() => {
    void queryClient.invalidateQueries({
      predicate: (query) => {
        const head = query.queryKey[0]
        if (typeof head !== 'string') {
          return false
        }
        return MAINTENANCE_PATHS.some(
          (path) => head === path || head.startsWith(`${path}/`),
        )
      },
    })
  }, [queryClient])
}

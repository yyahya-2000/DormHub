import { useCallback } from 'react'
import { useQueryClient } from '@tanstack/react-query'

/**
 * What to forget after a write in the guest module.
 *
 * A decision on a request changes two lists at once — the queue of the
 * dormitory and the author's own requests — and both are served by the same
 * route under different scopes, so one prefix covers them. The visitor register
 * is included because an entry recorded at the post adds a row to it.
 *
 * `/checkpoint` is deliberately absent. Its one read is a POST that the officer
 * asks for by pressing a button, and refetching it behind their back would put
 * a lookup in the audit log that nobody performed.
 */
const GUEST_PATHS = ['/guest-requests', '/guest-visits', '/buildings']

export function useGuestRefresh(): () => void {
  const queryClient = useQueryClient()

  return useCallback(() => {
    void queryClient.invalidateQueries({
      predicate: (query) => {
        const head = query.queryKey[0]
        if (typeof head !== 'string') {
          return false
        }
        return GUEST_PATHS.some((path) => head === path || head.startsWith(`${path}/`))
      },
    })
  }, [queryClient])
}

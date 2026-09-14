import { useCallback } from 'react'
import { useQueryClient } from '@tanstack/react-query'

/**
 * What to forget after a write to the personal account.
 *
 * The counterpart of `housing-cache.ts`, and it names a different set of paths
 * because a write here moves different answers: marking a message read changes
 * the list it was read in, and the session behind `GET /auth/me` is what the
 * frame of the application is drawn from.
 */
const ACCOUNT_PATHS = ['/notifications', '/auth/me']

export function useAccountRefresh(): () => void {
  const queryClient = useQueryClient()

  return useCallback(() => {
    void queryClient.invalidateQueries({
      predicate: (query) => {
        const head = query.queryKey[0]
        if (typeof head !== 'string') {
          return false
        }
        // A path covers what is nested under it the same way the housing
        // refresh covers a room under its building: the boundary is a slash,
        // so no path swallows a neighbour that merely shares a prefix.
        return ACCOUNT_PATHS.some((path) => head === path || head.startsWith(`${path}/`))
      },
    })
  }, [queryClient])
}

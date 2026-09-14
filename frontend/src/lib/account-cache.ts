import { useCallback } from 'react'
import { useQueryClient } from '@tanstack/react-query'

/**
 * What to forget after a write to the personal account.
 *
 * The counterpart of `housing-cache.ts`, and it names a different set of paths
 * because a write here moves different answers. Marking a message read changes
 * the list it was read in. Giving or withdrawing a consent changes the history,
 * the list of texts still pending, and `GET /auth/me`, which is where
 * `consent_required` comes from.
 *
 * `/auth/me` is on the list, and that is the one difference from the housing
 * refresh, which deliberately leaves the session alone. Here the session is
 * exactly what changed: the strip that offers the text is drawn from
 * `consent_required`, and a withdrawal that left it stale would hide the offer
 * to consent again.
 */
const ACCOUNT_PATHS = ['/notifications', '/consents', '/auth/me']

export function useAccountRefresh(): () => void {
  const queryClient = useQueryClient()

  return useCallback(() => {
    void queryClient.invalidateQueries({
      predicate: (query) => {
        const head = query.queryKey[0]
        if (typeof head !== 'string') {
          return false
        }
        // `/consents` covers `/consents/pending` the same way the housing
        // refresh covers a room under its building: the boundary is a slash,
        // so no path swallows a neighbour that merely shares a prefix.
        return ACCOUNT_PATHS.some((path) => head === path || head.startsWith(`${path}/`))
      },
    })
  }, [queryClient])
}

import { useCallback } from 'react'
import { useQueryClient } from '@tanstack/react-query'

/**
 * What to forget after a write in the lost-and-found module.
 *
 * One prefix covers four reads, because they are all sub-resources of one
 * path: the feed, one card, the claims on it, and the claim routes that hang
 * off `/lost-found/claims`. A single acceptance moves three of them at once —
 * the claim changes state, the entry's count of claims with it, and the entry
 * may have left the default reading of the feed altogether.
 *
 * `/notifications` is on the list because every answer in this module is a
 * message: the claimant is told either way (FR-26), the holder is told a claim
 * was filed, and the warden is told a refusal was referred. The unread count
 * in the title bar is stale the moment any of those is sent.
 */
const LOST_FOUND_PATHS = ['/lost-found', '/notifications']

export function useLostFoundRefresh(): () => void {
  const queryClient = useQueryClient()

  return useCallback(() => {
    void queryClient.invalidateQueries({
      predicate: (query) => {
        const head = query.queryKey[0]
        if (typeof head !== 'string') {
          return false
        }
        return LOST_FOUND_PATHS.some(
          (path) => head === path || head.startsWith(`${path}/`),
        )
      },
    })
  }, [queryClient])
}

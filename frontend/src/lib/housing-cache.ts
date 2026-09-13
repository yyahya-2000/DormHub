import { useCallback } from 'react'
import { useQueryClient } from '@tanstack/react-query'

/**
 * What to forget after a write to the housing register.
 *
 * A move-in changes four answers at once: the room the place belongs to, the
 * list of rooms of the building, the card of the person who moved in and — for
 * a deletion or an archiving — the register of dormitories itself. Rather than
 * enumerate them at each call site and miss one, the keys are matched by the
 * path they were built from, which is what the generated client uses as a key.
 *
 * The session (`/auth/me`) and the audit log are left alone on purpose: the
 * first has not changed, and the second is a page the reader asks for.
 */
const HOUSING_PATHS = ['/buildings', '/rooms', '/residents', '/residencies']

export function useHousingRefresh(): () => void {
  const queryClient = useQueryClient()

  return useCallback(() => {
    void queryClient.invalidateQueries({
      predicate: (query) => {
        const head = query.queryKey[0]
        if (typeof head !== 'string') {
          return false
        }
        return HOUSING_PATHS.some((path) => head === path || head.startsWith(`${path}/`))
      },
    })
  }, [queryClient])
}

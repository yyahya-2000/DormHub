import { useCallback, useState } from 'react'

/**
 * Page state for a list, and the two query parameters the API reads.
 *
 * Every list route of the contract answers with a Laravel page — `data` plus a
 * `meta` carrying `current_page`, `last_page`, `per_page` and `total` — and
 * takes `page` and `per_page`. The page number is the only thing a screen has
 * to hold, so this is all the hook keeps; `meta` comes back with the answer and
 * is read from there rather than mirrored into state, which is what keeps the
 * two from drifting apart after a deletion.
 */

/** What the API falls back to when `per_page` is not sent. */
export const DEFAULT_PER_PAGE = 20

export type PaginationState = {
  page: number
  perPage: number
  /** Spread straight into the parameters of a list query. */
  params: { page: number; per_page: number }
  setPage: (page: number) => void
  /** Back to the first page. */
  reset: () => void
}

/**
 * `resetKey` is the filter the list is under, in any form that compares by
 * `Object.is` — a number, a string, or several of them joined. When it changes
 * the page returns to the first, because page four of the old filter is a blank
 * screen under the new one. The comparison happens during the render that sees
 * the new key, not in an effect, so the list is never requested twice.
 */
export function usePagination(
  options: { perPage?: number; resetKey?: unknown } = {},
): PaginationState {
  const { perPage = DEFAULT_PER_PAGE, resetKey } = options

  const [page, setPage] = useState(1)
  const [appliedKey, setAppliedKey] = useState(resetKey)

  if (!Object.is(appliedKey, resetKey)) {
    setAppliedKey(resetKey)
    setPage(1)
  }

  const reset = useCallback(() => {
    setPage(1)
  }, [])

  return {
    page,
    perPage,
    params: { page, per_page: perPage },
    setPage,
    reset,
  }
}

/**
 * How many pages the answer says there are. A `meta` that is absent — an error,
 * or a route not yet paginated — counts as one page, and the pagination row
 * then draws nothing.
 */
export function lastPageOf(meta: { last_page?: number } | null | undefined): number {
  const last = meta?.last_page
  return typeof last === 'number' && last > 1 ? Math.floor(last) : 1
}

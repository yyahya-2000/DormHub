import { QueryClient } from '@tanstack/react-query'

import { ApiError } from '@/api/http-client'

/**
 * Server state lives in TanStack Query, as §3.3.1 puts it. The defaults matter
 * for an administrative system: a refusal is an answer, not a hiccup, so a 4xx
 * is never retried — repeating a 403 five times only fills the audit log.
 */
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      refetchOnWindowFocus: false,
      retry: (failureCount, error) => {
        if (error instanceof ApiError && error.status < 500) {
          return false
        }
        return failureCount < 2
      },
    },
    mutations: {
      retry: false,
    },
  },
})

import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'

import { useCurrentUser, useLogout, type currentUserResponse } from '@/api/generated/dormitory'
import type { ApiError } from '@/api/http-client'
import { SESSION_EXPIRED_EVENT } from '@/api/http-client'
import { forgetToken, readToken, rememberToken } from '@/auth/token-storage'
import { SessionContext, type Session, type SessionContextValue } from '@/auth/session-context'

/**
 * Holds the token and the account it belongs to for the whole application.
 *
 * The account is not taken from the sign-in response and trusted afterwards: it
 * is read from `GET /auth/me`, so a role revoked between two page loads is
 * reflected on reload without a special mechanism.
 */
export function SessionProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const [token, setToken] = useState<string | null>(() => readToken())
  const [wasSignedOutByServer, setWasSignedOutByServer] = useState(false)

  const currentUser = useCurrentUser<currentUserResponse, ApiError>({
    query: {
      enabled: token !== null,
      retry: false,
      staleTime: 60_000,
    },
  })

  const logout = useLogout<ApiError>()

  useEffect(() => {
    // The interceptor fires this when the API refuses the stored token: the
    // token is already gone from storage by then, and the guard sends the
    // person back to the sign-in screen.
    function onExpired() {
      setToken(null)
      setWasSignedOutByServer(true)
      queryClient.clear()
    }
    window.addEventListener(SESSION_EXPIRED_EVENT, onExpired)
    return () => window.removeEventListener(SESSION_EXPIRED_EVENT, onExpired)
  }, [queryClient])

  const signIn = useCallback(
    (issued: string) => {
      rememberToken(issued)
      setWasSignedOutByServer(false)
      setToken(issued)
      queryClient.clear()
    },
    [queryClient],
  )

  const signOut = useCallback(() => {
    logout.mutate(undefined, {
      // Whether the server revoked the token or refused the call, this browser
      // stops holding it.
      onSettled: () => {
        forgetToken()
        setToken(null)
        setWasSignedOutByServer(false)
        queryClient.clear()
      },
    })
  }, [logout, queryClient])

  const session: Session = useMemo(() => {
    if (token === null) {
      return { status: 'anonymous' }
    }
    const response = currentUser.data
    if (response !== undefined && response.status === 200) {
      return { status: 'authenticated', user: response.data.data }
    }
    if (currentUser.isError) {
      // The token did not produce an account — a revoked token, or an API that
      // is down. Either way there is no session to work in.
      return { status: 'anonymous' }
    }
    return { status: 'checking' }
  }, [token, currentUser.data, currentUser.isError])

  const value: SessionContextValue = useMemo(
    () => ({
      session,
      wasSignedOutByServer,
      signIn,
      signOut,
      isSigningOut: logout.isPending,
    }),
    [session, wasSignedOutByServer, signIn, signOut, logout.isPending],
  )

  return <SessionContext value={value}>{children}</SessionContext>
}

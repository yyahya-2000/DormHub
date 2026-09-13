import { createContext, use } from 'react'

import type { User } from '@/api/generated/model'

/**
 * What the application knows about the person at the keyboard: nothing, or the
 * account the server returned for the token it accepted. The grants inside
 * `User` describe which role is held in which building (FR-07); the interface
 * reads them to decide what to render, never to decide what is allowed.
 */
export type Session =
  | { status: 'anonymous' }
  | { status: 'checking' }
  | { status: 'authenticated'; user: User }

export type SessionContextValue = {
  session: Session
  /** True once the API has rejected the stored token during this visit. */
  wasSignedOutByServer: boolean
  /** Store the token the sign-in route issued and load the account behind it. */
  signIn: (token: string) => void
  /** Revoke the token on the server, then drop every cached answer. */
  signOut: () => void
  isSigningOut: boolean
}

export const SessionContext = createContext<SessionContextValue | null>(null)

export function useSession(): SessionContextValue {
  const value = use(SessionContext)
  if (value === null) {
    throw new Error('useSession was called outside SessionProvider')
  }
  return value
}

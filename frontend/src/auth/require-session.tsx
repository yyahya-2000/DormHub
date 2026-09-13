import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import { useSession } from '@/auth/session-context'

/**
 * The guard in front of every route that needs a token (FR-08). It is a
 * routing convenience, not a permission check: the token is verified by the
 * API on each request, and a page behind this guard can still be refused.
 */
export function RequireSession() {
  const { session } = useSession()
  const location = useLocation()
  const { t } = useTranslation()

  if (session.status === 'checking') {
    return (
      <div
        className="flex min-h-dvh items-center justify-center px-4 text-steel"
        role="status"
      >
        {t('common.loading')}…
      </div>
    )
  }

  if (session.status === 'anonymous') {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />
  }

  return <Outlet />
}

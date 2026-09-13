import { NavLink, Outlet } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import { buildingsOf, showsAuditLink } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { LanguageSwitch } from '@/components/language-switch'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

/**
 * The frame every signed-in screen sits in: a dark title bar, a row of filing
 * tabs, and the page. The tabs are drawn from the grants of the current
 * account — see `auth/navigation.ts` for why that is a matter of drawing and
 * not of permission.
 */
export function AppShell() {
  const { t } = useTranslation()
  const { session, signOut, isSigningOut } = useSession()

  if (session.status !== 'authenticated') {
    return null
  }

  const user = session.user
  const buildings = buildingsOf(user)
  const primaryBuilding = buildings[0]

  /*
   * The sections, built from the grants of the account. A tab is drawn when the
   * account has some chance of being answered — the register of dormitories is
   * drawn for everyone because the server narrows the list rather than refusing
   * it, and the audit log for the administrator alone because the server
   * refuses everyone else. None of this is a permission: every route below
   * stays reachable by hand and is decided by the API (§3.3.2).
   */
  const tabs: { to: string; label: string; end?: boolean }[] = [
    { to: '/', label: t('app.section.register'), end: true },
  ]
  if (primaryBuilding !== undefined) {
    tabs.push({
      to: `/buildings/${primaryBuilding}`,
      label: t('app.section.building'),
    })
  }
  tabs.push({ to: `/residents/${user.id}`, label: t('app.section.myCard') })
  if (showsAuditLink(user)) {
    tabs.push({ to: '/audit-logs', label: t('app.section.audit') })
  }

  return (
    <div className="flex min-h-dvh flex-col">
      <header className="bg-prussian text-white">
        <div className="mx-auto flex w-full max-w-5xl flex-wrap items-center justify-between gap-3 px-4 py-3">
          <div className="min-w-0">
            <p className="truncate font-semibold tracking-wide">{t('app.fullName')}</p>
            <p className="truncate text-white/70">{user.full_name}</p>
          </div>
          <div className="flex flex-wrap items-center gap-3">
            <LanguageSwitch />
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={signOut}
              disabled={isSigningOut}
              className="border-white/40 bg-transparent text-white hover:bg-white/10 hover:text-white"
            >
              {isSigningOut ? `${t('common.signingOut')}…` : t('common.signOut')}
            </Button>
          </div>
        </div>
        <div className="h-1 bg-brass" />
      </header>

      {tabs.length > 0 ? (
        <nav aria-label={t('common.menu')} className="border-b border-rule bg-paper">
          <ul className="mx-auto flex w-full max-w-5xl flex-wrap gap-x-2 px-4">
            {tabs.map((tab) => (
              <li key={tab.to}>
                <NavLink
                  to={tab.to}
                  end={tab.end ?? false}
                  className={({ isActive }) =>
                    cn(
                      'inline-block border-b-4 px-3 py-3 font-medium transition-colors',
                      isActive
                        ? 'border-prussian text-prussian'
                        : 'border-transparent text-steel hover:text-ink',
                    )
                  }
                >
                  {tab.label}
                </NavLink>
              </li>
            ))}
          </ul>
        </nav>
      ) : null}

      <main className="mx-auto w-full max-w-5xl grow px-4 py-8">
        <Outlet />
      </main>

      <footer className="border-t border-rule px-4 py-4">
        <p className="mx-auto w-full max-w-5xl text-steel">{t('app.fullName')}</p>
      </footer>
    </div>
  )
}

import { Link, NavLink, Outlet } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  awaitsConsent,
  buildingsOf,
  buildingsWith,
  guestRequestBuildingsOf,
  Permission,
  showsAuditLink,
} from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { LanguageSwitch } from '@/components/language-switch'
import { Button } from '@/components/ui/button'
import { useUnreadNotifications } from '@/hooks/use-unread-notifications'
import { useFormatters } from '@/lib/format'
import { cn } from '@/lib/utils'

/**
 * The frame every signed-in screen sits in: a dark title bar, a row of filing
 * tabs, and the page. The tabs are drawn from the grants of the current
 * account — see `auth/navigation.ts` for why that is a matter of drawing and
 * not of permission.
 *
 * Two things of the personal account live here rather than on a page, because
 * both have to be true of every screen. The count of unread messages (FR-34),
 * which is on the tab that leads to them so that a message arriving while
 * somebody is three screens deep in the housing register is still noticed. And
 * the standing offer of the consent text (FR-35), which is a strip and not a
 * modal: art. 9 part 1 wants consent free, and a dialog that has to be
 * dismissed before the application can be used is the opposite of that.
 */
export function AppShell() {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const { session, signOut, isSigningOut } = useSession()

  const authenticated = session.status === 'authenticated'
  const unread = useUnreadNotifications(authenticated)

  if (!authenticated) {
    return null
  }

  const user = session.user
  const buildings = buildingsOf(user)
  const primaryBuilding = buildings[0]
  const pendingConsents = awaitsConsent(user)

  /*
   * The sections, built from the grants of the account. A tab is drawn when the
   * account has some chance of being answered — the register of dormitories is
   * drawn for everyone because the server narrows the list rather than refusing
   * it, and the audit log for the administrator alone because the server
   * refuses everyone else. None of this is a permission: every route below
   * stays reachable by hand and is decided by the API (§3.3.2).
   */
  const tabs: { to: string; label: string; end?: boolean; badge?: number }[] = [
    { to: '/', label: t('app.section.register'), end: true },
  ]
  if (primaryBuilding !== undefined) {
    tabs.push({
      to: `/buildings/${primaryBuilding}`,
      label: t('app.section.building'),
    })
  }
  /*
   * The guest module, drawn from the grants and not from a role name.
   *
   * The post gets a tab of its own because the terminal is a workplace and not
   * a view of a building: an officer signs in, presses it, and stays there for
   * a shift. The duty officer's queue is a tab too, and that is the register of
   * stakeholders taken literally — the decision has to be a few seconds from
   * sign-in on a telephone, and three taps through the building card is not
   * that. A resident who lives somewhere gets the form.
   */
  const posts = buildingsWith(user, Permission.operateCheckpoint)
  if (posts.length > 0) {
    tabs.push({ to: '/checkpoint', label: t('app.section.checkpoint') })
  }
  const decidesIn = buildingsWith(user, Permission.decideGuestRequests)
  if (decidesIn[0] !== undefined) {
    tabs.push({
      to: `/buildings/${decidesIn[0]}/guest-requests`,
      label: t('app.section.guestQueue'),
    })
  }
  if (guestRequestBuildingsOf(user).length > 0) {
    tabs.push({ to: '/guests', label: t('app.section.guests') })
  }

  tabs.push({ to: `/residents/${user.id}`, label: t('app.section.myCard') })
  // The personal account is nobody's privilege: the routes behind these two
  // are scoped to the token and take no parameter naming anyone else.
  tabs.push({
    to: '/notifications',
    label: t('app.section.notifications'),
    ...(unread !== null && unread > 0 ? { badge: unread } : {}),
  })
  tabs.push({ to: '/consents', label: t('app.section.personalData') })
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
                  {tab.badge !== undefined ? (
                    <>
                      {' '}
                      <span
                        className="inline-block border border-brick/40 bg-brick-wash px-2 py-0.5 font-semibold text-brick"
                        aria-label={t('notifications.unreadCount', { count: tab.badge })}
                      >
                        {formatters.count(tab.badge)}
                      </span>
                    </>
                  ) : null}
                </NavLink>
              </li>
            ))}
          </ul>
        </nav>
      ) : null}

      {/*
        FR-35, first criterion, on every screen rather than only at sign-in. The
        offer stands until the person decides, and it never stands in the way:
        it is a strip that scrolls with the page, and nothing below it is
        disabled while it is there.
      */}
      {pendingConsents ? (
        <div className="border-b border-rule bg-brass-wash">
          <div className="mx-auto flex w-full max-w-5xl flex-wrap items-baseline justify-between gap-x-4 gap-y-2 px-4 py-3">
            <p className="m-0 min-w-0 text-ink">{t('consent.strip')}</p>
            <Link className="font-medium text-prussian underline" to="/consent">
              {t('consent.stripAction')}
            </Link>
          </div>
        </div>
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

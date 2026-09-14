import { Link, NavLink, Outlet } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  awaitsConsent,
  buildingsOf,
  buildingsWith,
  guestRequestBuildingsOf,
  maintenanceBuildingsOf,
  Permission,
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
  /*
   * The announcement feed is everybody's, and it is the one section here that
   * asks no question at all before drawing the tab. The route carries no
   * building parameter — the audience is computed from the grants of the token
   * — so there is nothing to scope the link to and nobody it could refuse: an
   * account addressed by nothing gets an empty feed, which is an answer.
   */
  tabs.push({ to: '/announcements', label: t('app.section.announcements') })
  /*
   * FR-36's form, offered to whoever the mirror believes lives somewhere. The
   * queue of a dormitory is not here: it is a view of one building and sits
   * among the building's own tabs.
   */
  if (maintenanceBuildingsOf(user).length > 0) {
    tabs.push({ to: '/maintenance', label: t('app.section.maintenance') })
  }
  /*
   * The lost-and-found bureau, drawn for everybody and asking nothing first —
   * the second section here to do so, and for the announcement feed's reason.
   * The route carries no building parameter: the dormitories are computed from
   * the grants of the token, so there is nothing to scope the link to and
   * nobody it could refuse. An account attached to no dormitory gets an empty
   * feed, which is an answer; the administrator, whose grant names none, gets
   * every one of them.
   */
  tabs.push({ to: '/lost-found', label: t('app.section.lostFound') })

  tabs.push({ to: `/residents/${user.id}`, label: t('app.section.myCard') })
  // The personal account is nobody's privilege: the routes behind these two
  // are scoped to the token and take no parameter naming anyone else.
  tabs.push({
    to: '/notifications',
    label: t('app.section.notifications'),
    ...(unread !== null && unread > 0 ? { badge: unread } : {}),
  })
  tabs.push({ to: '/consents', label: t('app.section.personalData') })

  return (
    <div className="flex min-h-dvh flex-col">
      <header className="bg-prussian text-white">
        <div className="mx-auto flex w-full max-w-5xl flex-wrap items-center justify-between gap-3 px-4 py-3">
          <div className="flex min-w-0 items-center gap-3">
            {/*
              The emblem of the university the dormitory belongs to. The brand
              book ships two marks under «Знак → версия для тёмных фонов», and
              the one to take is the second: a white disc carrying the monogram
              in brand blue. The first is the inverse — a blue disc inside a
              hairline white ring — and against `prussian` it reads as a hole
              rather than as a mark, which is also why hse.ru puts the white
              disc in its own dark header.

              The file is `Znak_for_black_2/CMYK/01_Znak_for_black_CMYK_2.svg`
              out of https://www.hse.ru/mirror/pubs/share/533609051, copied
              byte for byte, so `shasum -a 256 public/hse-emblem-on-dark.svg`
              still answers for where it came from. Governing act: приказ НИУ
              ВШЭ от 15.11.2022 «Об утверждении Правил работы с фирменным
              стилем», in force; the brand book revision of April 2026 reworked
              the descriptors and the palette but left the mark alone.

              It is an attribution of the institution and not a mark of the
              service: the system is coursework, so the emblem is a plain image
              of fixed height, never a link to hse.ru, and the name beside it
              stays the larger of the two.
            */}
            <img
              src="/hse-emblem-on-dark.svg"
              alt={t('app.logoAlt')}
              width={36}
              height={36}
              className="h-9 w-9 shrink-0"
            />
            <div className="min-w-0">
              <p className="truncate font-semibold tracking-wide">{t('app.fullName')}</p>
              <p className="truncate text-white/70">{user.full_name}</p>
            </div>
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

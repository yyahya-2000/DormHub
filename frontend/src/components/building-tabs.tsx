import { NavLink } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import { appointsStaff, issuesResidentAccounts, may, Permission } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { cn } from '@/lib/utils'

/**
 * The views of one building: the card, the register of rooms, the plan, the
 * staff and the accounts of incoming residents.
 *
 * They sit below the section tabs rather than beside them because they are one
 * object seen several ways, and because that many top-level tabs at 360 px are
 * a wall of links. Each is drawn from a capability and from nothing else: the
 * register and the plan for accounts that may read rooms, the staff for the two
 * links of the chain of appointment (FR-41), the accounts for those who may
 * issue one (FR-42). A building manager therefore gets four tabs and not five —
 * he appoints nobody. The routes stay reachable and the server is what refuses
 * (§3.3.2).
 */
export function BuildingTabs({ buildingId }: { buildingId: number }) {
  const { t } = useTranslation()
  const { session } = useSession()

  const user = session.status === 'authenticated' ? session.user : null
  const readsRooms = user !== null && may(user, Permission.viewRooms, buildingId)
  const appoints = user !== null && appointsStaff(user, buildingId)
  const issuesAccounts = user !== null && issuesResidentAccounts(user, buildingId)

  const views = [
    { to: `/buildings/${buildingId}`, label: t('building.views.card'), end: true },
    ...(readsRooms
      ? [
          { to: `/buildings/${buildingId}/rooms`, label: t('building.views.rooms'), end: false },
          { to: `/buildings/${buildingId}/plan`, label: t('building.views.plan'), end: false },
        ]
      : []),
    ...(appoints
      ? [{ to: `/buildings/${buildingId}/staff`, label: t('building.views.staff'), end: false }]
      : []),
    ...(issuesAccounts
      ? [
          {
            to: `/buildings/${buildingId}/accounts`,
            label: t('building.views.accounts'),
            end: false,
          },
        ]
      : []),
  ]

  if (views.length < 2) {
    return null
  }

  return (
    <nav aria-label={t('building.views.label')}>
      <ul className="m-0 flex list-none flex-wrap gap-2 p-0">
        {views.map((view) => (
          <li key={view.to}>
            <NavLink
              to={view.to}
              end={view.end}
              className={({ isActive }) =>
                cn(
                  'inline-block border px-3 py-2 font-medium transition-colors',
                  isActive
                    ? 'border-prussian bg-prussian text-white'
                    : 'border-rule bg-paper-raised text-steel hover:text-ink',
                )
              }
            >
              {view.label}
            </NavLink>
          </li>
        ))}
      </ul>
    </nav>
  )
}

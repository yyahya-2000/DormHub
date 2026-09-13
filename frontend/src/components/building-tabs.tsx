import { NavLink } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import { may, Permission } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { cn } from '@/lib/utils'

/**
 * The three views of one building: the card, the register of rooms, the plan.
 *
 * They sit below the section tabs rather than beside them because they are one
 * object seen three ways, and because six top-level tabs at 360 px are a wall
 * of links. The register and the plan are drawn for accounts whose grants carry
 * the capability to read rooms; the routes stay reachable and the server is
 * what refuses (§3.3.2).
 */
export function BuildingTabs({ buildingId }: { buildingId: number }) {
  const { t } = useTranslation()
  const { session } = useSession()

  const user = session.status === 'authenticated' ? session.user : null
  const readsRooms = user !== null && may(user, Permission.viewRooms, buildingId)

  const views = [
    { to: `/buildings/${buildingId}`, label: t('building.views.card'), end: true },
    ...(readsRooms
      ? [
          { to: `/buildings/${buildingId}/rooms`, label: t('building.views.rooms'), end: false },
          { to: `/buildings/${buildingId}/plan`, label: t('building.views.plan'), end: false },
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

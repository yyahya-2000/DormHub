import { NavLink } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  appointsStaff,
  issuesResidentAccounts,
  may,
  Permission,
  readsGuestRequests,
  readsMaintenanceQueue,
  readsVisitRegister,
} from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { cn } from '@/lib/utils'

/**
 * The views of one building: the card, the housing stock, the staff and the
 * accounts of incoming residents.
 *
 * They sit below the section tabs rather than beside them because they are one
 * object seen several ways, and because that many top-level tabs at 360 px are
 * a wall of links. Each is drawn from a capability and from nothing else: the
 * housing stock for accounts that may read rooms, the staff for the two
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
  const readsGuests = user !== null && readsGuestRequests(user, buildingId)
  const readsRegister = user !== null && readsVisitRegister(user, buildingId)
  const readsMaintenance = user !== null && readsMaintenanceQueue(user, buildingId)

  const views = [
    { to: `/buildings/${buildingId}`, label: t('building.views.card'), end: true },
    ...(readsRooms
      ? [
          { to: `/buildings/${buildingId}/rooms`, label: t('building.views.rooms'), end: false },
        ]
      : []),
    // Four roles read the queue of a dormitory and two export its register, and
    // the difference between those two sets is the whole reason the guest
    // module split the capabilities apart: the manager reads the queue because a
    // request names a room, and §3.9.6 keeps the register to the warden and the
    // administrator.
    ...(readsGuests
      ? [
          {
            to: `/buildings/${buildingId}/guest-requests`,
            label: t('building.views.guestRequests'),
            end: false,
          },
        ]
      : []),
    ...(readsRegister
      ? [
          {
            to: `/buildings/${buildingId}/visit-register`,
            label: t('building.views.visitRegister'),
            end: false,
          },
        ]
      : []),
    // FR-40. Three roles read the queue and two of them triage it, and the
    // difference is not drawn here: the tab leads to a screen that asks the
    // narrower question itself, so the administrator sees the backlog of any
    // dormitory without being offered a button that promises a date.
    ...(readsMaintenance
      ? [
          {
            to: `/buildings/${buildingId}/maintenance`,
            label: t('building.views.maintenance'),
            end: false,
          },
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

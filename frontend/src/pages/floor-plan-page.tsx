import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  useListBuildingRooms,
  useShowBuilding,
  type listBuildingRoomsResponse,
  type showBuildingResponse,
} from '@/api/generated/dormitory'
import { BedStatus, RoomStatus, type Room } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { may, Permission } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { BuildingTabs } from '@/components/building-tabs'
import { RoomPlaces } from '@/components/housing/room-places'
import { RoomSummary } from '@/components/housing/room-summary'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Skeleton } from '@/components/ui/skeleton'
import { useBuildingOccupancy } from '@/hooks/use-building-occupancy'
import { useFormatters } from '@/lib/format'
import { vacanciesOf } from '@/lib/rooms'
import { cn } from '@/lib/utils'

/**
 * FR-43, the building floor by floor.
 *
 * **What this is not.** There is no plan of the premises in the data and none
 * is invented here. The register knows a floor number and a room number, so the
 * page draws a grid of rooms grouped by floor and says as much on the page
 * itself. A drawn corridor with doors on it would be a picture of a building
 * nobody surveyed, and a warden would read it as one.
 *
 * Occupancy is stated twice on every room, once as a figure and once as a row
 * of places, and the two answer different questions. «Occupied 3 of 3» is the
 * criterion's «places taken against capacity». The row of squares underneath is
 * where a blocked place becomes visible, which no ratio can show.
 *
 * A full room is not distinguished by colour alone. The tone changes, the
 * border changes, and the tile carries the words — a plan read at the security
 * post at seven in the morning, on whatever monitor is there, cannot depend on
 * a reader telling two washes apart.
 */

const PLACE_SQUARE: Record<string, string> = {
  free: 'border-prussian/50 bg-paper-raised',
  occupied: 'border-brass/60 bg-brass',
  blocked: 'border-steel/60 bg-steel/40',
}

export function FloorPlanPage() {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const params = useParams<{ buildingId: string }>()
  const buildingId = Number(params.buildingId)
  const { session } = useSession()

  const [selected, setSelected] = useState<number | null>(null)

  const user = session.status === 'authenticated' ? session.user : null
  const mayManageRooms = user !== null && may(user, Permission.manageRooms, buildingId)
  const mayManageResidencies =
    user !== null && may(user, Permission.manageResidencies, buildingId)
  const mayReadPeople = user !== null && may(user, Permission.viewPeople, buildingId)
  const mayReadCards = user !== null && may(user, Permission.viewResidentCard, buildingId)

  const building = useShowBuilding<showBuildingResponse, ApiError>(buildingId, {
    query: { enabled: Number.isInteger(buildingId), retry: false },
  })

  const rooms = useListBuildingRooms<listBuildingRoomsResponse, ApiError>(buildingId, {
    query: { enabled: Number.isInteger(buildingId), retry: false },
  })

  const occupancy = useBuildingOccupancy(buildingId, {
    rollEnabled: mayReadPeople,
    resolveEnabled: mayReadCards && selected !== null,
  })

  const card = building.data?.status === 200 ? building.data.data.data : null
  const list = rooms.data?.status === 200 ? rooms.data.data.data : null

  const byFloor = new Map<number, Room[]>()
  for (const room of list ?? []) {
    const floor = byFloor.get(room.floor)
    if (floor === undefined) {
      byFloor.set(room.floor, [room])
    } else {
      floor.push(room)
    }
  }
  const floors = [...byFloor.keys()].sort((a, b) => a - b)
  for (const group of byFloor.values()) {
    group.sort((a, b) => a.number.localeCompare(b.number, undefined, { numeric: true }))
  }

  // Only meaningful once the register has answered. When the room list was
  // refused, «no rooms are registered on floors 1-9» would be a statement about
  // the building, and what actually happened is a statement about the reader.
  const declaredFloors = list === null ? 0 : (card?.floors_count ?? 0)
  const emptyFloors: number[] = []
  for (let floor = 1; floor <= declaredFloors; floor++) {
    if (!byFloor.has(floor)) {
      emptyFloors.push(floor)
    }
  }

  return (
    <div className="grid grid-cols-1 gap-6">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">{t('plan.heading')}</h1>
        {card !== null ? (
          <p className="mt-1 break-words text-lg text-steel">
            {card.name} · {card.address}
          </p>
        ) : null}
      </div>

      <BuildingTabs buildingId={buildingId} />

      <p className="m-0 border-l-4 border-brass bg-brass-wash px-3 py-2">
        {t('plan.notAPlan')}
      </p>

      {/*
        One refusal, not two. Both queries are refused together when the role
        does not cover this dormitory, and printing the same 403 twice reads as
        two separate failures. The register is this page's subject, so its
        refusal is the one shown; the card's stands in only when the rooms
        answered and the card did not.
      */}
      {rooms.isError ? (
        <RequestRefusal error={rooms.error} />
      ) : building.isError ? (
        <RequestRefusal error={building.error} />
      ) : null}

      {rooms.isPending && !rooms.isError ? (
        <div className="grid gap-2" aria-hidden="true">
          <Skeleton className="h-24 w-full" />
          <Skeleton className="h-24 w-full" />
        </div>
      ) : null}

      {list !== null ? <PlanLegend /> : null}

      {list !== null && list.length === 0 ? (
        <Panel caption={t('plan.heading')}>
          <p className="px-4 py-6 text-steel">{t('rooms.empty')}</p>
        </Panel>
      ) : null}

      {floors.map((floor) => {
        const roomsOfFloor = byFloor.get(floor) ?? []
        const occupied = roomsOfFloor.reduce(
          (total, room) => total + (room.occupied_beds_count ?? 0),
          0,
        )
        const capacity = roomsOfFloor.reduce((total, room) => total + room.capacity, 0)
        const chosen = roomsOfFloor.find((room) => room.id === selected) ?? null

        return (
          <Panel
            key={floor}
            className="min-w-0"
            caption={t('plan.floor', { floor })}
            aside={t('plan.floorSummary', {
              rooms: formatters.count(roomsOfFloor.length),
              occupied: formatters.count(occupied),
              capacity: formatters.count(capacity),
            })}
          >
            <ul className="m-0 grid list-none grid-cols-[repeat(auto-fill,minmax(9rem,1fr))] gap-2 p-4">
              {roomsOfFloor.map((room) => (
                <li key={room.id} className="min-w-0">
                  <RoomTile
                    room={room}
                    selected={room.id === selected}
                    onSelect={() =>
                      setSelected((current) => (current === room.id ? null : room.id))
                    }
                  />
                </li>
              ))}
            </ul>

            {chosen !== null ? (
              <div className="grid gap-4 border-t border-rule bg-paper px-4 py-4">
                <RoomSummary room={chosen} />

                {mayReadCards ? (
                  <p className="m-0 text-steel">{t('plan.namesFromCards')}</p>
                ) : (
                  <p className="m-0 text-steel">{t('plan.namesWithheld')}</p>
                )}

                {occupancy.rollError !== null ? (
                  <RequestRefusal error={occupancy.rollError} />
                ) : null}

                {occupancy.refusedCards > 0 ? (
                  <p className="m-0 text-steel">
                    {t('plan.cardsRefused', { count: occupancy.refusedCards })}
                  </p>
                ) : null}

                <RoomPlaces
                  room={chosen}
                  occupancy={occupancy}
                  mayManageRooms={mayManageRooms}
                  mayManageResidencies={mayManageResidencies}
                />
              </div>
            ) : null}
          </Panel>
        )
      })}

      {emptyFloors.length > 0 ? (
        <p className="m-0 text-steel">
          {t('plan.emptyFloors', {
            floors: emptyFloors.map((floor) => formatters.count(floor)).join(', '),
            count: emptyFloors.length,
          })}
        </p>
      ) : null}
    </div>
  )
}

/** One room on the plan: the number, the ratio, the places, and the state in words. */
function RoomTile({
  room,
  selected,
  onSelect,
}: {
  room: Room
  selected: boolean
  onSelect: () => void
}) {
  const { t } = useTranslation()
  const formatters = useFormatters()

  const inService = room.status === undefined || room.status === RoomStatus.in_service
  const vacancies = vacanciesOf(room)
  const full = inService && vacancies === 0

  return (
    <button
      type="button"
      onClick={onSelect}
      aria-pressed={selected}
      className={cn(
        'grid w-full min-w-0 gap-1 border-2 px-3 py-3 text-left transition-colors',
        // The state decides the tone; selection adds a bar on the leading edge
        // rather than replacing it, so that a chosen room still reads as full.
        !inService
          ? 'border-steel/40 bg-paper text-steel'
          : full
            ? 'border-brick bg-brick-wash'
            : 'border-prussian/40 bg-paper-raised',
        selected ? 'border-l-8 border-l-prussian' : null,
      )}
    >
      <span className="font-mono text-xl font-semibold text-ink">{room.number}</span>

      <span className="text-ink">
        {t('plan.tileOccupancy', {
          occupied: formatters.count(room.occupied_beds_count ?? 0),
          capacity: formatters.count(room.capacity),
        })}
      </span>

      {/* The places themselves. A ratio cannot show a blocked place; this can. */}
      <span className="flex flex-wrap gap-1" aria-hidden="true">
        {(room.beds ?? []).map((bed) => (
          <span
            key={bed.id}
            className={cn(
              'inline-block size-4 border',
              PLACE_SQUARE[bed.status] ?? PLACE_SQUARE.blocked,
            )}
          />
        ))}
      </span>

      <span className={cn('font-medium', full ? 'text-brick' : 'text-prussian')}>
        {!inService
          ? t(`roomStatus.${room.status ?? RoomStatus.withdrawn}`)
          : full
            ? t('plan.noVacancies')
            : t('rooms.vacancies', { count: vacancies })}
      </span>
    </button>
  )
}

/** What the tones and the squares mean, said once above the floors. */
function PlanLegend() {
  const { t } = useTranslation()

  return (
    <div className="grid gap-2 border border-rule bg-paper-raised px-3 py-3">
      <h2 className="label-caps m-0">{t('plan.legend')}</h2>
      <ul className="m-0 flex list-none flex-wrap gap-x-5 gap-y-2 p-0">
        <li className="flex items-center gap-2">
          <span className="inline-block size-4 border-2 border-prussian/40 bg-paper-raised" />
          {t('plan.legendFree')}
        </li>
        <li className="flex items-center gap-2">
          <span className="inline-block size-4 border-2 border-brick bg-brick-wash" />
          {t('plan.legendFull')}
        </li>
        <li className="flex items-center gap-2">
          <span className="inline-block size-4 border-2 border-steel/40 bg-paper" />
          {t('plan.legendClosed')}
        </li>
      </ul>
      <ul className="m-0 flex list-none flex-wrap gap-x-5 gap-y-2 p-0">
        {Object.values(BedStatus).map((status) => (
          <li key={status} className="flex items-center gap-2">
            <span className={cn('inline-block size-4 border', PLACE_SQUARE[status])} />
            {t(`bedStatus.${status}`)}
          </li>
        ))}
      </ul>
    </div>
  )
}

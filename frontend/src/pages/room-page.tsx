import { useState } from 'react'
import { ArrowLeft, SquarePen } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import { useShowRoom, type showRoomResponse } from '@/api/generated/dormitory'
import type { ApiError } from '@/api/http-client'
import { may, Permission } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { RoomFigures } from '@/components/housing/room-card'
import { RoomForm } from '@/components/housing/room-form'
import { RoomPlaces } from '@/components/housing/room-places'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { useBuildingOccupancy } from '@/hooks/use-building-occupancy'

/**
 * One room: its figures, its places and the two residency writes.
 *
 * Editing the room lives here rather than in the list of a floor — a form open
 * inside a grid of cards makes the grid jump, and the room is the thing being
 * changed. Names are resolved only once a room is open, which is what keeps the
 * reads of resident cards (and their audit entries) down to the rooms somebody
 * actually looked at.
 */
export function RoomPage() {
  const { t } = useTranslation()
  const params = useParams<{ buildingId: string; roomId: string }>()
  const buildingId = Number(params.buildingId)
  const roomId = Number(params.roomId)
  const { session } = useSession()

  const [editing, setEditing] = useState(false)

  const user = session.status === 'authenticated' ? session.user : null
  const mayManageRooms = user !== null && may(user, Permission.manageRooms, buildingId)
  const mayManageResidencies =
    user !== null && may(user, Permission.manageResidencies, buildingId)
  const mayReadPeople = user !== null && may(user, Permission.viewPeople, buildingId)
  const mayReadCards = user !== null && may(user, Permission.viewResidentCard, buildingId)

  const card = useShowRoom<showRoomResponse, ApiError>(roomId, {
    query: { enabled: Number.isInteger(roomId), retry: false },
  })

  const occupancy = useBuildingOccupancy(buildingId, {
    rollEnabled: mayReadPeople,
    resolveEnabled: mayReadCards,
  })

  const room = card.data?.status === 200 ? card.data.data.data : null

  return (
    <div className="grid grid-cols-1 gap-6">
      <div className="grid gap-2">
        <Link
          to={
            room === null
              ? `/buildings/${buildingId}/rooms`
              : `/buildings/${buildingId}/rooms/floor/${room.floor}`
          }
          className="inline-flex items-center gap-2 text-prussian underline"
        >
          <ArrowLeft aria-hidden="true" className="size-5" />
          {room === null ? t('housing.floors') : t('housing.floor', { floor: room.floor })}
        </Link>

        <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1">
          <h1 className="m-0 font-mono text-2xl font-semibold text-ink">
            {room?.number ?? '—'}
          </h1>
          {room?.type !== undefined ? (
            <span className="text-steel">{t(`roomType.${room.type}`)}</span>
          ) : null}
        </div>

        {room !== null ? <RoomFigures room={room} /> : null}
      </div>

      {card.isError ? <RequestRefusal error={card.error} /> : null}

      {card.isPending && !card.isError ? (
        <div className="grid gap-2" aria-hidden="true">
          <Skeleton className="h-12 w-full" />
          <Skeleton className="h-12 w-full" />
        </div>
      ) : null}

      {room !== null ? (
        <>
          {mayManageRooms ? (
            <div>
              <Button
                type="button"
                variant="outline"
                onClick={() => setEditing((open) => !open)}
              >
                <SquarePen aria-hidden="true" />
                {t('housing.editRoom')}
              </Button>
            </div>
          ) : null}

          {editing ? (
            <RoomForm
              buildingId={buildingId}
              room={room}
              onDone={() => setEditing(false)}
            />
          ) : null}

          {occupancy.rollError !== null ? (
            <RequestRefusal error={occupancy.rollError} />
          ) : null}

          <RoomPlaces
            room={room}
            occupancy={occupancy}
            mayManageRooms={mayManageRooms}
            mayManageResidencies={mayManageResidencies}
          />
        </>
      ) : null}
    </div>
  )
}

import { useState } from 'react'
import { useQueries } from '@tanstack/react-query'
import { ArrowLeft, DoorOpen, Plus } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  getListBuildingRoomsQueryOptions,
  useListBuildingRooms,
  type listBuildingRoomsResponse,
} from '@/api/generated/dormitory'
import type { Room } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { may, Permission } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { RoomCard } from '@/components/housing/room-card'
import { RoomForm } from '@/components/housing/room-form'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { lastPageOf } from '@/hooks/use-pagination'

/** The register pages at a hundred rows, and a floor fits inside one of them. */
const PER_PAGE = 100

/**
 * The rooms of one floor.
 *
 * The register is addressed by building and not by floor, so the pages of the
 * building are read in full — they are ordered by floor and cached, which is
 * what makes walking from floor to floor cost one request rather than one per
 * floor — and the floor is taken out of them here. The `free` filter is the
 * database's own.
 */
function useRoomsOfFloor(
  buildingId: number,
  floor: number,
  free: boolean,
): { rooms: Room[]; isPending: boolean; error: ApiError | null } {
  const enabled = Number.isInteger(buildingId) && Number.isInteger(floor)
  const params = { ...(free ? { free: true } : {}), per_page: PER_PAGE }

  const first = useListBuildingRooms<listBuildingRoomsResponse, ApiError>(
    buildingId,
    { ...params, page: 1 },
    { query: { enabled, retry: false } },
  )

  const body = first.data?.status === 200 ? first.data.data : null
  const pages = lastPageOf(body?.meta)

  const rest = useQueries({
    queries: Array.from({ length: pages > 1 ? pages - 1 : 0 }, (_, index) =>
      getListBuildingRoomsQueryOptions<listBuildingRoomsResponse, ApiError>(
        buildingId,
        { ...params, page: index + 2 },
        { query: { enabled, retry: false } },
      ),
    ),
  })

  const rooms = [
    ...(body?.data ?? []),
    ...rest.flatMap((page) =>
      page.data !== undefined && page.data.status === 200 ? page.data.data.data : [],
    ),
  ].filter((room) => room.floor === floor)

  return {
    rooms,
    isPending: first.isPending || rest.some((page) => page.isPending),
    error: first.isError ? first.error : null,
  }
}

export function FloorPage() {
  const { t } = useTranslation()
  const params = useParams<{ buildingId: string; floor: string }>()
  const buildingId = Number(params.buildingId)
  const floor = Number(params.floor)
  const { session } = useSession()

  const [free, setFree] = useState(false)
  const [adding, setAdding] = useState(false)

  const user = session.status === 'authenticated' ? session.user : null
  const mayManageRooms = user !== null && may(user, Permission.manageRooms, buildingId)

  const { rooms, isPending, error } = useRoomsOfFloor(buildingId, floor, free)

  return (
    <div className="grid grid-cols-1 gap-6">
      <div className="grid gap-2">
        <Link
          to={`/buildings/${buildingId}/rooms`}
          className="inline-flex items-center gap-2 text-prussian underline"
        >
          <ArrowLeft aria-hidden="true" className="size-5" />
          {t('housing.floors')}
        </Link>
        <h1 className="text-2xl font-semibold text-ink">{t('housing.floor', { floor })}</h1>
      </div>

      <div className="flex flex-wrap gap-2">
        <Button
          type="button"
          variant={free ? 'default' : 'outline'}
          aria-pressed={free}
          onClick={() => setFree((current) => !current)}
        >
          <DoorOpen aria-hidden="true" />
          {t('housing.onlyFree')}
        </Button>

        {mayManageRooms ? (
          <Button type="button" variant="outline" onClick={() => setAdding((open) => !open)}>
            <Plus aria-hidden="true" />
            {t('housing.addRoom')}
          </Button>
        ) : null}
      </div>

      {adding ? (
        <RoomForm
          buildingId={buildingId}
          room={null}
          defaultFloor={floor}
          onDone={() => setAdding(false)}
        />
      ) : null}

      {error !== null ? <RequestRefusal error={error} /> : null}

      {isPending && error === null ? (
        <div className="grid gap-2" aria-hidden="true">
          <Skeleton className="h-20 w-full" />
          <Skeleton className="h-20 w-full" />
        </div>
      ) : null}

      {!isPending && rooms.length === 0 ? (
        <p className="m-0 text-steel">{t('housing.empty')}</p>
      ) : null}

      <ul className="m-0 grid list-none grid-cols-[repeat(auto-fill,minmax(14rem,1fr))] gap-3 p-0">
        {rooms.map((room) => (
          <li key={room.id} className="min-w-0">
            <RoomCard room={room} to={`/buildings/${buildingId}/rooms/${room.id}`} />
          </li>
        ))}
      </ul>
    </div>
  )
}

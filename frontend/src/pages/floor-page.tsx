import { useState } from 'react'
import { keepPreviousData } from '@tanstack/react-query'
import { ArrowLeft, DoorOpen, Plus } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import { useListBuildingRooms, type listBuildingRoomsResponse } from '@/api/generated/dormitory'
import type { ApiError } from '@/api/http-client'
import { may, Permission } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { RoomCard } from '@/components/housing/room-card'
import { RoomForm } from '@/components/housing/room-form'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Pagination } from '@/components/ui/pagination'
import { Skeleton } from '@/components/ui/skeleton'
import { lastPageOf, usePagination } from '@/hooks/use-pagination'

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

  const paging = usePagination({ resetKey: `${buildingId}|${floor}|${free ? '1' : ''}` })

  const register = useListBuildingRooms<listBuildingRoomsResponse, ApiError>(
    buildingId,
    { floor, ...(free ? { free: true } : {}), ...paging.params },
    {
      query: {
        enabled: Number.isInteger(buildingId) && Number.isInteger(floor),
        retry: false,
        placeholderData: keepPreviousData,
      },
    },
  )

  const page = register.data?.status === 200 ? register.data.data : null
  const rooms = page?.data ?? []
  const isPending = register.isPending
  const error = register.isError ? register.error : null

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

      <Pagination
        page={paging.page}
        lastPage={lastPageOf(page?.meta)}
        onPageChange={paging.setPage}
        disabled={register.isFetching}
      />
    </div>
  )
}

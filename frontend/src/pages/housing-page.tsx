import { useState } from 'react'
import { keepPreviousData } from '@tanstack/react-query'
import { DoorClosed, Plus, User } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  useListBuildingFloors,
  useListBuildingRooms,
  useListBuildingUsers,
  type listBuildingFloorsResponse,
  type listBuildingRoomsResponse,
  type listBuildingUsersResponse,
} from '@/api/generated/dormitory'
import { RoleCode, type Floor } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { may, Permission } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { BuildingTabs } from '@/components/building-tabs'
import { RoomSquares } from '@/components/housing/place-squares'
import { RoomCard } from '@/components/housing/room-card'
import { RoomForm } from '@/components/housing/room-form'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Pagination } from '@/components/ui/pagination'
import { Skeleton } from '@/components/ui/skeleton'
import { useDebounced } from '@/hooks/use-debounced'
import { lastPageOf, usePagination } from '@/hooks/use-pagination'
import { useFormatters } from '@/lib/format'

/**
 * FR-02 and FR-43, the housing stock of one dormitory in a single section.
 *
 * The way in is the floor, because that is how a dormitory is walked. Each card
 * draws the four figures the server counts — rooms, rooms with a free place,
 * places, free places — as a bar and a row of squares, and opens the floor.
 * Above them the two searches that skip the walk: a room by number, a resident
 * by name.
 */
export function HousingPage() {
  const { t } = useTranslation()
  const params = useParams<{ buildingId: string }>()
  const buildingId = Number(params.buildingId)
  const { session } = useSession()

  const [roomQuery, setRoomQuery] = useState('')
  const [nameQuery, setNameQuery] = useState('')
  const [adding, setAdding] = useState(false)

  const user = session.status === 'authenticated' ? session.user : null
  const mayManageRooms = user !== null && may(user, Permission.manageRooms, buildingId)
  const mayReadPeople = user !== null && may(user, Permission.viewPeople, buildingId)

  const floors = useListBuildingFloors<listBuildingFloorsResponse, ApiError>(buildingId, {
    query: { enabled: Number.isInteger(buildingId), retry: false },
  })

  const list = floors.data?.status === 200 ? floors.data.data.data : null
  const searchingRooms = roomQuery.trim().length > 0
  const searchingPeople = nameQuery.trim().length > 0

  return (
    <div className="grid grid-cols-1 gap-6">
      <h1 className="text-2xl font-semibold text-ink">{t('housing.heading')}</h1>

      <BuildingTabs buildingId={buildingId} />

      <div className="grid gap-3 sm:grid-cols-2">
        <div className="flex items-center gap-2">
          <DoorClosed aria-hidden="true" className="size-5 shrink-0 text-steel" />
          <Input
            value={roomQuery}
            maxLength={32}
            aria-label={t('housing.searchRoom')}
            placeholder={t('housing.searchRoom')}
            onChange={(event) => setRoomQuery(event.target.value)}
          />
        </div>
        {mayReadPeople ? (
          <div className="flex items-center gap-2">
            <User aria-hidden="true" className="size-5 shrink-0 text-steel" />
            <Input
              value={nameQuery}
              maxLength={120}
              aria-label={t('housing.searchResident')}
              placeholder={t('housing.searchResident')}
              onChange={(event) => setNameQuery(event.target.value)}
            />
          </div>
        ) : null}
      </div>

      {floors.isError ? <RequestRefusal error={floors.error} /> : null}

      {mayManageRooms ? (
        <div>
          <Button type="button" onClick={() => setAdding((open) => !open)}>
            <Plus aria-hidden="true" />
            {t('housing.addRoom')}
          </Button>
        </div>
      ) : null}

      {adding ? (
        <RoomForm buildingId={buildingId} room={null} onDone={() => setAdding(false)} />
      ) : null}

      {searchingRooms ? <FoundRooms buildingId={buildingId} query={roomQuery} /> : null}

      {searchingPeople ? <FoundResidents buildingId={buildingId} query={nameQuery} /> : null}

      {!searchingRooms && !searchingPeople ? (
        <>
          {floors.isPending && !floors.isError ? (
            <div className="grid gap-2" aria-hidden="true">
              <Skeleton className="h-24 w-full" />
              <Skeleton className="h-24 w-full" />
            </div>
          ) : null}

          {list !== null && list.length === 0 ? (
            <p className="m-0 text-steel">{t('housing.empty')}</p>
          ) : null}

          <ul className="m-0 grid list-none grid-cols-[repeat(auto-fill,minmax(15rem,1fr))] gap-3 p-0">
            {(list ?? []).map((floor) => (
              <li key={floor.floor} className="min-w-0">
                <FloorCard
                  floor={floor}
                  to={`/buildings/${buildingId}/rooms/floor/${floor.floor}`}
                />
              </li>
            ))}
          </ul>
        </>
      ) : null}
    </div>
  )
}

/**
 * One floor, read at a glance.
 *
 * Three things in a column and each of them says the same figure twice, once
 * drawn and once written. The bar is the places of the whole floor and fills
 * from the left with the ones that are taken; the squares are the rooms, one
 * each, dark where nobody else fits; both lines under them carry the words, so
 * nothing on the card depends on telling two tones apart. The whole card is the
 * link, because a floor is one thing and there is nothing else on it to press.
 */
function FloorCard({ floor, to }: { floor: Floor; to: string }) {
  const { t } = useTranslation()
  const formatters = useFormatters()

  const taken = Math.max(floor.beds_count - floor.free_beds, 0)
  const share = floor.beds_count > 0 ? (taken / floor.beds_count) * 100 : 0

  return (
    <Link
      to={to}
      className="group grid h-full content-start gap-4 border border-rule bg-paper-raised px-4 py-4 transition-colors hover:border-prussian hover:bg-paper"
    >
      <div className="flex items-baseline gap-2">
        <span className="label-caps">{t('housing.floorLabel')}</span>
        <span className="font-mono text-[2.25rem] leading-none font-semibold tabular-nums text-ink">
          {floor.floor}
        </span>
      </div>

      <div className="grid gap-1.5">
        <div aria-hidden="true" className="flex h-3 w-full border border-rule bg-prussian-wash">
          <div
            className="bg-prussian transition-colors group-hover:bg-prussian-bright"
            style={{ width: `${share}%` }}
          />
        </div>
        <p className="m-0 text-pretty text-steel">
          {t('housing.placesTaken', {
            taken: formatters.count(taken),
            total: formatters.count(floor.beds_count),
          })}
        </p>
      </div>

      <div className="grid gap-1.5">
        <RoomSquares total={floor.rooms_count} free={floor.rooms_with_free_beds} />
        <p className="m-0 text-pretty text-steel">
          {t('housing.floorRooms', {
            rooms: formatters.count(floor.rooms_count),
            free: formatters.count(floor.rooms_with_free_beds),
          })}
        </p>
      </div>
    </Link>
  )
}

/** Rooms matching part of a number, searched by the database and paged by it. */
function FoundRooms({ buildingId, query }: { buildingId: number; query: string }) {
  const { t } = useTranslation()
  const search = useDebounced(query.trim())
  const paging = usePagination({ resetKey: `${buildingId}|${search}` })

  const rooms = useListBuildingRooms<listBuildingRoomsResponse, ApiError>(
    buildingId,
    { q: search, ...paging.params },
    { query: { enabled: search.length > 0, retry: false, placeholderData: keepPreviousData } },
  )

  const body = rooms.data?.status === 200 ? rooms.data.data : null

  return (
    <Panel className="min-w-0" caption={t('housing.searchRoom')}>
      {rooms.isError ? (
        <div className="px-4 py-4">
          <RequestRefusal error={rooms.error} />
        </div>
      ) : null}

      {body !== null && body.data.length === 0 ? (
        <p className="px-4 py-6 text-steel">{t('housing.nothingFound')}</p>
      ) : null}

      <ul className="m-0 grid list-none grid-cols-[repeat(auto-fill,minmax(14rem,1fr))] gap-3 p-4">
        {(body?.data ?? []).map((room) => (
          <li key={room.id} className="min-w-0">
            <RoomCard room={room} to={`/buildings/${buildingId}/rooms/${room.id}`} />
          </li>
        ))}
      </ul>

      <Pagination
        page={paging.page}
        lastPage={lastPageOf(body?.meta)}
        onPageChange={paging.setPage}
        disabled={rooms.isFetching}
      />
    </Panel>
  )
}

/** Residents matching part of a name. The card behind each is FR-06's. */
function FoundResidents({ buildingId, query }: { buildingId: number; query: string }) {
  const { t } = useTranslation()
  const search = useDebounced(query.trim())
  const paging = usePagination({ resetKey: `${buildingId}|${search}` })

  const people = useListBuildingUsers<listBuildingUsersResponse, ApiError>(
    buildingId,
    { q: search, role: RoleCode.student, ...paging.params },
    { query: { enabled: search.length > 0, retry: false, placeholderData: keepPreviousData } },
  )

  const body = people.data?.status === 200 ? people.data.data : null

  return (
    <Panel className="min-w-0" caption={t('housing.searchResident')}>
      {people.isError ? (
        <div className="px-4 py-4">
          <RequestRefusal error={people.error} />
        </div>
      ) : null}

      {body !== null && body.data.length === 0 ? (
        <p className="px-4 py-6 text-steel">{t('housing.nothingFound')}</p>
      ) : null}

      <ul className="m-0 list-none p-0">
        {(body?.data ?? []).map((person) => (
          <li key={person.id} className="border-b border-rule/70 last:border-b-0">
            <Link
              to={`/residents/${person.id}`}
              className="flex items-center gap-2 px-4 py-3 text-prussian underline"
            >
              <User aria-hidden="true" className="size-5 shrink-0 text-steel" />
              {person.full_name}
            </Link>
          </li>
        ))}
      </ul>

      <Pagination
        page={paging.page}
        lastPage={lastPageOf(body?.meta)}
        onPageChange={paging.setPage}
        disabled={people.isFetching}
      />
    </Panel>
  )
}

import { useState, type FormEvent } from 'react'
import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  useCreateRoom,
  useListBuildingRooms,
  useUpdateRoom,
  type listBuildingRoomsResponse,
} from '@/api/generated/dormitory'
import { RoomStatus, RoomType, type Room, type RoomInput } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { may, Permission } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { BuildingTabs } from '@/components/building-tabs'
import { FormField, selectClassName } from '@/components/form-field'
import { RoomPlaces } from '@/components/housing/room-places'
import { RoomSummary } from '@/components/housing/room-summary'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { useBuildingOccupancy } from '@/hooks/use-building-occupancy'
import { useHousingRefresh } from '@/lib/housing-cache'

/**
 * FR-02, the register of rooms and places of one dormitory.
 *
 * Four figures travel with every room and none of them is computed here:
 * `capacity` is the ceiling, `beds_count` the places registered,
 * `occupied_beds_count` the places held and `free_places` the remainder the
 * register would refuse a further place on. The last is easy to mistake for
 * «vacancies for residents» and is not: a room of four registered places with
 * nobody in it has a free remainder of zero and four empty beds. The screen
 * therefore says both, in words.
 */

type RoomFields = {
  number: string
  floor: string
  capacity: string
  type: RoomType
  status: RoomStatus
}

const EMPTY_ROOM: RoomFields = {
  number: '',
  floor: '1',
  capacity: '2',
  type: RoomType.corridor,
  status: RoomStatus.in_service,
}

function payloadOf(fields: RoomFields): RoomInput {
  return {
    number: fields.number.trim(),
    floor: Number(fields.floor),
    capacity: Number(fields.capacity),
    type: fields.type,
    status: fields.status,
  }
}

export function RoomsPage() {
  const { t } = useTranslation()
  const params = useParams<{ buildingId: string }>()
  const buildingId = Number(params.buildingId)
  const { session } = useSession()

  const [expanded, setExpanded] = useState<number | null>(null)
  const [editing, setEditing] = useState<number | null>(null)
  const [adding, setAdding] = useState(false)

  const user = session.status === 'authenticated' ? session.user : null
  const mayManageRooms = user !== null && may(user, Permission.manageRooms, buildingId)
  const mayManageResidencies =
    user !== null && may(user, Permission.manageResidencies, buildingId)
  const mayReadPeople = user !== null && may(user, Permission.viewPeople, buildingId)
  const mayReadCards = user !== null && may(user, Permission.viewResidentCard, buildingId)

  const rooms = useListBuildingRooms<listBuildingRoomsResponse, ApiError>(buildingId, {
    query: { enabled: Number.isInteger(buildingId), retry: false },
  })

  const occupancy = useBuildingOccupancy(buildingId, {
    rollEnabled: mayReadPeople,
    resolveEnabled: mayReadCards && expanded !== null,
  })

  const list = rooms.data?.status === 200 ? rooms.data.data.data : null

  return (
    <div className="grid grid-cols-1 gap-6">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">{t('rooms.heading')}</h1>
        <p className="mt-1 text-steel">{t('rooms.lead')}</p>
      </div>

      <BuildingTabs buildingId={buildingId} />

      {rooms.isError ? <RequestRefusal error={rooms.error} /> : null}

      {mayManageRooms ? (
        <div>
          <Button type="button" onClick={() => setAdding((open) => !open)}>
            {t('rooms.add')}
          </Button>
        </div>
      ) : null}

      {adding ? (
        <Panel caption={t('rooms.addTitle')}>
          <RoomForm buildingId={buildingId} room={null} onDone={() => setAdding(false)} />
        </Panel>
      ) : null}

      {rooms.isPending && !rooms.isError ? (
        <div className="grid gap-2" aria-hidden="true">
          <Skeleton className="h-20 w-full" />
          <Skeleton className="h-20 w-full" />
        </div>
      ) : null}

      {list !== null ? (
        <Panel
          className="min-w-0"
          caption={t('rooms.heading')}
          aside={t('rooms.count', { count: list.length })}
        >
          {list.length === 0 ? (
            <p className="px-4 py-6 text-steel">{t('rooms.empty')}</p>
          ) : (
            <ul className="m-0 list-none p-0">
              {list.map((room) => (
                <li key={room.id} className="border-b border-rule/70 last:border-b-0">
                  <div className="grid gap-3 px-4 py-4">
                    <RoomSummary room={room} />

                    <div className="flex flex-wrap gap-2">
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        aria-expanded={expanded === room.id}
                        onClick={() =>
                          setExpanded((current) => (current === room.id ? null : room.id))
                        }
                      >
                        {expanded === room.id ? t('rooms.hidePlaces') : t('rooms.showPlaces')}
                      </Button>
                      {mayManageRooms ? (
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          onClick={() =>
                            setEditing((current) => (current === room.id ? null : room.id))
                          }
                        >
                          {t('rooms.edit')}
                        </Button>
                      ) : null}
                    </div>

                    {editing === room.id ? (
                      <RoomForm
                        buildingId={buildingId}
                        room={room}
                        onDone={() => setEditing(null)}
                      />
                    ) : null}

                    {expanded === room.id ? (
                      <RoomPlaces
                        room={room}
                        occupancy={occupancy}
                        mayManageRooms={mayManageRooms}
                        mayManageResidencies={mayManageResidencies}
                      />
                    ) : null}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      ) : null}
    </div>
  )
}

/** The fields of a room, for a new one and for an edit alike. */
function RoomForm({
  buildingId,
  room,
  onDone,
}: {
  buildingId: number
  room: Room | null
  onDone: () => void
}) {
  const { t } = useTranslation()
  const refresh = useHousingRefresh()
  const [fields, setFields] = useState<RoomFields>(() =>
    room === null
      ? EMPTY_ROOM
      : {
          number: room.number,
          floor: String(room.floor),
          capacity: String(room.capacity),
          type: room.type ?? RoomType.corridor,
          status: room.status ?? RoomStatus.in_service,
        },
  )

  const create = useCreateRoom<ApiError>()
  const update = useUpdateRoom<ApiError>()
  const pending = create.isPending || update.isPending
  const error = create.isError ? create.error : update.isError ? update.error : null

  function set<K extends keyof RoomFields>(key: K, value: RoomFields[K]) {
    setFields((current) => ({ ...current, [key]: value }))
  }

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const data = payloadOf(fields)
    const done = {
      onSuccess: () => {
        refresh()
        onDone()
      },
    }
    if (room === null) {
      create.mutate({ building: buildingId, data }, done)
    } else {
      update.mutate({ room: room.id, data }, done)
    }
  }

  const id = room === null ? 'room-new' : `room-${room.id}`

  return (
    <form className="grid gap-4 border border-rule bg-paper px-3 py-3" onSubmit={submit}>
      {error !== null ? <RequestRefusal error={error} /> : null}

      <FormField id={`${id}-number`} label={t('fields.number')} note={t('rooms.numberNote')}>
        <Input
          id={`${id}-number`}
          value={fields.number}
          maxLength={32}
          required
          onChange={(event) => set('number', event.target.value)}
        />
      </FormField>

      <div className="grid gap-4 sm:grid-cols-2">
        <FormField id={`${id}-floor`} label={t('fields.floor')}>
          <Input
            id={`${id}-floor`}
            type="number"
            inputMode="numeric"
            min={0}
            max={100}
            value={fields.floor}
            required
            onChange={(event) => set('floor', event.target.value)}
          />
        </FormField>

        <FormField
          id={`${id}-capacity`}
          label={t('fields.capacity')}
          note={t('rooms.capacityNote')}
        >
          <Input
            id={`${id}-capacity`}
            type="number"
            inputMode="numeric"
            min={1}
            max={32}
            value={fields.capacity}
            required
            onChange={(event) => set('capacity', event.target.value)}
          />
        </FormField>
      </div>

      <FormField id={`${id}-type`} label={t('fields.type')}>
        <select
          id={`${id}-type`}
          className={selectClassName}
          value={fields.type}
          onChange={(event) => set('type', event.target.value as RoomType)}
        >
          {Object.values(RoomType).map((value) => (
            <option key={value} value={value}>
              {t(`roomType.${value}`)}
            </option>
          ))}
        </select>
      </FormField>

      <FormField id={`${id}-status`} label={t('fields.status')} note={t('rooms.statusNote')}>
        <select
          id={`${id}-status`}
          className={selectClassName}
          value={fields.status}
          onChange={(event) => set('status', event.target.value as RoomStatus)}
        >
          {Object.values(RoomStatus).map((value) => (
            <option key={value} value={value}>
              {t(`roomStatus.${value}`)}
            </option>
          ))}
        </select>
      </FormField>

      <div className="flex flex-wrap gap-2">
        <Button type="submit" disabled={pending}>
          {pending ? `${t('common.saving')}…` : t('common.save')}
        </Button>
        <Button type="button" variant="outline" onClick={onDone}>
          {t('common.cancel')}
        </Button>
      </div>
    </form>
  )
}

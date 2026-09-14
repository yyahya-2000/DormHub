import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'

import { useCreateRoom, useUpdateRoom } from '@/api/generated/dormitory'
import { RoomType, type Room, type RoomInput } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { FormField, selectClassName } from '@/components/form-field'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useHousingRefresh } from '@/lib/housing-cache'

/**
 * FR-02. The fields of a room, for a new one and for an edit alike. A room has
 * no status any more: a place carries one, and that is where a bed nobody may
 * take is marked.
 */
type RoomFields = {
  number: string
  floor: string
  capacity: string
  type: RoomType
}

function payloadOf(fields: RoomFields): RoomInput {
  return {
    number: fields.number.trim(),
    floor: Number(fields.floor),
    capacity: Number(fields.capacity),
    type: fields.type,
  }
}

export function RoomForm({
  buildingId,
  room,
  defaultFloor,
  onDone,
}: {
  buildingId: number
  room: Room | null
  defaultFloor?: number
  onDone: () => void
}) {
  const { t } = useTranslation()
  const refresh = useHousingRefresh()
  const [fields, setFields] = useState<RoomFields>(() =>
    room === null
      ? {
          number: '',
          floor: String(defaultFloor ?? 1),
          capacity: '2',
          type: RoomType.corridor,
        }
      : {
          number: room.number,
          floor: String(room.floor),
          capacity: String(room.capacity),
          type: room.type ?? RoomType.corridor,
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

      <FormField id={`${id}-number`} label={t('fields.number')} required>
        <Input
          id={`${id}-number`}
          value={fields.number}
          maxLength={32}
          required
          onChange={(event) => set('number', event.target.value)}
        />
      </FormField>

      <div className="grid gap-4 sm:grid-cols-2">
        <FormField id={`${id}-floor`} label={t('fields.floor')} required>
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

        <FormField id={`${id}-capacity`} label={t('fields.capacity')} required>
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

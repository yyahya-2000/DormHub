import { useState, type FormEvent } from 'react'
import { Clock, Layers, Pencil } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  useCreateBuilding,
  useDeleteBuilding,
  useListBuildings,
  useUpdateBuilding,
  type listBuildingsResponse,
} from '@/api/generated/dormitory'
import type { Building, BuildingInput } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { keepsBuildingRegister } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { FormField } from '@/components/form-field'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { useFormatters } from '@/lib/format'
import { useHousingRefresh } from '@/lib/housing-cache'

/**
 * FR-01, the register of dormitories.
 *
 * The list is asked for by everyone and narrowed by the server: a resident sees
 * the one building their grant names, the administrator sees all of them. That
 * is the «empty scoped result» half of FR-07 and the reason this page is also
 * where a signed-in person lands — the same route serves as the register and as
 * the way into a building.
 *
 * A row carries one control, and it opens the form. Deleting lives inside that
 * form: it is the rare end of a dormitory's life, it is refused while rooms are
 * attached, and a button for it beside every row of the register is a button
 * pressed by accident.
 */

type BuildingFields = {
  name: string
  address: string
  floorsCount: string
  visitingFrom: string
  visitingTo: string
}

const EMPTY_FORM: BuildingFields = {
  name: '',
  address: '',
  floorsCount: '1',
  visitingFrom: '08:00',
  visitingTo: '23:00',
}

/** `<input type="time">` speaks `HH:MM`; the contract speaks `HH:MM:SS`. */
function toTimeField(value: string | undefined): string {
  return (value ?? '').slice(0, 5)
}

function toContractTime(value: string): string {
  return value.length === 5 ? `${value}:00` : value
}

function formOf(building: Building): BuildingFields {
  return {
    name: building.name,
    address: building.address,
    floorsCount: String(building.floors_count ?? 1),
    visitingFrom: toTimeField(building.visiting_from),
    visitingTo: toTimeField(building.visiting_to),
  }
}

function payloadOf(form: BuildingFields): BuildingInput {
  return {
    name: form.name.trim(),
    address: form.address.trim(),
    floors_count: Number(form.floorsCount),
    visiting_from: toContractTime(form.visitingFrom),
    visiting_to: toContractTime(form.visitingTo),
  }
}

export function BuildingRegisterPage() {
  const { t } = useTranslation()
  const { session } = useSession()
  const [editing, setEditing] = useState<{ kind: 'create' } | { kind: 'edit'; building: Building } | null>(
    null,
  )

  const user = session.status === 'authenticated' ? session.user : null
  const keepsRegister = user !== null && keepsBuildingRegister(user)

  const buildings = useListBuildings<listBuildingsResponse, ApiError>({
    query: { retry: false },
  })

  const rows = buildings.data?.status === 200 ? buildings.data.data.data : null

  return (
    <div className="grid grid-cols-1 gap-6">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">{t('register.heading')}</h1>
      </div>

      {buildings.isError ? <RequestRefusal error={buildings.error} /> : null}

      {keepsRegister ? (
        <div>
          <Button
            type="button"
            onClick={() =>
              setEditing((current) =>
                current !== null && current.kind === 'create' ? null : { kind: 'create' },
              )
            }
          >
            {t('register.add')}
          </Button>
        </div>
      ) : null}

      {editing !== null ? (
        <BuildingForm
          key={editing.kind === 'edit' ? editing.building.id : 'create'}
          building={editing.kind === 'edit' ? editing.building : null}
          onDone={() => setEditing(null)}
        />
      ) : null}

      {buildings.isPending && !buildings.isError ? (
        <div className="grid gap-2" aria-hidden="true">
          <Skeleton className="h-16 w-full" />
          <Skeleton className="h-16 w-full" />
        </div>
      ) : null}

      {rows !== null ? (
        <Panel
          className="min-w-0"
          caption={t('register.heading')}
          aside={t('register.count', { count: rows.length })}
        >
          {rows.length === 0 ? (
            <p className="px-4 py-6 text-steel">{t('register.empty')}</p>
          ) : (
            <ul className="m-0 list-none p-0">
              {rows.map((building) => (
                <li key={building.id} className="border-b border-rule/70 last:border-b-0">
                  <BuildingRow
                    building={building}
                    keepsRegister={keepsRegister}
                    onEdit={() => setEditing({ kind: 'edit', building })}
                  />
                </li>
              ))}
            </ul>
          )}
        </Panel>
      ) : null}
    </div>
  )
}

/** One dormitory of the register: the name, where it is, and the way in. */
function BuildingRow({
  building,
  keepsRegister,
  onEdit,
}: {
  building: Building
  keepsRegister: boolean
  onEdit: () => void
}) {
  const { t } = useTranslation()
  const formatters = useFormatters()

  return (
    <div className="flex items-start justify-between gap-3 px-4 py-4">
      <div className="grid min-w-0 gap-1">
        <h3 className="m-0 min-w-0 text-lg font-semibold text-ink">
          <Link className="text-prussian underline" to={`/buildings/${building.id}`}>
            {building.name}
          </Link>
        </h3>

        <p className="m-0 break-words text-steel">{building.address}</p>

        <p className="m-0 flex flex-wrap items-center gap-x-4 gap-y-1 text-steel">
          <span className="inline-flex items-center gap-1.5">
            <Layers aria-hidden="true" className="size-4" />
            <span className="sr-only">{t('building.fields.floors')}</span>
            {formatters.count(building.floors_count)}
          </span>
          <span className="inline-flex items-center gap-1.5">
            <Clock aria-hidden="true" className="size-4" />
            <span className="sr-only">{t('building.fields.visiting')}</span>
            {t('building.visitingWindow', {
              from: formatters.clock(building.visiting_from),
              to: formatters.clock(building.visiting_to),
            })}
          </span>
        </p>
      </div>

      {keepsRegister ? (
        <Button
          type="button"
          variant="outline"
          size="icon-lg"
          aria-label={t('register.edit')}
          title={t('register.edit')}
          onClick={onEdit}
        >
          <Pencil aria-hidden="true" className="size-5" />
        </Button>
      ) : null}
    </div>
  )
}

/** The same fields for a new dormitory and for an edit; the route differs. */
function BuildingForm({
  building,
  onDone,
}: {
  building: Building | null
  onDone: () => void
}) {
  const { t } = useTranslation()
  const refresh = useHousingRefresh()
  const [form, setForm] = useState<BuildingFields>(() =>
    building === null ? EMPTY_FORM : formOf(building),
  )

  const create = useCreateBuilding<ApiError>()
  const update = useUpdateBuilding<ApiError>()
  const pending = create.isPending || update.isPending
  const error = create.isError ? create.error : update.isError ? update.error : null

  function set<K extends keyof BuildingFields>(key: K, value: BuildingFields[K]) {
    setForm((current) => ({ ...current, [key]: value }))
  }

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const data = payloadOf(form)
    const done = { onSuccess: () => { refresh(); onDone() } }
    if (building === null) {
      create.mutate({ data }, done)
    } else {
      update.mutate({ building: building.id, data }, done)
    }
  }

  return (
    <Panel caption={building === null ? t('register.addTitle') : t('register.editTitle', { name: building.name })}>
      <form className="grid gap-4 px-4 py-4" onSubmit={submit}>
        {error !== null ? <RequestRefusal error={error} /> : null}

        <FormField id="building-name" label={t('fields.name')} required>
          <Input
            id="building-name"
            value={form.name}
            maxLength={255}
            required
            onChange={(event) => set('name', event.target.value)}
          />
        </FormField>

        <FormField id="building-address" label={t('fields.address')} required>
          <Input
            id="building-address"
            value={form.address}
            maxLength={255}
            required
            onChange={(event) => set('address', event.target.value)}
          />
        </FormField>

        <FormField id="building-floors" label={t('fields.floors_count')} required>
          <Input
            id="building-floors"
            type="number"
            inputMode="numeric"
            min={1}
            max={100}
            value={form.floorsCount}
            required
            onChange={(event) => set('floorsCount', event.target.value)}
          />
        </FormField>

        <FormField id="building-visiting-from" label={t('building.fields.visiting')} required>
          <div className="flex items-center gap-2">
            <Input
              id="building-visiting-from"
              type="time"
              value={form.visitingFrom}
              required
              onChange={(event) => set('visitingFrom', event.target.value)}
            />
            <span aria-hidden="true" className="text-steel">
              —
            </span>
            <Input
              id="building-visiting-to"
              type="time"
              aria-label={t('fields.visiting_to')}
              value={form.visitingTo}
              required
              onChange={(event) => set('visitingTo', event.target.value)}
            />
          </div>
        </FormField>

        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="flex flex-wrap gap-2">
            <Button type="submit" disabled={pending}>
              {pending ? `${t('common.saving')}…` : t('common.save')}
            </Button>
            <Button type="button" variant="outline" onClick={onDone}>
              {t('common.cancel')}
            </Button>
          </div>

          {building !== null ? <DeleteBuilding building={building} onDone={onDone} /> : null}
        </div>
      </form>
    </Panel>
  )
}

/**
 * Deleting is two presses rather than one, and it stays inside the form: the
 * server refuses it while rooms are attached and says how many stand in the
 * way, so the refusal belongs where the rest of the form's refusals are.
 */
function DeleteBuilding({ building, onDone }: { building: Building; onDone: () => void }) {
  const { t } = useTranslation()
  const refresh = useHousingRefresh()
  const [confirming, setConfirming] = useState(false)
  const remove = useDeleteBuilding<ApiError>()

  return (
    <div className="grid gap-2">
      <div className="flex flex-wrap gap-2">
        {confirming ? (
          <>
            <Button
              type="button"
              variant="destructive"
              disabled={remove.isPending}
              onClick={() =>
                remove.mutate(
                  { building: building.id },
                  {
                    onSuccess: () => {
                      refresh()
                      onDone()
                    },
                  },
                )
              }
            >
              {remove.isPending ? `${t('common.saving')}…` : t('register.deleteConfirm')}
            </Button>
            <Button type="button" variant="outline" onClick={() => setConfirming(false)}>
              {t('common.cancel')}
            </Button>
          </>
        ) : (
          <Button type="button" variant="outline" onClick={() => setConfirming(true)}>
            {t('register.delete')}
          </Button>
        )}
      </div>

      {remove.isError ? <RequestRefusal error={remove.error} /> : null}
    </div>
  )
}

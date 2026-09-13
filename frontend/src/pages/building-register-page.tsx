import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  useArchiveBuilding,
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
 * Creating, editing, archiving and deleting are the administrator's, and the
 * controls are drawn only for him. The decision is still the server's; a token
 * that is not his gets 403 and the refusal is shown in place of the row.
 *
 * Archiving and deleting are two different answers to «this dormitory is
 * closed». Archiving keeps the row and with it the residency history; deleting
 * removes it and is refused while rooms are attached, with the count of what
 * stands in the way — the second criterion of FR-01, rendered as a sentence
 * rather than as a status code.
 */

type BuildingFields = {
  name: string
  address: string
  floorsCount: string
  visitingFrom: string
  visitingTo: string
  curfewAt: string
  isActive: boolean
}

const EMPTY_FORM: BuildingFields = {
  name: '',
  address: '',
  floorsCount: '1',
  visitingFrom: '08:00',
  visitingTo: '23:00',
  curfewAt: '23:00',
  isActive: true,
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
    curfewAt: toTimeField(building.curfew_at),
    isActive: building.is_active,
  }
}

function payloadOf(form: BuildingFields): BuildingInput {
  return {
    name: form.name.trim(),
    address: form.address.trim(),
    floors_count: Number(form.floorsCount),
    visiting_from: toContractTime(form.visitingFrom),
    visiting_to: toContractTime(form.visitingTo),
    curfew_at: toContractTime(form.curfewAt),
    is_active: form.isActive,
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
        <p className="mt-1 text-steel">
          {keepsRegister ? t('register.leadAdmin') : t('register.leadScoped')}
        </p>
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

/**
 * One dormitory of the register. The two writes that act on a single building —
 * archiving and deleting — hold their mutations here, so that a refusal lands
 * under the row it concerns instead of at the top of a list of twelve.
 */
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
  const refresh = useHousingRefresh()
  const [confirmingDelete, setConfirmingDelete] = useState(false)

  const archive = useArchiveBuilding<ApiError>()
  const remove = useDeleteBuilding<ApiError>()

  return (
    <div className="grid gap-2 px-4 py-4">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h3 className="m-0 min-w-0 text-lg font-semibold text-ink">
          <Link className="text-prussian underline" to={`/buildings/${building.id}`}>
            {building.name}
          </Link>
        </h3>
        <span
          className={
            building.is_active
              ? 'border border-prussian/25 bg-prussian-wash px-2 py-0.5 text-prussian'
              : 'border border-rule bg-paper px-2 py-0.5 text-steel'
          }
        >
          {building.is_active ? t('building.active') : t('building.inactive')}
        </span>
      </div>

      <p className="m-0 break-words text-steel">{building.address}</p>

      <dl className="m-0 grid grid-cols-[minmax(0,auto)_minmax(0,1fr)] gap-x-3 gap-y-1">
        <dt className="label-caps">{t('building.fields.id')}</dt>
        <dd className="m-0">{formatters.count(building.id)}</dd>
        <dt className="label-caps">{t('building.fields.floors')}</dt>
        <dd className="m-0">{formatters.count(building.floors_count)}</dd>
        <dt className="label-caps">{t('building.fields.visiting')}</dt>
        <dd className="m-0">
          {t('building.visitingWindow', {
            from: formatters.clock(building.visiting_from),
            to: formatters.clock(building.visiting_to),
          })}
        </dd>
      </dl>

      {keepsRegister ? (
        <div className="flex flex-wrap gap-2">
          <Button type="button" variant="outline" size="sm" onClick={onEdit}>
            {t('register.edit')}
          </Button>
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={archive.isPending || !building.is_active}
            onClick={() =>
              archive.mutate({ building: building.id }, { onSuccess: () => refresh() })
            }
          >
            {building.is_active ? t('register.archive') : t('register.archived')}
          </Button>
          {confirmingDelete ? (
            <>
              <Button
                type="button"
                variant="destructive"
                size="sm"
                disabled={remove.isPending}
                onClick={() =>
                  remove.mutate(
                    { building: building.id },
                    {
                      onSuccess: () => {
                        setConfirmingDelete(false)
                        refresh()
                      },
                    },
                  )
                }
              >
                {remove.isPending ? `${t('common.saving')}…` : t('register.deleteConfirm')}
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => setConfirmingDelete(false)}
              >
                {t('common.cancel')}
              </Button>
            </>
          ) : (
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => setConfirmingDelete(true)}
            >
              {t('register.delete')}
            </Button>
          )}
        </div>
      ) : null}

      {archive.isError ? <RequestRefusal error={archive.error} /> : null}
      {remove.isError ? <RequestRefusal error={remove.error} /> : null}
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

        <FormField id="building-name" label={t('fields.name')}>
          <Input
            id="building-name"
            value={form.name}
            maxLength={255}
            required
            onChange={(event) => set('name', event.target.value)}
          />
        </FormField>

        <FormField id="building-address" label={t('fields.address')}>
          <Input
            id="building-address"
            value={form.address}
            maxLength={255}
            required
            onChange={(event) => set('address', event.target.value)}
          />
        </FormField>

        <FormField id="building-floors" label={t('fields.floors_count')}>
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

        <div className="grid gap-4 sm:grid-cols-2">
          <FormField
            id="building-visiting-from"
            label={t('fields.visiting_from')}
            note={t('building.visitingNote')}
          >
            <Input
              id="building-visiting-from"
              type="time"
              value={form.visitingFrom}
              required
              onChange={(event) => set('visitingFrom', event.target.value)}
            />
          </FormField>

          <FormField id="building-visiting-to" label={t('fields.visiting_to')}>
            <Input
              id="building-visiting-to"
              type="time"
              value={form.visitingTo}
              required
              onChange={(event) => set('visitingTo', event.target.value)}
            />
          </FormField>
        </div>

        <FormField id="building-curfew" label={t('fields.curfew_at')}>
          <Input
            id="building-curfew"
            type="time"
            value={form.curfewAt}
            required
            onChange={(event) => set('curfewAt', event.target.value)}
          />
        </FormField>

        <label className="flex items-center gap-3">
          <input
            type="checkbox"
            className="size-5 accent-prussian"
            checked={form.isActive}
            onChange={(event) => set('isActive', event.target.checked)}
          />
          <span>{t('fields.is_active')}</span>
        </label>

        <div className="flex flex-wrap gap-2">
          <Button type="submit" disabled={pending}>
            {pending ? `${t('common.saving')}…` : t('common.save')}
          </Button>
          <Button type="button" variant="outline" onClick={onDone}>
            {t('common.cancel')}
          </Button>
        </div>
      </form>
    </Panel>
  )
}

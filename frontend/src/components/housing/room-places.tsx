import { useState, type FormEvent } from 'react'
import { BedSingle, Lock, Plus, Search, User as UserIcon, UserMinus, UserPlus } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  useCreateBed,
  useCreateResidency,
  useListBuildingUsers,
  type listBuildingUsersResponse,
} from '@/api/generated/dormitory'
import { BedStatus, RoleCode, type Bed, type Room, type User } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { FormField } from '@/components/form-field'
import { TerminateResidencyForm } from '@/components/housing/terminate-residency-form'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import type { BuildingOccupancy } from '@/hooks/use-building-occupancy'
import { useDebounced } from '@/hooks/use-debounced'
import { todayIso, useFormatters } from '@/lib/format'
import { useHousingRefresh } from '@/lib/housing-cache'
import { cn } from '@/lib/utils'

/**
 * The places of one room, and the two writes that change who holds them:
 * moving a person in (FR-03) and moving them out (FR-05). A residency names a
 * place, so both live where the place is.
 *
 * Every control is drawn from a capability and decided by the server. A duty
 * officer sees no buttons; if he reaches one anyway the API refuses, and the
 * refusal is rendered where the button was.
 */

const PLACE_TONE: Record<string, string> = {
  free: 'border-prussian/30 bg-paper-raised',
  occupied: 'border-brass/40 bg-brass-wash',
  blocked: 'border-rule bg-paper text-steel',
}

const PLACE_ICON = {
  free: BedSingle,
  occupied: UserIcon,
  blocked: Lock,
}

export function RoomPlaces({
  room,
  occupancy,
  mayManageRooms,
  mayManageResidencies,
}: {
  room: Room
  occupancy: BuildingOccupancy
  mayManageRooms: boolean
  mayManageResidencies: boolean
}) {
  const { t } = useTranslation()
  const [openForm, setOpenForm] = useState<
    { kind: 'place' } | { kind: 'assign'; bed: Bed } | { kind: 'release'; bed: Bed } | null
  >(null)

  const places = room.beds ?? []

  return (
    <div className="grid gap-4">
      <ul className="m-0 grid list-none gap-2 p-0">
        {places.length === 0 ? <li className="text-steel">{t('places.none')}</li> : null}
        {places.map((bed) => (
          <li key={bed.id}>
            <PlaceRow
              bed={bed}
              occupancy={occupancy}
              mayManageResidencies={mayManageResidencies}
              onAssign={() => setOpenForm({ kind: 'assign', bed })}
              onRelease={() => setOpenForm({ kind: 'release', bed })}
            />
          </li>
        ))}
      </ul>

      {mayManageRooms ? (
        <div>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() =>
              setOpenForm((current) =>
                current !== null && current.kind === 'place' ? null : { kind: 'place' },
              )
            }
          >
            <Plus aria-hidden="true" />
            {t('places.add')}
          </Button>
        </div>
      ) : null}

      {openForm?.kind === 'place' ? (
        <AddPlaceForm room={room} onDone={() => setOpenForm(null)} />
      ) : null}

      {openForm?.kind === 'assign' ? (
        <AssignForm room={room} bed={openForm.bed} onDone={() => setOpenForm(null)} />
      ) : null}

      {openForm?.kind === 'release' ? (
        <TerminateResidencyForm
          residencyId={occupancy.byBed.get(openForm.bed.id)?.residencyId ?? null}
          title={t('residency.releaseTitle', {
            name: occupancy.byBed.get(openForm.bed.id)?.fullName ?? t('places.unnamed'),
            bed: openForm.bed.label,
          })}
          onDone={() => setOpenForm(null)}
        />
      ) : null}
    </div>
  )
}

/** One place: its label, its state, who holds it, and what may be done to it. */
function PlaceRow({
  bed,
  occupancy,
  mayManageResidencies,
  onAssign,
  onRelease,
}: {
  bed: Bed
  occupancy: BuildingOccupancy
  mayManageResidencies: boolean
  onAssign: () => void
  onRelease: () => void
}) {
  const { t } = useTranslation()
  const formatters = useFormatters()

  const occupant = occupancy.byBed.get(bed.id) ?? null
  const taken = bed.status === BedStatus.occupied
  const StateIcon = PLACE_ICON[bed.status] ?? Lock

  return (
    <div
      className={cn(
        'flex flex-wrap items-baseline gap-x-4 gap-y-1 border px-3 py-2',
        PLACE_TONE[bed.status] ?? PLACE_TONE.blocked,
      )}
    >
      <span className="inline-flex items-baseline gap-2" title={t(`bedStatus.${bed.status}`)}>
        <StateIcon aria-hidden="true" className="size-5 shrink-0 translate-y-1 text-steel" />
        <span className="font-mono text-lg font-semibold">{bed.label}</span>
        <span className="sr-only">{t(`bedStatus.${bed.status}`)}</span>
      </span>

      <span className="min-w-0 grow basis-full sm:basis-auto">
        {taken ? (
          occupant !== null ? (
            <>
              <Link className="text-prussian underline" to={`/residents/${occupant.userId}`}>
                {occupant.fullName}
              </Link>
              <span className="text-steel">
                {' · '}
                {t('places.since', { date: formatters.date(occupant.movedInAt) })}
              </span>
            </>
          ) : occupancy.canResolve ? (
            <span className="text-steel">{t('places.unnamed')}</span>
          ) : null
        ) : null}
      </span>

      {mayManageResidencies ? (
        <span className="basis-full sm:basis-auto">
          {taken ? (
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={onRelease}
              disabled={occupant?.residencyId === undefined || occupant?.residencyId === null}
            >
              <UserMinus aria-hidden="true" />
              {t('residency.release')}
            </Button>
          ) : bed.status === BedStatus.free ? (
            <Button type="button" variant="outline" size="sm" onClick={onAssign}>
              <UserPlus aria-hidden="true" />
              {t('residency.assign')}
            </Button>
          ) : null}
        </span>
      ) : null}
    </div>
  )
}

/** FR-02, second criterion. The 422 the register answers with names the remainder. */
function AddPlaceForm({ room, onDone }: { room: Room; onDone: () => void }) {
  const { t } = useTranslation()
  const refresh = useHousingRefresh()
  const [label, setLabel] = useState('')
  const createBed = useCreateBed<ApiError>()

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    createBed.mutate(
      { room: room.id, data: { label: label.trim() } },
      {
        onSuccess: () => {
          refresh()
          setLabel('')
          onDone()
        },
      },
    )
  }

  return (
    <form className="grid gap-3 border border-rule bg-paper px-3 py-3" onSubmit={submit}>
      <h4 className="label-caps m-0">{t('places.addTitle', { room: room.number })}</h4>
      {createBed.isError ? <RequestRefusal error={createBed.error} /> : null}
      <FormField id={`bed-label-${room.id}`} label={t('fields.label')} required>
        <Input
          id={`bed-label-${room.id}`}
          value={label}
          maxLength={16}
          required
          onChange={(event) => setLabel(event.target.value)}
        />
      </FormField>
      <div className="flex flex-wrap gap-2">
        <Button type="submit" disabled={createBed.isPending}>
          {createBed.isPending ? `${t('common.saving')}…` : t('common.save')}
        </Button>
        <Button type="button" variant="outline" onClick={onDone}>
          {t('common.cancel')}
        </Button>
      </div>
    </form>
  )
}

/**
 * FR-03. The resident is found by name rather than picked out of a roll: the
 * search runs on the server (`q` against the roll of this building), so a
 * dormitory of six hundred people costs the same as one of six.
 */
function AssignForm({ room, bed, onDone }: { room: Room; bed: Bed; onDone: () => void }) {
  const { t } = useTranslation()
  const refresh = useHousingRefresh()
  const [query, setQuery] = useState('')
  const [chosen, setChosen] = useState<User | null>(null)
  const [contract, setContract] = useState('')
  const [movedInAt, setMovedInAt] = useState(todayIso)
  const assign = useCreateResidency<ApiError>()

  const search = useDebounced(query.trim())
  const found = useListBuildingUsers<listBuildingUsersResponse, ApiError>(
    room.building_id,
    { q: search, role: RoleCode.student, per_page: 8 },
    { query: { enabled: search.length >= 2 && chosen === null, retry: false } },
  )
  const people = found.data?.status === 200 ? found.data.data.data : null

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (chosen === null) {
      return
    }
    assign.mutate(
      {
        data: {
          user_id: chosen.id,
          bed_id: bed.id,
          contract_number: contract.trim(),
          moved_in_at: movedInAt,
        },
      },
      {
        onSuccess: () => {
          refresh()
          onDone()
        },
      },
    )
  }

  const id = `assign-${bed.id}`

  return (
    <form className="grid gap-3 border border-rule bg-paper px-3 py-3" onSubmit={submit}>
      <h4 className="label-caps m-0">
        {t('residency.assignTitle', { room: room.number, bed: bed.label })}
      </h4>

      {assign.isError ? <RequestRefusal error={assign.error} /> : null}

      <div className="grid gap-2">
        <FormField id={`${id}-person`} label={t('fields.full_name')} required>
          <Input
            id={`${id}-person`}
            value={chosen === null ? query : chosen.full_name}
            maxLength={120}
            required
            readOnly={chosen !== null}
            onChange={(event) => setQuery(event.target.value)}
          />
        </FormField>

        {chosen !== null ? (
          <div>
            <Button
              type="button"
              variant="outline"
              size="icon-sm"
              aria-label={t('housing.searchResident')}
              title={t('housing.searchResident')}
              onClick={() => {
                setChosen(null)
                setQuery('')
              }}
            >
              <Search aria-hidden="true" />
            </Button>
          </div>
        ) : people !== null ? (
          <ul className="m-0 grid list-none gap-1 p-0">
            {people.length === 0 ? (
              <li className="text-steel">{t('residency.nobodyFound')}</li>
            ) : null}
            {people.map((person) => (
              <li key={person.id}>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="w-full justify-start"
                  onClick={() => setChosen(person)}
                >
                  <UserIcon aria-hidden="true" />
                  {person.full_name}
                </Button>
              </li>
            ))}
          </ul>
        ) : null}
      </div>

      <FormField id={`${id}-contract`} label={t('fields.contract_number')} required>
        <Input
          id={`${id}-contract`}
          value={contract}
          maxLength={64}
          required
          onChange={(event) => setContract(event.target.value)}
        />
      </FormField>

      <FormField id={`${id}-date`} label={t('fields.moved_in_at')} required>
        <Input
          id={`${id}-date`}
          type="date"
          value={movedInAt}
          required
          onChange={(event) => setMovedInAt(event.target.value)}
        />
      </FormField>

      <div className="flex flex-wrap gap-2">
        <Button type="submit" disabled={assign.isPending || chosen === null}>
          {assign.isPending ? `${t('common.saving')}…` : t('residency.assign')}
        </Button>
        <Button type="button" variant="outline" onClick={onDone}>
          {t('common.cancel')}
        </Button>
      </div>
    </form>
  )
}

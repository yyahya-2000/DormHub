import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import { useCreateBed, useCreateResidency } from '@/api/generated/dormitory'
import { BedStatus, RoomStatus, type Bed, type Room } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { FormField, selectClassName } from '@/components/form-field'
import { TerminateResidencyForm } from '@/components/housing/terminate-residency-form'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import type { BuildingOccupancy, Occupant } from '@/hooks/use-building-occupancy'
import { todayIso, useFormatters } from '@/lib/format'
import { useHousingRefresh } from '@/lib/housing-cache'
import { cn } from '@/lib/utils'

/**
 * The places of one room, and the two writes that change who holds them.
 *
 * The same component stands under the register of rooms (FR-02) and under the
 * floor plan (FR-43), because they ask the same question of a room and differ
 * only in how they got to it. Moving a person in (FR-03) and moving them out
 * (FR-05) are here rather than on a screen of their own: a residency names a
 * place, and the place is what the person at the keyboard is looking at.
 *
 * Every control below is drawn from a capability and decided by the server.
 * A duty officer reads the register and sees no buttons; if he reaches one
 * anyway the API refuses, and the refusal is rendered where the button was.
 */

const PLACE_TONE: Record<string, string> = {
  free: 'border-prussian/30 bg-paper-raised',
  occupied: 'border-brass/40 bg-brass-wash',
  blocked: 'border-rule bg-paper text-steel',
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
  const acceptsResidents = room.status === undefined || room.status === RoomStatus.in_service

  return (
    <div className="grid gap-4">
      <ul className="m-0 grid list-none gap-2 p-0">
        {places.length === 0 ? (
          <li className="text-steel">{t('places.none')}</li>
        ) : null}
        {places.map((bed) => (
          <li key={bed.id}>
            <PlaceRow
              bed={bed}
              occupant={occupancy.byBed.get(bed.id) ?? null}
              occupancy={occupancy}
              acceptsResidents={acceptsResidents}
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
            {t('places.add')}
          </Button>
          <p className="mt-2 mb-0 text-steel">
            {t('places.remainder', {
              count: room.free_places,
              capacity: room.capacity,
            })}
          </p>
        </div>
      ) : null}

      {openForm?.kind === 'place' ? (
        <AddPlaceForm room={room} onDone={() => setOpenForm(null)} />
      ) : null}

      {openForm?.kind === 'assign' ? (
        <AssignForm
          room={room}
          bed={openForm.bed}
          occupancy={occupancy}
          onDone={() => setOpenForm(null)}
        />
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
  occupant,
  occupancy,
  acceptsResidents,
  mayManageResidencies,
  onAssign,
  onRelease,
}: {
  bed: Bed
  occupant: Occupant | null
  occupancy: BuildingOccupancy
  acceptsResidents: boolean
  mayManageResidencies: boolean
  onAssign: () => void
  onRelease: () => void
}) {
  const { t } = useTranslation()
  const formatters = useFormatters()

  const taken = bed.status === BedStatus.occupied

  return (
    <div
      className={cn(
        'flex flex-wrap items-baseline gap-x-4 gap-y-1 border px-3 py-2',
        PLACE_TONE[bed.status] ?? PLACE_TONE.blocked,
      )}
    >
      <span className="font-mono text-lg font-semibold">
        {t('places.label', { label: bed.label })}
      </span>
      <span className="text-steel">{t(`bedStatus.${bed.status}`)}</span>

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
          ) : occupancy.isResolving ? (
            <span className="text-steel">{t('places.resolving')}…</span>
          ) : occupancy.canResolve ? (
            // The card was asked for and refused, which is worth saying: the
            // place is held by somebody this reader was not allowed to see.
            <span className="text-steel">{t('places.unnamed')}</span>
          ) : (
            // The cards were never asked for. The panel above has already said
            // why, and repeating it on every place would only be noise.
            null
          )
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
              {t('residency.release')}
            </Button>
          ) : bed.status === BedStatus.free && acceptsResidents ? (
            <Button type="button" variant="outline" size="sm" onClick={onAssign}>
              {t('residency.assign')}
            </Button>
          ) : (
            <span className="text-steel">
              {acceptsResidents ? t('places.blockedNote') : t('places.roomClosedNote')}
            </span>
          )}
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
      <FormField id={`bed-label-${room.id}`} label={t('fields.label')} note={t('places.labelNote')}>
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
 * FR-03. The candidates are the residents the roll of this building returned;
 * the register has no route for creating an account in this iteration, so a
 * person who is not on the roll cannot be placed from here, and the form says so
 * rather than offering a field that would only ever be refused.
 */
function AssignForm({
  room,
  bed,
  occupancy,
  onDone,
}: {
  room: Room
  bed: Bed
  occupancy: BuildingOccupancy
  onDone: () => void
}) {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const refresh = useHousingRefresh()
  const [userId, setUserId] = useState('')
  const [contract, setContract] = useState('')
  const [movedInAt, setMovedInAt] = useState(todayIso)
  const [ground, setGround] = useState('')
  const assign = useCreateResidency<ApiError>()

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const resident = Number(userId)
    if (!Number.isInteger(resident) || resident <= 0) {
      return
    }
    assign.mutate(
      {
        data: {
          user_id: resident,
          bed_id: bed.id,
          contract_number: contract.trim(),
          moved_in_at: movedInAt,
          ...(ground.trim() === '' ? {} : { ground: ground.trim() }),
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

      {occupancy.residents.length === 0 ? (
        <p className="m-0 text-steel">{t('residency.noCandidates')}</p>
      ) : null}

      <FormField id={`${id}-user`} label={t('fields.user_id')} note={t('residency.candidateNote')}>
        <select
          id={`${id}-user`}
          className={selectClassName}
          value={userId}
          required
          onChange={(event) => setUserId(event.target.value)}
        >
          <option value="">{t('residency.choosePerson')}</option>
          {occupancy.residents.map((person) => {
            const held = [...occupancy.byBed.values()].find(
              (entry) => entry.userId === person.id,
            )
            return (
              <option key={person.id} value={String(person.id)}>
                {held === undefined
                  ? person.full_name
                  : t('residency.alreadyPlaced', { name: person.full_name })}
              </option>
            )
          })}
        </select>
      </FormField>

      <FormField id={`${id}-contract`} label={t('fields.contract_number')}>
        <Input
          id={`${id}-contract`}
          value={contract}
          maxLength={64}
          required
          onChange={(event) => setContract(event.target.value)}
        />
      </FormField>

      <FormField id={`${id}-date`} label={t('fields.moved_in_at')}>
        <Input
          id={`${id}-date`}
          type="date"
          value={movedInAt}
          required
          onChange={(event) => setMovedInAt(event.target.value)}
        />
      </FormField>

      <FormField id={`${id}-ground`} label={t('fields.ground')} note={t('residency.groundNote')}>
        <Input
          id={`${id}-ground`}
          value={ground}
          maxLength={255}
          onChange={(event) => setGround(event.target.value)}
        />
      </FormField>

      <p className="m-0 text-steel">
        {t('residency.assignSummary', {
          room: room.number,
          bed: bed.label,
          date: formatters.date(movedInAt),
        })}
      </p>

      <div className="flex flex-wrap gap-2">
        <Button type="submit" disabled={assign.isPending}>
          {assign.isPending ? `${t('common.saving')}…` : t('residency.assign')}
        </Button>
        <Button type="button" variant="outline" onClick={onDone}>
          {t('common.cancel')}
        </Button>
      </div>
    </form>
  )
}

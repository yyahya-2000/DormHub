import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  useShowResidentCard,
  type showResidentCardResponse,
} from '@/api/generated/dormitory'
import type { Placement, Residency } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { may, Permission } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { TerminateResidencyForm } from '@/components/housing/terminate-residency-form'
import { FieldRow, Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { RoleTag, StatusTag } from '@/components/tags'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { useFormatters } from '@/lib/format'

/**
 * FR-06, the resident card.
 *
 * The route is decided on the person and not on the role: a warden of the
 * dormitory and the administrator read it, a resident reads their own and
 * nobody else's, and the warden of another building is refused. The refusal
 * arrives from the API, which is the point of the criterion — the interface has
 * no list of who may look.
 *
 * Reading the card is written to the audit log, because the card carries
 * personal data. That is worth knowing before opening it, so the page says so.
 *
 * Nothing on the card is filled in with a plausible default. A warden has no
 * study status and no citizenship, and the fields say «not recorded» rather
 * than inventing something that would read as a fact.
 */
export function ResidentCardPage() {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const params = useParams<{ residentId: string }>()
  const residentId = Number(params.residentId)
  const { session } = useSession()

  const [terminating, setTerminating] = useState<number | null>(null)

  const user = session.status === 'authenticated' ? session.user : null

  const card = useShowResidentCard<showResidentCardResponse, ApiError>(residentId, {
    query: { enabled: Number.isInteger(residentId), retry: false },
  })

  const person = card.data?.status === 200 ? card.data.data.data : null

  return (
    <div className="grid grid-cols-1 gap-6">
      <div className="min-w-0">
        <h1 className="break-words text-2xl font-semibold text-ink">
          {person?.full_name ?? t('resident.heading')}
        </h1>
        <p className="mt-1 text-steel">{t('resident.auditNote')}</p>
      </div>

      {card.isError ? <RequestRefusal error={card.error} /> : null}

      {card.isPending && !card.isError ? (
        <div className="grid gap-2" aria-hidden="true">
          <Skeleton className="h-12 w-full" />
          <Skeleton className="h-12 w-full" />
          <Skeleton className="h-12 w-2/3" />
        </div>
      ) : null}

      {person !== null ? (
        <>
          <Panel className="min-w-0" caption={t('resident.heading')}>
            <dl className="m-0">
              <FieldRow label={t('fields.full_name')}>
                <span className="text-lg font-medium">{person.full_name}</span>
              </FieldRow>
              <FieldRow label={t('fields.study_status')}>
                {person.study_status === null || person.study_status === undefined
                  ? t('resident.notRecorded')
                  : t(`studyStatus.${person.study_status}`)}
              </FieldRow>
              <FieldRow label={t('fields.citizenship')}>
                {person.citizenship ?? t('resident.notRecorded')}
              </FieldRow>
              <FieldRow label={t('fields.contact')}>
                {person.contact.email !== undefined ? (
                  <a className="break-words text-prussian underline" href={`mailto:${person.contact.email}`}>
                    {person.contact.email}
                  </a>
                ) : (
                  t('resident.notRecorded')
                )}
                <div className="text-steel">
                  {person.contact.phone ?? t('people.noPhone')}
                </div>
              </FieldRow>
              {person.account_status !== undefined ? (
                <FieldRow label={t('fields.account_status')}>
                  <StatusTag status={person.account_status} />
                </FieldRow>
              ) : null}
              <FieldRow label={t('people.columns.role')}>
                <div className="flex flex-wrap gap-2">
                  {(person.roles ?? []).length === 0
                    ? t('resident.noRoles')
                    : (person.roles ?? []).map((grant) => (
                        <RoleTag key={`${grant.role}-${grant.building_id}`} grant={grant} />
                      ))}
                </div>
              </FieldRow>
            </dl>
          </Panel>

          <Panel className="min-w-0" caption={t('resident.currentPlace')}>
            {person.current_bed === null || person.current_bed === undefined ? (
              <p className="px-4 py-5 text-steel">{t('resident.noPlace')}</p>
            ) : (
              <div className="px-4 py-4">
                <PlacementLine placement={person.current_bed} />
                <p className="mt-1 mb-0 text-steel">
                  {t('places.since', {
                    date: formatters.date(person.current_bed.moved_in_at),
                  })}
                </p>
              </div>
            )}
          </Panel>

          <Panel
            className="min-w-0"
            caption={t('resident.history')}
            aside={t('resident.historyCount', { count: person.residency_history.length })}
          >
            {person.residency_history.length === 0 ? (
              <p className="px-4 py-5 text-steel">{t('resident.noHistory')}</p>
            ) : (
              <ul className="m-0 list-none p-0">
                {person.residency_history.map((residency) => (
                  <li key={residency.id} className="border-b border-rule/70 px-4 py-4 last:border-b-0">
                    <ResidencyRecord residency={residency} />

                    {residency.is_open &&
                    user !== null &&
                    residency.building_id !== null &&
                    residency.building_id !== undefined &&
                    may(user, Permission.manageResidencies, residency.building_id) ? (
                      <div className="mt-3 grid gap-3">
                        {terminating === residency.id ? (
                          <TerminateResidencyForm
                            residencyId={residency.id}
                            title={t('residency.releaseTitle', {
                              name: person.full_name,
                              bed: residency.bed_label ?? t('common.empty'),
                            })}
                            onDone={() => setTerminating(null)}
                          />
                        ) : (
                          <div>
                            <Button
                              type="button"
                              variant="outline"
                              size="sm"
                              onClick={() => setTerminating(residency.id)}
                            >
                              {t('residency.release')}
                            </Button>
                          </div>
                        )}
                      </div>
                    ) : null}
                  </li>
                ))}
              </ul>
            )}
          </Panel>

          <Panel
            className="min-w-0"
            caption={t('resident.obligations')}
            aside={t('resident.obligationsCount', {
              count: person.open_obligations.length,
            })}
          >
            {person.open_obligations.length === 0 ? (
              <p className="px-4 py-5 text-steel">{t('resident.noObligations')}</p>
            ) : (
              <ul className="m-0 list-none p-0">
                {person.open_obligations.map((obligation) => (
                  <li
                    key={obligation.residency_id}
                    className="border-b border-rule/70 px-4 py-4 last:border-b-0"
                  >
                    <p className="m-0 font-medium">
                      {t(`obligationKind.${obligation.kind}`)}
                    </p>
                    <p className="m-0 break-words text-steel">
                      {t('resident.contract', { number: obligation.contract_number })}
                      {' · '}
                      {t('places.since', { date: formatters.date(obligation.since) })}
                    </p>
                    {obligation.placement !== undefined ? (
                      <div className="mt-1">
                        <PlacementLine placement={obligation.placement} />
                      </div>
                    ) : null}
                  </li>
                ))}
              </ul>
            )}
            <p className="border-t border-rule px-4 py-3 text-steel">
              {t('resident.obligationsNote')}
            </p>
          </Panel>
        </>
      ) : null}
    </div>
  )
}

/** Building, room and place of one placement, with a way back to the plan. */
function PlacementLine({ placement }: { placement: Placement }) {
  const { t } = useTranslation()

  const room = placement.room_number ?? t('common.empty')
  const bed = placement.bed_label ?? t('common.empty')

  return (
    <p className="m-0 break-words">
      {placement.building_id !== null && placement.building_id !== undefined ? (
        <Link className="text-prussian underline" to={`/buildings/${placement.building_id}/plan`}>
          {placement.building_name ?? t('roles.scopeBuilding', { id: placement.building_id })}
        </Link>
      ) : (
        (placement.building_name ?? t('common.empty'))
      )}
      {' · '}
      {t('resident.placement', {
        floor: placement.floor ?? t('common.empty'),
        room,
        bed,
      })}
    </p>
  )
}

/** One residency of the history: where, when, on what ground, and whether it is open. */
function ResidencyRecord({ residency }: { residency: Residency }) {
  const { t } = useTranslation()
  const formatters = useFormatters()

  return (
    <div className="grid gap-1">
      <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <span className="font-medium">
          {t('resident.contract', { number: residency.contract_number })}
        </span>
        <span
          className={
            residency.is_open
              ? 'border border-prussian/25 bg-prussian-wash px-2 py-0.5 text-prussian'
              : 'border border-rule bg-paper px-2 py-0.5 text-steel'
          }
        >
          {t(`residencyStatus.${residency.status}`)}
        </span>
        {residency.is_open && !residency.is_current ? (
          <span className="border border-brass/40 bg-brass-wash px-2 py-0.5 text-brass">
            {t('resident.endsAhead')}
          </span>
        ) : null}
      </div>

      <PlacementLine
        placement={{
          building_id: residency.building_id,
          building_name: residency.building_name,
          room_number: residency.room_number,
          floor: residency.floor,
          bed_label: residency.bed_label,
        }}
      />

      <p className="m-0 text-steel">
        {t('resident.period', {
          from: formatters.date(residency.moved_in_at),
          to:
            residency.moved_out_at === null || residency.moved_out_at === undefined
              ? t('resident.stillHere')
              : formatters.date(residency.moved_out_at),
        })}
      </p>

      {residency.moved_in_ground !== null && residency.moved_in_ground !== undefined ? (
        <p className="m-0 break-words text-steel">
          {t('resident.movedInGround', { ground: residency.moved_in_ground })}
        </p>
      ) : null}

      {residency.moved_out_ground !== null && residency.moved_out_ground !== undefined ? (
        <p className="m-0 break-words text-steel">
          {t('resident.movedOutGround', { ground: residency.moved_out_ground })}
        </p>
      ) : null}
    </div>
  )
}

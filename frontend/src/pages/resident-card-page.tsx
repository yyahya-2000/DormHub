import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { KeyRound } from 'lucide-react'

import {
  useShowResidentCard,
  type showResidentCardResponse,
} from '@/api/generated/dormitory'
import type { ApiError } from '@/api/http-client'
import { may, Permission } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
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
 * Nothing on the card is filled in with a plausible default. A staff account
 * created outside the resident route has no citizenship, and the field says
 * «not recorded» rather than inventing something that would read as a fact.
 *
 * The place held right now comes first, because it is what the card is opened
 * for. Its building and room are links only for an account that may open them:
 * a resident carries no `rooms.view`, and a link that could only ever refuse is
 * worse than plain text.
 */
export function ResidentCardPage() {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const params = useParams<{ residentId: string }>()
  const residentId = Number(params.residentId)
  const { session } = useSession()

  const user = session.status === 'authenticated' ? session.user : null

  const card = useShowResidentCard<showResidentCardResponse, ApiError>(residentId, {
    query: { enabled: Number.isInteger(residentId), retry: false },
  })

  const person = card.data?.status === 200 ? card.data.data.data : null
  const place = person?.current_bed ?? null
  const own = user !== null && user.id === residentId

  const buildingId = place?.building_id ?? null
  const opensRooms =
    user !== null && buildingId !== null && may(user, Permission.viewRooms, buildingId)

  return (
    <div className="grid grid-cols-1 gap-6">
      <div className="min-w-0">
        <h1 className="break-words text-2xl font-semibold text-ink">
          {person?.full_name ?? t('resident.heading')}
        </h1>
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
          <Panel className="min-w-0" caption={t('resident.currentPlace')}>
            {place === null ? (
              <p className="m-0 px-4 py-5 text-steel">{t('resident.noPlace')}</p>
            ) : (
              <dl className="m-0">
                <FieldRow label={t('fields.building_id')}>
                  {opensRooms && buildingId !== null ? (
                    <Link
                      className="break-words text-prussian underline"
                      to={`/buildings/${buildingId}/rooms`}
                    >
                      {place.building_name ??
                        t('roles.scopeBuilding', { id: buildingId })}
                    </Link>
                  ) : (
                    <span className="break-words">
                      {place.building_name ?? t('common.empty')}
                    </span>
                  )}
                </FieldRow>
                <FieldRow label={t('fields.floor')}>
                  {place.floor ?? t('common.empty')}
                </FieldRow>
                <FieldRow label={t('fields.number')}>
                  {opensRooms &&
                  buildingId !== null &&
                  place.room_id !== null &&
                  place.room_id !== undefined ? (
                    <Link
                      className="break-words text-prussian underline"
                      to={`/buildings/${buildingId}/rooms/${place.room_id}`}
                    >
                      {place.room_number ?? t('common.empty')}
                    </Link>
                  ) : (
                    <span className="break-words">
                      {place.room_number ?? t('common.empty')}
                    </span>
                  )}
                </FieldRow>
                <FieldRow label={t('fields.label')}>
                  <span className="text-lg font-medium">
                    {place.bed_label ?? t('common.empty')}
                  </span>
                </FieldRow>
                <FieldRow label={t('fields.moved_in_at')}>
                  {formatters.date(place.moved_in_at)}
                </FieldRow>
              </dl>
            )}
          </Panel>

          <Panel className="min-w-0" caption={t('resident.heading')}>
            <dl className="m-0">
              <FieldRow label={t('fields.full_name')}>
                <span className="text-lg font-medium">{person.full_name}</span>
              </FieldRow>
              <FieldRow label={t('fields.citizenship')}>
                {person.citizenship_label ?? person.citizenship ?? t('resident.notRecorded')}
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

            {own ? (
              <div className="border-t border-rule px-4 py-3">
                <Button asChild variant="outline">
                  <Link to="/password">
                    <KeyRound aria-hidden="true" className="size-4" />
                    {t('changePassword.submit')}
                  </Link>
                </Button>
              </div>
            ) : null}
          </Panel>
        </>
      ) : null}
    </div>
  )
}

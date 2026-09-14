import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import { useShowBuilding, type showBuildingResponse } from '@/api/generated/dormitory'
import type { ApiError } from '@/api/http-client'
import { showsBuildingRoll } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { BuildingTabs } from '@/components/building-tabs'
import { FieldRow, Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Skeleton } from '@/components/ui/skeleton'
import { useBuildingStaff } from '@/hooks/use-building-staff'
import { useFormatters } from '@/lib/format'

/**
 * The card of one dormitory, and under it the people who run it.
 *
 * The roll of everyone attached to the building used to sit here, residents
 * included, which made the card of a building the longest screen in the
 * system. The residents belong to the housing screens, where a place has a
 * name; what belongs here is the staff, because «who do I go to» is the
 * question the card is opened with.
 */
export function BuildingPage() {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const { session } = useSession()
  const params = useParams<{ buildingId: string }>()
  const buildingId = Number(params.buildingId)

  const user = session.status === 'authenticated' ? session.user : null
  const maySeeRoll = user !== null && showsBuildingRoll(user, buildingId)

  const building = useShowBuilding<showBuildingResponse, ApiError>(buildingId, {
    query: { enabled: Number.isInteger(buildingId), retry: false },
  })

  const staff = useBuildingStaff(buildingId, Number.isInteger(buildingId) && maySeeRoll)

  const card = building.data?.status === 200 ? building.data.data.data : null

  return (
    <div className="grid grid-cols-1 gap-8">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">{t('building.heading')}</h1>
        {card !== null ? (
          <p className="mt-1 break-words text-lg text-steel">
            {card.name} · {card.address}
          </p>
        ) : null}
      </div>

      <BuildingTabs buildingId={buildingId} />

      {building.isError ? <RequestRefusal error={building.error} /> : null}

      {building.isPending && !building.isError ? (
        <div className="grid gap-2" aria-hidden="true">
          <Skeleton className="h-12 w-full" />
          <Skeleton className="h-12 w-full" />
          <Skeleton className="h-12 w-2/3" />
        </div>
      ) : null}

      {card !== null ? (
        <Panel caption={t('building.heading')}>
          <dl className="m-0">
            <FieldRow label={t('building.fields.name')}>
              <span className="text-lg font-medium">{card.name}</span>
            </FieldRow>
            <FieldRow label={t('building.fields.address')}>{card.address}</FieldRow>
            <FieldRow label={t('building.fields.floors')}>
              {formatters.count(card.floors_count)}
            </FieldRow>
            <FieldRow label={t('building.fields.visiting')}>
              {t('building.visitingWindow', {
                from: formatters.clock(card.visiting_from),
                to: formatters.clock(card.visiting_to),
              })}
            </FieldRow>
          </dl>
        </Panel>
      ) : null}

      {maySeeRoll ? (
        <Panel caption={t('staff.heading')}>
          {staff.error !== null ? (
            <div className="px-4 py-4">
              <RequestRefusal error={staff.error} />
            </div>
          ) : null}

          {staff.isPending && staff.error === null ? (
            <div className="grid gap-2 px-4 py-4" aria-hidden="true">
              <Skeleton className="h-10 w-full" />
              <Skeleton className="h-10 w-full" />
            </div>
          ) : null}

          {!staff.isPending && staff.error === null && staff.total === 0 ? (
            <p className="px-4 py-6 text-steel">{t('staff.rosterEmpty')}</p>
          ) : null}

          {staff.groups.map((group) => (
            <section key={group.role} className="border-b border-rule/70 last:border-b-0">
              <h3 className="label-caps m-0 bg-paper px-4 py-2">{t(`roles.${group.role}`)}</h3>
              <ul className="m-0 list-none p-0">
                {group.people.map((person) => (
                  <li
                    key={person.id}
                    className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-t border-rule/70 px-4 py-3"
                  >
                    <span className="min-w-0 font-medium">{person.full_name}</span>
                    <span className="min-w-0 break-words text-steel">
                      <a className="text-prussian underline" href={`mailto:${person.email}`}>
                        {person.email}
                      </a>
                      {person.phone !== null && person.phone !== undefined
                        ? ` · ${person.phone}`
                        : ''}
                    </span>
                  </li>
                ))}
              </ul>
            </section>
          ))}
        </Panel>
      ) : null}
    </div>
  )
}

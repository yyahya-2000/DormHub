import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  useListBuildingUsers,
  useShowBuilding,
  type listBuildingUsersResponse,
  type showBuildingResponse,
} from '@/api/generated/dormitory'
import type { User } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { showsBuildingRoll } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { FieldRow, Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { RoleTag, StatusTag } from '@/components/tags'
import { Skeleton } from '@/components/ui/skeleton'
import { useFormatters } from '@/lib/format'

/**
 * The protected screen of this slice: one building card and the people holding
 * a role in it — the two routes the contract implements for FR-07.
 *
 * The roll is requested only when the account's grants suggest it will be
 * granted, and that is a matter of not asking for a refusal on every page load.
 * When the request is made anyway — a typed address, a stale grant — the 403
 * from the server is what the reader sees.
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

  const roll = useListBuildingUsers<listBuildingUsersResponse, ApiError>(buildingId, {
    query: { enabled: Number.isInteger(buildingId) && maySeeRoll, retry: false },
  })

  const card = building.data?.status === 200 ? building.data.data.data : null
  const people = roll.data?.status === 200 ? roll.data.data.data : null

  return (
    <div className="grid gap-8">
      <div>
        <h1 className="text-2xl font-semibold text-ink">{t('building.heading')}</h1>
        {card !== null ? (
          <p className="mt-1 text-lg text-steel">
            {card.name} · {card.address}
          </p>
        ) : null}
      </div>

      {building.isError ? <RequestRefusal error={building.error} /> : null}

      {building.isPending && !building.isError ? (
        <div className="grid gap-2" aria-hidden="true">
          <Skeleton className="h-12 w-full" />
          <Skeleton className="h-12 w-full" />
          <Skeleton className="h-12 w-2/3" />
        </div>
      ) : null}

      {card !== null ? (
        <Panel
          caption={t('building.heading')}
          aside={card.is_active ? t('building.active') : t('building.inactive')}
        >
          <dl className="m-0">
            <FieldRow label={t('building.fields.id')}>
              {formatters.count(card.id)}
            </FieldRow>
            <FieldRow label={t('building.fields.name')}>
              <span className="text-lg font-medium">{card.name}</span>
            </FieldRow>
            <FieldRow label={t('building.fields.address')}>{card.address}</FieldRow>
            <FieldRow label={t('building.fields.floors')}>
              {formatters.count(card.floors_count)}
            </FieldRow>
            <FieldRow
              label={t('building.fields.visiting')}
              note={t('building.visitingNote')}
            >
              {t('building.visitingWindow', {
                from: formatters.clock(card.visiting_from),
                to: formatters.clock(card.visiting_to),
              })}
            </FieldRow>
            <FieldRow label={t('building.fields.curfew')}>
              {formatters.clock(card.curfew_at)}
            </FieldRow>
            <FieldRow label={t('building.fields.status')}>
              {card.is_active ? t('building.active') : t('building.inactive')}
            </FieldRow>
          </dl>
        </Panel>
      ) : null}

      {maySeeRoll ? (
        <Panel
          caption={t('people.heading')}
          aside={
            people !== null ? t('people.count', { count: people.length }) : undefined
          }
        >
          {roll.isError ? (
            <div className="px-4 py-4">
              <RequestRefusal error={roll.error} />
            </div>
          ) : null}

          {roll.isPending && !roll.isError ? (
            <div className="grid gap-2 px-4 py-4" aria-hidden="true">
              <Skeleton className="h-10 w-full" />
              <Skeleton className="h-10 w-full" />
            </div>
          ) : null}

          {people !== null ? <PeopleList people={people} /> : null}

          <p className="border-t border-rule px-4 py-3 text-steel">
            {t('people.hint')}
          </p>
        </Panel>
      ) : null}
    </div>
  )
}

/**
 * Two renderings of the same roll: ruled rows on a wide screen, a stack of
 * records below 640 px. NFR-11 asks the interface to work at 360 px, and a
 * four-column table there is a horizontal scrollbar, not a layout.
 */
function PeopleList({ people }: { people: User[] }) {
  const { t } = useTranslation()

  if (people.length === 0) {
    return <p className="px-4 py-6 text-steel">{t('people.empty')}</p>
  }

  return (
    <>
      <table className="hidden w-full border-collapse text-left sm:table">
        <thead>
          <tr className="border-b border-rule">
            <th className="label-caps px-4 py-2">{t('people.columns.person')}</th>
            <th className="label-caps px-4 py-2">{t('people.columns.contacts')}</th>
            <th className="label-caps px-4 py-2">{t('people.columns.role')}</th>
            <th className="label-caps px-4 py-2">{t('people.columns.status')}</th>
          </tr>
        </thead>
        <tbody>
          {people.map((person) => (
            <tr key={person.id} className="border-b border-rule/70 align-top last:border-b-0">
              <td className="px-4 py-3 font-medium">{person.full_name}</td>
              <td className="px-4 py-3">
                <a className="text-prussian underline" href={`mailto:${person.email}`}>
                  {person.email}
                </a>
                <div className="text-steel">
                  {person.phone ?? t('people.noPhone')}
                </div>
              </td>
              <td className="px-4 py-3">
                <div className="flex flex-wrap gap-2">
                  {(person.roles ?? []).map((grant) => (
                    <RoleTag key={`${grant.role}-${grant.building_id}`} grant={grant} />
                  ))}
                </div>
              </td>
              <td className="px-4 py-3">
                <StatusTag status={person.status} />
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <ul className="sm:hidden">
        {people.map((person) => (
          <li key={person.id} className="border-b border-rule/70 px-4 py-4 last:border-b-0">
            <p className="font-medium">{person.full_name}</p>
            <a className="text-prussian underline" href={`mailto:${person.email}`}>
              {person.email}
            </a>
            <p className="text-steel">{person.phone ?? t('people.noPhone')}</p>
            <div className="mt-2 flex flex-wrap gap-2">
              {(person.roles ?? []).map((grant) => (
                <RoleTag key={`${grant.role}-${grant.building_id}`} grant={grant} />
              ))}
              <StatusTag status={person.status} />
            </div>
          </li>
        ))}
      </ul>
    </>
  )
}

import { useState, type FormEvent } from 'react'
import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  useAppointStaff,
  useListBuildingUsers,
  useRevokeStaff,
  type listBuildingUsersResponse,
} from '@/api/generated/dormitory'
import { RoleCode, type RoleGrant, type User } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import {
  appointsStaff,
  grantableRolesIn,
  isSystemAdministrator,
  type KnownRole,
} from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { BuildingTabs } from '@/components/building-tabs'
import { FormField, selectClassName } from '@/components/form-field'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { StatusTag } from '@/components/tags'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { useHousingRefresh } from '@/lib/housing-cache'

/**
 * FR-41, the staff of one dormitory.
 *
 * The screen is a roll with two verbs on it. Who holds which role here comes
 * from `GET /buildings/{id}/users`; granting and revoking are the two routes of
 * FR-41, and both name the building in the path rather than in a payload — the
 * scope a caller's own authorisation is checked against is never something the
 * caller can nominate.
 *
 * Which roles the form offers is read from `grantableRolesIn`, the mirror of
 * `RoleCode::grantableRoles()`. The administrator is offered `warden` and
 * nothing else; the warden of this building is offered manager, duty officer
 * and security; the manager is offered nothing, which is why the tab that leads
 * here is not drawn for him and why this page renders a refusal instead of a
 * form when he types the address by hand. The decision is still the server's
 * (§3.3.2) — every control below can be reached, and the 403 is shown where the
 * control was.
 *
 * A revocation takes a person's access away, so it is deliberately two actions
 * rather than one. It is not a `window.confirm`: that dialog blocks the page,
 * cannot be translated, and on the shared computer of the security post it has
 * been dismissed by muscle memory a hundred times already. What stands in its
 * place is a strip that opens under the row, names the person, the role and
 * what they lose, and holds the only button that actually revokes.
 */

/** The roll is everyone attached to the building; the staff is everyone else. */
function staffGrantsIn(person: User, buildingId: number): RoleGrant[] {
  return (person.roles ?? []).filter(
    (grant) => grant.building_id === buildingId && grant.role !== RoleCode.student,
  )
}

export function BuildingStaffPage() {
  const { t } = useTranslation()
  const { session } = useSession()
  const params = useParams<{ buildingId: string }>()
  const buildingId = Number(params.buildingId)

  const user = session.status === 'authenticated' ? session.user : null
  const grantable = user === null ? [] : grantableRolesIn(user, buildingId)
  const appoints = user !== null && appointsStaff(user, buildingId)

  const roll = useListBuildingUsers<listBuildingUsersResponse, ApiError>(buildingId, {
    query: { enabled: Number.isInteger(buildingId), retry: false },
  })

  const people = roll.data?.status === 200 ? roll.data.data.data : null
  const staff =
    people === null
      ? null
      : people
          .map((person) => ({ person, grants: staffGrantsIn(person, buildingId) }))
          .filter((entry) => entry.grants.length > 0)

  return (
    <div className="grid grid-cols-1 gap-8">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">{t('staff.heading')}</h1>
        <p className="mt-1 text-steel">
          {user !== null && isSystemAdministrator(user)
            ? t('staff.leadAdmin')
            : appoints
              ? t('staff.leadWarden', { id: buildingId })
              : t('staff.leadReadOnly')}
        </p>
      </div>

      <BuildingTabs buildingId={buildingId} />

      {roll.isError ? <RequestRefusal error={roll.error} /> : null}

      {roll.isPending && !roll.isError ? (
        <div className="grid gap-2" aria-hidden="true">
          <Skeleton className="h-16 w-full" />
          <Skeleton className="h-16 w-full" />
        </div>
      ) : null}

      {staff !== null ? (
        <Panel
          className="min-w-0"
          caption={t('staff.rosterHeading')}
          aside={t('staff.rosterCount', { count: staff.length })}
        >
          {staff.length === 0 ? (
            <p className="px-4 py-6 text-steel">{t('staff.rosterEmpty')}</p>
          ) : (
            <ul className="m-0 list-none p-0">
              {staff.map((entry) => (
                <li key={entry.person.id} className="border-b border-rule/70 last:border-b-0">
                  <StaffRow
                    person={entry.person}
                    grants={entry.grants}
                    buildingId={buildingId}
                    grantable={grantable}
                  />
                </li>
              ))}
            </ul>
          )}
          <p className="border-t border-rule px-4 py-3 text-steel">
            {t('staff.residentsNote')}
          </p>
        </Panel>
      ) : null}

      {appoints ? (
        <AppointmentForm
          buildingId={buildingId}
          grantable={grantable}
          candidates={people ?? []}
        />
      ) : null}
    </div>
  )
}

/**
 * One member of staff and every role they hold here. A person can hold two —
 * a duty officer who also stands at the post — so the revocation is attached to
 * the grant and not to the person.
 */
function StaffRow({
  person,
  grants,
  buildingId,
  grantable,
}: {
  person: User
  grants: RoleGrant[]
  buildingId: number
  grantable: KnownRole[]
}) {
  const { t } = useTranslation()

  return (
    <div className="grid gap-3 px-4 py-4">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h3 className="m-0 min-w-0 text-lg font-semibold text-ink">{person.full_name}</h3>
        <StatusTag status={person.status} />
      </div>

      <p className="m-0 min-w-0 break-words">
        <a className="text-prussian underline" href={`mailto:${person.email}`}>
          {person.email}
        </a>
        <span className="text-steel">
          {' · '}
          {t('staff.accountNumber', { id: person.id })}
        </span>
      </p>

      <ul className="m-0 grid list-none gap-2 p-0">
        {grants.map((grant) => (
          <li key={grant.role}>
            <GrantRow
              person={person}
              grant={grant}
              buildingId={buildingId}
              mayRevoke={grantable.includes(grant.role)}
            />
          </li>
        ))}
      </ul>
    </div>
  )
}

/** One grant, and the two-step revocation that can end it. */
function GrantRow({
  person,
  grant,
  buildingId,
  mayRevoke,
}: {
  person: User
  grant: RoleGrant
  buildingId: number
  mayRevoke: boolean
}) {
  const { t } = useTranslation()
  const refresh = useHousingRefresh()
  const [confirming, setConfirming] = useState(false)
  const revoke = useRevokeStaff<ApiError>()

  const roleName = t(`roles.${grant.role}`)

  return (
    <div className="grid gap-2 border border-prussian/25 bg-prussian-wash px-3 py-2">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
        <span className="min-w-0">
          <span className="font-medium text-prussian">{roleName}</span>
          <span className="text-steel">
            {' · '}
            {t('roles.scopeBuilding', { id: buildingId })}
          </span>
        </span>

        {mayRevoke && !confirming ? (
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => setConfirming(true)}
          >
            {t('staff.revoke')}
          </Button>
        ) : null}
      </div>

      {confirming ? (
        /*
         * The step that makes the revocation deliberate. It states the person,
         * the role and the consequence in one sentence, because «Отозвать?» on
         * its own is a question about a word rather than about an access.
         */
        <div className="grid gap-2 border-l-4 border-brick bg-brick-wash px-3 py-3" role="alert">
          <p className="m-0 font-semibold text-ink">
            {t('staff.revokeTitle', { role: roleName, name: person.full_name })}
          </p>
          <p className="m-0 text-ink">
            {t('staff.revokeBody', { name: person.full_name, id: buildingId })}
          </p>
          <div className="flex flex-wrap gap-2">
            <Button
              type="button"
              variant="destructive"
              size="sm"
              disabled={revoke.isPending}
              onClick={() =>
                revoke.mutate(
                  { building: buildingId, user: person.id, role: grant.role },
                  {
                    onSuccess: () => {
                      setConfirming(false)
                      refresh()
                    },
                  },
                )
              }
            >
              {revoke.isPending ? `${t('common.saving')}…` : t('staff.revokeConfirm')}
            </Button>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => setConfirming(false)}
            >
              {t('common.cancel')}
            </Button>
          </div>
        </div>
      ) : null}

      {revoke.isError ? <RequestRefusal error={revoke.error} /> : null}
    </div>
  )
}

/**
 * The appointment itself.
 *
 * The account is named twice over because the contract admits both: the person
 * is usually already attached to this dormitory — a resident being made a duty
 * officer, a security officer being promoted — and `user_id` «need not exist in
 * this dormitory yet» for the case where they are not. There is no route for
 * searching accounts across the system in this iteration, so the second case is
 * the account number, typed. Offering a free-text search that would have
 * nothing behind it would be worse than admitting the number.
 */
function AppointmentForm({
  buildingId,
  grantable,
  candidates,
}: {
  buildingId: number
  grantable: KnownRole[]
  candidates: User[]
}) {
  const { t } = useTranslation()
  const refresh = useHousingRefresh()
  const [subject, setSubject] = useState('')
  const [accountNumber, setAccountNumber] = useState('')
  const [role, setRole] = useState<string>(grantable[0] ?? '')
  const [outcome, setOutcome] = useState<{ name: string; role: string; fresh: boolean } | null>(
    null,
  )

  const appoint = useAppointStaff<ApiError>()

  const byNumber = subject === 'other'
  const chosenId = byNumber ? Number(accountNumber) : Number(subject)
  const chosen = candidates.find((person) => person.id === chosenId) ?? null

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!Number.isInteger(chosenId) || chosenId <= 0 || role === '') {
      return
    }
    appoint.mutate(
      { building: buildingId, data: { user_id: chosenId, role: role as RoleCode } },
      {
        onSuccess: (response) => {
          if (response.status !== 200 && response.status !== 201) {
            return
          }
          setOutcome({
            // The server's own answer names the grant; the name comes from the
            // roll when the roll held the person, and from the number when it
            // did not.
            name: chosen?.full_name ?? t('staff.accountNumber', { id: chosenId }),
            role: response.data.data.role,
            // 200 means the grant already stood. Saying «назначен» there would
            // report an appointment the register did not make.
            fresh: response.status === 201,
          })
          setSubject('')
          setAccountNumber('')
          refresh()
        },
      },
    )
  }

  return (
    <Panel caption={t('staff.appointHeading')}>
      <form className="grid gap-4 px-4 py-4" onSubmit={submit}>
        <p className="m-0 text-steel">{t('staff.appointLead')}</p>

        {appoint.isError ? <RequestRefusal error={appoint.error} /> : null}

        {outcome !== null ? (
          <p
            className="m-0 border-l-4 border-brass bg-brass-wash px-4 py-3 text-ink"
            role="status"
          >
            {outcome.fresh
              ? t('staff.appointed', {
                  name: outcome.name,
                  role: t(`roles.${outcome.role}`),
                  id: buildingId,
                })
              : t('staff.alreadyHeld', {
                  name: outcome.name,
                  role: t(`roles.${outcome.role}`),
                })}
          </p>
        ) : null}

        <FormField id="staff-subject" label={t('staff.subject')} note={t('staff.subjectNote')}>
          <select
            id="staff-subject"
            className={selectClassName}
            value={subject}
            required
            onChange={(event) => setSubject(event.target.value)}
          >
            <option value="">{t('staff.chooseSubject')}</option>
            {candidates.map((person) => (
              <option key={person.id} value={String(person.id)}>
                {person.full_name}
              </option>
            ))}
            <option value="other">{t('staff.subjectOther')}</option>
          </select>
        </FormField>

        {byNumber ? (
          <FormField
            id="staff-account"
            label={t('staff.accountField')}
            note={t('staff.accountFieldNote')}
          >
            <Input
              id="staff-account"
              type="number"
              inputMode="numeric"
              min={1}
              value={accountNumber}
              required
              onChange={(event) => setAccountNumber(event.target.value)}
            />
          </FormField>
        ) : null}

        <FormField id="staff-role" label={t('staff.role')} note={t('staff.roleNote')}>
          <select
            id="staff-role"
            className={selectClassName}
            value={role}
            required
            onChange={(event) => setRole(event.target.value)}
          >
            {grantable.map((code) => (
              <option key={code} value={code}>
                {t(`roles.${code}`)}
              </option>
            ))}
          </select>
        </FormField>

        <div>
          <Button type="submit" disabled={appoint.isPending}>
            {appoint.isPending ? `${t('common.saving')}…` : t('staff.appoint')}
          </Button>
        </div>
      </form>
    </Panel>
  )
}

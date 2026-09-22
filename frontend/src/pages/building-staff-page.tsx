import { useState, type FormEvent } from 'react'
import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  useAppointStaff,
  useListBuildingUsers,
  useRevokeStaff,
  type listBuildingUsersResponse,
} from '@/api/generated/dormitory'
import { RoleCode, type User } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { appointsStaff, grantableRolesIn, type KnownRole } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { BuildingTabs } from '@/components/building-tabs'
import { FormField, selectClassName } from '@/components/form-field'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { StatusTag } from '@/components/tags'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { useBuildingStaff, type StaffRole } from '@/hooks/use-building-staff'
import { useHousingRefresh } from '@/lib/housing-cache'

/**
 * FR-41, the staff of one dormitory, read and written by post.
 *
 * Who holds which role here comes from `GET /buildings/{id}/users`, once per
 * post, so the grouping on the screen is the grouping of the requests behind
 * it. Granting and revoking are the two routes of FR-41, and both name the
 * building in the path rather than in a payload — the scope a caller's own
 * authorisation is checked against is never something the caller can nominate.
 *
 * Which roles the form offers is read from `grantableRolesIn`, the mirror of
 * `RoleCode::grantableRoles()`. The administrator is offered the warden and
 * nothing else; the warden of this building is offered manager and security;
 * the manager is offered nothing, which is why the tab that leads here is not
 * drawn for him. The decision is still the server's (§3.3.2) — the
 * 403 is shown where the control was.
 */

/** A dormitory's candidate list is short; one page holds it. */
const CANDIDATES_PER_PAGE = 100

export function BuildingStaffPage() {
  const { t } = useTranslation()
  const { session } = useSession()
  const params = useParams<{ buildingId: string }>()
  const buildingId = Number(params.buildingId)

  const user = session.status === 'authenticated' ? session.user : null
  const grantable = user === null ? [] : grantableRolesIn(user, buildingId)
  const appoints = user !== null && appointsStaff(user, buildingId)

  const staff = useBuildingStaff(buildingId, Number.isInteger(buildingId))

  const candidates = useListBuildingUsers<listBuildingUsersResponse, ApiError>(
    buildingId,
    { per_page: CANDIDATES_PER_PAGE },
    { query: { enabled: Number.isInteger(buildingId) && appoints, retry: false } },
  )

  const people =
    candidates.data?.status === 200 ? candidates.data.data.data : []

  return (
    <div className="grid grid-cols-1 gap-8">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">{t('staff.heading')}</h1>
      </div>

      <BuildingTabs buildingId={buildingId} />

      {staff.error !== null ? <RequestRefusal error={staff.error} /> : null}

      {staff.isPending && staff.error === null ? (
        <div className="grid gap-2" aria-hidden="true">
          <Skeleton className="h-16 w-full" />
          <Skeleton className="h-16 w-full" />
        </div>
      ) : null}

      {!staff.isPending && staff.error === null ? (
        <Panel
          className="min-w-0"
          caption={t('staff.rosterHeading')}
          aside={t('staff.rosterCount', { count: staff.total })}
        >
          {staff.total === 0 ? (
            <p className="px-4 py-6 text-steel">{t('staff.rosterEmpty')}</p>
          ) : (
            staff.groups.map((group) => (
              <section key={group.role} className="border-b border-rule/70 last:border-b-0">
                <h3 className="label-caps m-0 bg-paper px-4 py-2">{t(`roles.${group.role}`)}</h3>
                <ul className="m-0 list-none p-0">
                  {group.people.map((person) => (
                    <li key={person.id} className="border-t border-rule/70">
                      <StaffRow
                        person={person}
                        role={group.role}
                        buildingId={buildingId}
                        mayRevoke={grantable.includes(group.role)}
                      />
                    </li>
                  ))}
                </ul>
              </section>
            ))
          )}
        </Panel>
      ) : null}

      {appoints ? (
        <AppointmentForm
          buildingId={buildingId}
          grantable={grantable}
          candidates={people}
        />
      ) : null}
    </div>
  )
}

/** One person in one post, and the two presses that can end it. */
function StaffRow({
  person,
  role,
  buildingId,
  mayRevoke,
}: {
  person: User
  role: StaffRole
  buildingId: number
  mayRevoke: boolean
}) {
  const { t } = useTranslation()
  const refresh = useHousingRefresh()
  const [confirming, setConfirming] = useState(false)
  const revoke = useRevokeStaff<ApiError>()

  return (
    <div className="grid gap-2 px-4 py-4">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
        <span className="min-w-0">
          <span className="text-lg font-semibold text-ink">{person.full_name}</span>{' '}
          <StatusTag status={person.status} />
        </span>

        {mayRevoke ? (
          confirming ? (
            <span className="flex flex-wrap gap-2">
              <Button
                type="button"
                variant="destructive"
                size="sm"
                disabled={revoke.isPending}
                onClick={() =>
                  revoke.mutate(
                    { building: buildingId, user: person.id, role },
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
            </span>
          ) : (
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => setConfirming(true)}
            >
              {t('staff.revoke')}
            </Button>
          )
        ) : null}
      </div>

      <p className="m-0 min-w-0 break-words text-steel">
        <a className="text-prussian underline" href={`mailto:${person.email}`}>
          {person.email}
        </a>
        {person.phone !== null && person.phone !== undefined ? ` · ${person.phone}` : ''}
      </p>

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
 * this dormitory yet» for the case where they are not, which is the ordinary
 * case for a warden. There is no route for searching accounts across the
 * system in this iteration, so the second case is the account number, typed.
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

  const appoint = useAppointStaff<ApiError>()

  const byNumber = subject === 'other'
  const chosenId = byNumber ? Number(accountNumber) : Number(subject)

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
        {appoint.isError ? <RequestRefusal error={appoint.error} /> : null}

        <FormField id="staff-subject" label={t('staff.subject')} required>
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
          <FormField id="staff-account" label={t('staff.accountField')} required>
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

        <FormField id="staff-role" label={t('staff.role')} required>
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

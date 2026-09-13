import { useState, type FormEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  useIssueResidentAccount,
  useListBuildingUsers,
  type listBuildingUsersResponse,
} from '@/api/generated/dormitory'
import { StudyStatus, type ResidentAccountInput, type User } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { issuesResidentAccounts } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { BuildingTabs } from '@/components/building-tabs'
import { FormField, selectClassName } from '@/components/form-field'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { useHousingRefresh } from '@/lib/housing-cache'

/**
 * FR-42, the account of an incoming resident.
 *
 * The third acceptance criterion is the whole design of this screen: the
 * one-time credential goes to the resident's own contact and is never shown to
 * the person creating the account. The API holds to it — the 201 carries a
 * `User` and no secret — and an interface that merely obeyed that silently
 * would be read as broken. A warden who presses «Завести» and sees a name
 * appear will look for a password, not find one, and conclude the system
 * failed. So the absence is stated before the button is pressed and again
 * after: what was sent, where it went, and what the resident is to do with it.
 *
 * `password_change_required` is the one thing the response says about the
 * secret, and it says it without carrying one. The panel of accounts still
 * waiting for a first password is built from it, and it is the only evidence
 * this screen can honestly show that the credential is out and unspent.
 *
 * The role is not a field. The grant is always the resident role in the
 * building named by the path, so no staff role can be issued here — there is no
 * parameter through which one could be asked for, on the wire or on the screen.
 */

type AccountFields = {
  fullName: string
  email: string
  phone: string
  studyStatus: string
  citizenship: string
}

const EMPTY_FORM: AccountFields = {
  fullName: '',
  email: '',
  phone: '',
  studyStatus: StudyStatus.enrolled,
  citizenship: '',
}

function payloadOf(form: AccountFields): ResidentAccountInput {
  const phone = form.phone.trim()
  const citizenship = form.citizenship.trim()
  return {
    full_name: form.fullName.trim(),
    email: form.email.trim(),
    ...(phone === '' ? {} : { phone }),
    ...(form.studyStatus === '' ? {} : { study_status: form.studyStatus as StudyStatus }),
    ...(citizenship === '' ? {} : { citizenship }),
  }
}

export function ResidentAccountPage() {
  const { t } = useTranslation()
  const { session } = useSession()
  const refresh = useHousingRefresh()
  const params = useParams<{ buildingId: string }>()
  const buildingId = Number(params.buildingId)

  const user = session.status === 'authenticated' ? session.user : null
  const issues = user !== null && issuesResidentAccounts(user, buildingId)

  const [form, setForm] = useState<AccountFields>(EMPTY_FORM)
  const [issued, setIssued] = useState<User | null>(null)

  const roll = useListBuildingUsers<listBuildingUsersResponse, ApiError>(buildingId, {
    query: { enabled: Number.isInteger(buildingId) && issues, retry: false },
  })

  const create = useIssueResidentAccount<ApiError>()

  const people = roll.data?.status === 200 ? roll.data.data.data : null
  const waiting = people?.filter((person) => person.password_change_required) ?? null

  function set<K extends keyof AccountFields>(key: K, value: AccountFields[K]) {
    setForm((current) => ({ ...current, [key]: value }))
  }

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    create.mutate(
      { building: buildingId, data: payloadOf(form) },
      {
        onSuccess: (response) => {
          if (response.status !== 201) {
            return
          }
          setIssued(response.data.data)
          setForm(EMPTY_FORM)
          refresh()
        },
      },
    )
  }

  return (
    <div className="grid grid-cols-1 gap-8">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">{t('account.heading')}</h1>
        <p className="mt-1 text-steel">
          {issues ? t('account.lead', { id: buildingId }) : t('account.leadReadOnly')}
        </p>
      </div>

      <BuildingTabs buildingId={buildingId} />

      {/*
        Stated before the button rather than after it. The person at the keyboard
        is about to hand over something they will never see, and finding that out
        from an empty screen afterwards is how a working system gets reported as
        broken.
      */}
      {issues ? (
        <>
        <section className="border-l-4 border-brass bg-brass-wash px-4 py-4">
          <h2 className="m-0 text-lg font-semibold text-ink">{t('account.credentialTitle')}</h2>
          <p className="mt-2 mb-0 text-ink">{t('account.credentialBody')}</p>
          <ol className="mt-2 mb-0 grid gap-1 pl-5 text-ink">
            <li>{t('account.credentialStep1')}</li>
            <li>{t('account.credentialStep2')}</li>
            <li>{t('account.credentialStep3')}</li>
          </ol>
        </section>

        {issued !== null ? <IssuedReceipt person={issued} buildingId={buildingId} /> : null}

        <Panel caption={t('account.formHeading')}>
          <form className="grid gap-4 px-4 py-4" onSubmit={submit}>
            {create.isError ? <RequestRefusal error={create.error} /> : null}

            <FormField id="account-name" label={t('fields.full_name')}>
              <Input
                id="account-name"
                value={form.fullName}
                maxLength={255}
                autoComplete="off"
                required
                onChange={(event) => set('fullName', event.target.value)}
              />
            </FormField>

            <FormField
              id="account-email"
              label={t('fields.email')}
              note={t('account.emailNote')}
            >
              <Input
                id="account-email"
                type="email"
                inputMode="email"
                value={form.email}
                maxLength={255}
                autoComplete="off"
                required
                onChange={(event) => set('email', event.target.value)}
              />
            </FormField>

            <FormField id="account-phone" label={t('account.phone')} note={t('account.phoneNote')}>
              <Input
                id="account-phone"
                type="tel"
                inputMode="tel"
                value={form.phone}
                maxLength={32}
                autoComplete="off"
                onChange={(event) => set('phone', event.target.value)}
              />
            </FormField>

            <FormField id="account-study" label={t('fields.study_status')}>
              <select
                id="account-study"
                className={selectClassName}
                value={form.studyStatus}
                onChange={(event) => set('studyStatus', event.target.value)}
              >
                <option value="">{t('account.studyUnknown')}</option>
                {Object.values(StudyStatus).map((code) => (
                  <option key={code} value={code}>
                    {t(`studyStatus.${code}`)}
                  </option>
                ))}
              </select>
            </FormField>

            <FormField
              id="account-citizenship"
              label={t('fields.citizenship')}
              note={t('account.citizenshipNote')}
            >
              <Input
                id="account-citizenship"
                value={form.citizenship}
                maxLength={64}
                autoComplete="off"
                onChange={(event) => set('citizenship', event.target.value)}
              />
            </FormField>

            <p className="m-0 text-steel">{t('account.roleNote', { id: buildingId })}</p>

            <div>
              <Button type="submit" disabled={create.isPending}>
                {create.isPending ? `${t('common.saving')}…` : t('account.submit')}
              </Button>
            </div>
          </form>
        </Panel>

        <Panel
          caption={t('account.waitingHeading')}
          aside={waiting !== null ? t('account.waitingCount', { count: waiting.length }) : undefined}
        >
          {roll.isError ? (
            <div className="px-4 py-4">
              <RequestRefusal error={roll.error} />
            </div>
          ) : null}

          {roll.isPending && !roll.isError ? (
            <div className="grid gap-2 px-4 py-4" aria-hidden="true">
              <Skeleton className="h-10 w-full" />
            </div>
          ) : null}

          {waiting !== null && waiting.length === 0 ? (
            <p className="px-4 py-6 text-steel">{t('account.waitingEmpty')}</p>
          ) : null}

          {waiting !== null && waiting.length > 0 ? (
            <ul className="m-0 list-none p-0">
              {waiting.map((person) => (
                <li
                  key={person.id}
                  className="grid gap-1 border-b border-rule/70 px-4 py-4 last:border-b-0"
                >
                  <p className="m-0 font-medium text-ink">{person.full_name}</p>
                  <p className="m-0 min-w-0 break-words text-steel">{person.email}</p>
                  <p className="m-0 text-steel">{t('account.waitingRow')}</p>
                </li>
              ))}
            </ul>
          ) : null}

          <p className="border-t border-rule px-4 py-3 text-steel">{t('account.waitingNote')}</p>
        </Panel>
        </>
      ) : null}
    </div>
  )
}

/**
 * What the register did, said in full. It names the address the code went to,
 * because that address is the only copy — and it says outright that no password
 * is coming back to this screen, which is the sentence that keeps the warden
 * from hunting for one.
 */
function IssuedReceipt({ person, buildingId }: { person: User; buildingId: number }) {
  const { t } = useTranslation()

  return (
    <section className="border-l-4 border-prussian bg-prussian-wash px-4 py-4" role="status">
      <h2 className="m-0 text-lg font-semibold text-ink">
        {t('account.issuedTitle', { name: person.full_name })}
      </h2>
      <dl className="mt-2 mb-0 grid gap-x-3 gap-y-1 sm:grid-cols-[minmax(0,auto)_minmax(0,1fr)]">
        <dt className="label-caps">{t('fields.email')}</dt>
        <dd className="m-0 min-w-0 break-words">{person.email}</dd>
        <dt className="label-caps">{t('account.issuedRole')}</dt>
        <dd className="m-0">
          {t('roles.student')} · {t('roles.scopeBuilding', { id: buildingId })}
        </dd>
        <dt className="label-caps">{t('account.issuedCredential')}</dt>
        <dd className="m-0">
          {person.password_change_required
            ? t('account.issuedCredentialSent')
            : t('account.issuedCredentialSpent')}
        </dd>
      </dl>
      <p className="mt-3 mb-0 text-ink">{t('account.issuedNoPassword')}</p>
      <p className="mt-2 mb-0">
        <Link className="text-prussian underline" to={`/residents/${person.id}`}>
          {t('account.issuedOpenCard')}
        </Link>
      </p>
    </section>
  )
}

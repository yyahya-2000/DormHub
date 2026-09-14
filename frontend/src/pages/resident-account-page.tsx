import { useState, type FormEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Download } from 'lucide-react'

import {
  useIssueResidentAccount,
  useListCitizenships,
  type listCitizenshipsResponse,
} from '@/api/generated/dormitory'
import type { Citizenship, IssuedAccount } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { issuesResidentAccounts } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { BuildingTabs } from '@/components/building-tabs'
import { FormField, selectClassName } from '@/components/form-field'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { downloadAccountSheet } from '@/lib/account-sheet'
import { useHousingRefresh } from '@/lib/housing-cache'

/**
 * FR-42, the account of an incoming resident.
 *
 * The server generates the password and returns it once, in plain text, on the
 * 201 — the only answer in the API that carries one. It lives in the state of
 * this screen and in the sheet the office prints from it, and nowhere else:
 * not in storage, not in a log, and not in a second request.
 *
 * The role is not a field. The grant is always the resident role in the
 * building named by the path, so no staff role can be issued here.
 */

type AccountFields = {
  fullName: string
  email: string
  phone: string
  citizenship: string
}

const EMPTY_FORM: AccountFields = {
  fullName: '',
  email: '',
  phone: '',
  citizenship: '',
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
  const [issued, setIssued] = useState<IssuedAccount | null>(null)

  const citizenships = useListCitizenships<listCitizenshipsResponse, ApiError>({
    query: { enabled: issues, retry: false },
  })
  const countries =
    citizenships.data?.status === 200 ? citizenships.data.data.data : null

  const create = useIssueResidentAccount<ApiError>()

  function set<K extends keyof AccountFields>(key: K, value: AccountFields[K]) {
    setForm((current) => ({ ...current, [key]: value }))
  }

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    create.mutate(
      {
        building: buildingId,
        data: {
          full_name: form.fullName.trim(),
          email: form.email.trim(),
          phone: form.phone.trim(),
          citizenship: form.citizenship as Citizenship,
        },
      },
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

  if (!issues) {
    return (
      <div className="grid grid-cols-1 gap-8">
        <h1 className="text-2xl font-semibold text-ink">{t('account.heading')}</h1>
        <BuildingTabs buildingId={buildingId} />
      </div>
    )
  }

  return (
    <div className="grid grid-cols-1 gap-8">
      <h1 className="text-2xl font-semibold text-ink">{t('account.heading')}</h1>

      <BuildingTabs buildingId={buildingId} />

      {issued !== null ? <IssuedSheet account={issued} /> : null}

      <Panel caption={t('account.formHeading')}>
        <form className="grid gap-4 px-4 py-4" onSubmit={submit}>
          {create.isError ? <RequestRefusal error={create.error} /> : null}

          <FormField id="account-name" label={t('fields.full_name')} required>
            <Input
              id="account-name"
              value={form.fullName}
              maxLength={255}
              autoComplete="off"
              required
              onChange={(event) => set('fullName', event.target.value)}
            />
          </FormField>

          <FormField id="account-email" label={t('fields.email')} required>
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

          <FormField id="account-phone" label={t('account.phone')} required>
            <Input
              id="account-phone"
              type="tel"
              inputMode="tel"
              value={form.phone}
              maxLength={32}
              autoComplete="off"
              required
              onChange={(event) => set('phone', event.target.value)}
            />
          </FormField>

          <FormField id="account-citizenship" label={t('fields.citizenship')} required>
            <select
              id="account-citizenship"
              className={selectClassName}
              value={form.citizenship}
              required
              onChange={(event) => set('citizenship', event.target.value)}
            >
              <option value="">{t('account.citizenshipPick')}</option>
              {(countries ?? []).map((country) => (
                <option key={country.value} value={country.value}>
                  {country.label}
                </option>
              ))}
            </select>
          </FormField>

          <div>
            <Button type="submit" disabled={create.isPending}>
              {create.isPending ? `${t('common.saving')}…` : t('account.submit')}
            </Button>
          </div>
        </form>
      </Panel>
    </div>
  )
}

/**
 * The password, once.
 *
 * It is shown here and put on the sheet, and there is no route that would hand
 * it back after this screen is left — which is why the download sits beside it
 * rather than on the resident card.
 */
function IssuedSheet({ account }: { account: IssuedAccount }) {
  const { t } = useTranslation()

  const role = t('roles.student')

  return (
    <Panel caption={t('account.issuedTitle')}>
      <dl className="m-0 grid gap-x-6 gap-y-2 px-4 py-4 sm:grid-cols-[minmax(0,10rem)_minmax(0,1fr)]">
        <dt className="label-caps">{t('fields.full_name')}</dt>
        <dd className="m-0 min-w-0 break-words text-ink">{account.user.full_name}</dd>
        <dt className="label-caps">{t('account.login')}</dt>
        <dd className="m-0 min-w-0 break-words text-ink">{account.user.email}</dd>
        <dt className="label-caps">{t('fields.password')}</dt>
        <dd className="m-0 font-mono text-lg font-semibold break-all text-ink">
          {account.password}
        </dd>
        <dt className="label-caps">{t('fields.building_id')}</dt>
        <dd className="m-0 min-w-0 break-words text-ink">{account.building.name}</dd>
        <dt className="label-caps">{t('account.issuedRole')}</dt>
        <dd className="m-0 text-ink">{role}</dd>
      </dl>

      <div className="flex flex-wrap gap-3 border-t border-rule px-4 py-3">
        <Button
          type="button"
          onClick={() =>
            void downloadAccountSheet(
              {
                fullName: account.user.full_name,
                login: account.user.email,
                password: account.password,
                building: account.building.name,
                role,
              },
              {
                title: t('account.sheetTitle'),
                fullName: t('fields.full_name'),
                login: t('account.login'),
                password: t('fields.password'),
                building: t('fields.building_id'),
                role: t('account.issuedRole'),
                fileName: `account-${account.user.id}.pdf`,
              },
            )
          }
        >
          <Download aria-hidden="true" className="size-4" />
          {t('account.downloadSheet')}
        </Button>
        <Button asChild variant="outline">
          <Link to={`/residents/${account.user.id}`}>{t('account.issuedOpenCard')}</Link>
        </Button>
      </div>
    </Panel>
  )
}

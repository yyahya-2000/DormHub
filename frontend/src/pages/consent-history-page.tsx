import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  useListConsents,
  useWithdrawConsent,
  type listConsentsResponse,
} from '@/api/generated/dormitory'
import { ConsentDocument, type ConsentRecord } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { useSession } from '@/auth/session-context'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { useAccountRefresh } from '@/lib/account-cache'
import { useFormatters } from '@/lib/format'

/**
 * FR-35, third and fourth criteria: what was recorded, and taking it back.
 *
 * A withdrawn row stays on this screen with both dates on it — art. 9 part 3 of
 * Federal Law No. 152-FZ puts the burden of proving consent on the operator,
 * and that proof is needed for the period before a withdrawal as much as after
 * it.
 */
export function ConsentHistoryPage() {
  const { t } = useTranslation()
  const { session } = useSession()

  const consents = useListConsents<listConsentsResponse, ApiError>({
    query: { retry: false },
  })

  const records = consents.data?.status === 200 ? consents.data.data.data : null
  const awaiting =
    session.status === 'authenticated' ? (session.user.consent_required ?? []) : []

  return (
    <div className="grid grid-cols-1 gap-6">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">{t('consentHistory.heading')}</h1>
      </div>

      {awaiting.length > 0 ? (
        <section className="border-l-4 border-brass bg-brass-wash px-4 py-4" role="note">
          <h2 className="m-0 text-lg font-semibold text-ink">
            {t('consentHistory.pendingTitle', { count: awaiting.length })}
          </h2>
          <p className="mt-2 mb-0">
            <Link className="text-prussian underline" to="/consent">
              {t('consentHistory.pendingAction')}
            </Link>
          </p>
        </section>
      ) : null}

      {consents.isError ? <RequestRefusal error={consents.error} /> : null}

      {consents.isError ? null : (
        <Panel
          className="min-w-0"
          caption={t('consentHistory.recordsCaption')}
          aside={
            records === null
              ? undefined
              : t('consentHistory.recordsCount', { count: records.length })
          }
        >
          {consents.isPending ? (
            <div className="grid gap-2 px-4 py-4" aria-hidden="true">
              <Skeleton className="h-24 w-full" />
            </div>
          ) : null}

          {records !== null && records.length === 0 ? (
            <p className="px-4 py-6 text-steel">{t('consentHistory.empty')}</p>
          ) : null}

          {records !== null && records.length > 0 ? (
            <ul className="m-0 list-none p-0">
              {records.map((record) => (
                <ConsentRow key={record.id} record={record} />
              ))}
            </ul>
          ) : null}
        </Panel>
      )}
    </div>
  )
}

function ConsentRow({ record }: { record: ConsentRecord }) {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const refresh = useAccountRefresh()
  const [confirming, setConfirming] = useState(false)
  const [nothingStood, setNothingStood] = useState(false)
  const withdraw = useWithdrawConsent<ApiError>()

  const withdrawable =
    record.in_force && record.document === ConsentDocument.resident_personal_data

  return (
    <li className="grid min-w-0 gap-3 border-b border-rule/70 px-4 py-4 last:border-b-0">
      <div className="flex min-w-0 flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <span className="min-w-0 font-medium break-words text-ink">{record.title}</span>
        <span
          className={
            record.in_force
              ? 'inline-block border border-prussian/40 bg-prussian-wash px-2 py-0.5 font-medium whitespace-nowrap text-prussian'
              : 'inline-block border border-rule bg-paper px-2 py-0.5 font-medium whitespace-nowrap text-steel'
          }
        >
          {record.in_force ? t('consentHistory.inForce') : t('consentHistory.withdrawn')}
        </span>
      </div>

      <dl className="m-0 grid gap-x-4 gap-y-1 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)]">
        <dt className="label-caps">{t('consent.fields.document')}</dt>
        <dd className="m-0 break-words">
          {t(`consentDocument.${record.document}`, { defaultValue: record.document })}
        </dd>
        <dt className="label-caps">{t('consent.fields.revision')}</dt>
        <dd className="m-0 break-words">{record.revision}</dd>
        <dt className="label-caps">{t('consent.fields.acceptedAt')}</dt>
        <dd className="m-0">{formatters.dateTime(record.accepted_at)}</dd>
        {record.revoked_at !== null ? (
          <>
            <dt className="label-caps">{t('consent.fields.revokedAt')}</dt>
            <dd className="m-0">{formatters.dateTime(record.revoked_at)}</dd>
          </>
        ) : null}
        {record.ip_address !== null && record.ip_address !== undefined ? (
          <>
            <dt className="label-caps">{t('consent.fields.address')}</dt>
            <dd className="m-0 break-words text-steel">{record.ip_address}</dd>
          </>
        ) : null}
      </dl>

      {withdraw.isError ? <RequestRefusal error={withdraw.error} /> : null}

      {nothingStood ? (
        <p className="m-0 border-l-4 border-brass bg-brass-wash px-3 py-2 text-ink" role="status">
          {t('consentHistory.nothingStood')}
        </p>
      ) : null}

      {withdrawable && !confirming ? (
        <div>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => setConfirming(true)}
          >
            {t('consentHistory.withdrawAction')}
          </Button>
        </div>
      ) : null}

      {withdrawable && confirming ? (
        <div className="grid gap-2 border-l-4 border-brick bg-brick-wash px-3 py-3" role="alert">
          <p className="m-0 font-semibold text-ink">
            {t('consentHistory.confirmTitle', { title: record.title })}
          </p>
          <div className="flex flex-wrap gap-2">
            <Button
              type="button"
              variant="destructive"
              size="sm"
              disabled={withdraw.isPending}
              onClick={() =>
                withdraw.mutate(
                  { document: record.document },
                  {
                    onSuccess: (response) => {
                      setConfirming(false)
                      setNothingStood(response.status === 204)
                      refresh()
                    },
                  },
                )
              }
            >
              {withdraw.isPending
                ? `${t('common.saving')}…`
                : t('consentHistory.withdrawConfirm')}
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

      {record.in_force && !withdrawable ? (
        <p className="m-0 text-steel">{t('consentHistory.atPostOnly')}</p>
      ) : null}
    </li>
  )
}

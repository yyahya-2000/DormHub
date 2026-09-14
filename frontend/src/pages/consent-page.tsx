import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  useGiveConsent,
  useListPendingConsents,
  type listPendingConsentsResponse,
} from '@/api/generated/dormitory'
import { ConsentDocument, type ConsentRecord, type ConsentText } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { useSession } from '@/auth/session-context'
import { ConsentBody } from '@/components/consent-document'
import { LanguageSwitch } from '@/components/language-switch'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { useAccountRefresh } from '@/lib/account-cache'
import { useFormatters } from '@/lib/format'

/**
 * FR-35, first criterion: the text is shown and the decision is recorded.
 *
 * Art. 9 part 1 of Federal Law No. 152-FZ has consent «executed separately from
 * other documents», so this is a screen of its own outside the application
 * frame and no other form in the system records a consent as a by-product. The
 * revision travels with the decision: a record naming a wording the person
 * never read would prove nothing.
 */
export function ConsentPage() {
  const { t } = useTranslation()
  const { session, signOut, isSigningOut } = useSession()
  const refresh = useAccountRefresh()
  const [given, setGiven] = useState<ConsentRecord | null>(null)

  const pending = useListPendingConsents<listPendingConsentsResponse, ApiError>({
    query: { retry: false },
  })

  const documents = pending.data?.status === 200 ? pending.data.data.data : null
  const current = documents?.[0] ?? null

  return (
    <div className="flex min-h-dvh flex-col bg-paper">
      <header className="bg-prussian text-white">
        <div className="mx-auto flex w-full max-w-3xl flex-wrap items-center justify-between gap-3 px-4 py-3">
          <p className="min-w-0 truncate font-semibold tracking-wide">{t('app.fullName')}</p>
          <div className="flex flex-wrap items-center gap-3">
            <LanguageSwitch />
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={signOut}
              disabled={isSigningOut}
              className="border-white/40 bg-transparent text-white hover:bg-white/10 hover:text-white"
            >
              {isSigningOut ? `${t('common.signingOut')}…` : t('common.signOut')}
            </Button>
          </div>
        </div>
        <div className="h-1 bg-brass" />
      </header>

      <main className="mx-auto w-full max-w-3xl grow px-4 py-8">
        <div className="grid grid-cols-1 gap-6">
          <div className="min-w-0">
            <h1 className="text-2xl font-semibold text-ink">{t('consent.heading')}</h1>
            <p className="mt-1 text-steel">
              {session.status === 'authenticated'
                ? t('consent.leadFor', { name: session.user.full_name })
                : t('consent.lead')}
            </p>
          </div>

          {pending.isError ? <RequestRefusal error={pending.error} /> : null}

          {pending.isPending && !pending.isError ? (
            <div className="grid gap-2" aria-hidden="true">
              <Skeleton className="h-24 w-full" />
              <Skeleton className="h-64 w-full" />
            </div>
          ) : null}

          {given !== null ? (
            <ConsentReceipt record={given} onContinue={() => setGiven(null)} />
          ) : null}

          {given === null && documents !== null && documents.length === 0 ? (
            <section className="border border-rule bg-paper-raised px-4 py-6">
              <p className="m-0 text-ink">{t('consent.nothingPending')}</p>
              <p className="mt-3 mb-0 flex flex-wrap gap-3">
                <Link className="text-prussian underline" to="/consents">
                  {t('consent.toHistory')}
                </Link>
                <Link className="text-prussian underline" to="/">
                  {t('consent.toSystem')}
                </Link>
              </p>
            </section>
          ) : null}

          {given === null && current !== null ? (
            <PendingDocument
              document={current}
              remaining={(documents?.length ?? 1) - 1}
              onGiven={(record) => {
                setGiven(record)
                refresh()
              }}
            />
          ) : null}
        </div>
      </main>

      <footer className="border-t border-rule px-4 py-4">
        <p className="mx-auto w-full max-w-3xl text-steel">{t('app.fullName')}</p>
      </footer>
    </div>
  )
}

function PendingDocument({
  document,
  remaining,
  onGiven,
}: {
  document: ConsentText
  remaining: number
  onGiven: (record: ConsentRecord) => void
}) {
  const { t } = useTranslation()
  const give = useGiveConsent<ApiError>()

  const decidable = document.document === ConsentDocument.resident_personal_data

  return (
    <section className="grid gap-6">
      <article className="border border-rule bg-paper-raised">
        <header className="border-b border-rule bg-prussian-wash px-4 py-3">
          <h2 className="m-0 text-lg font-semibold text-ink">{document.title}</h2>
        </header>
        <div className="px-4 py-5">
          <ConsentBody markdown={document.body} />
        </div>
      </article>

      {give.isError ? <RequestRefusal error={give.error} /> : null}

      {decidable ? (
        <section className="border border-rule bg-paper-raised px-4 py-5">
          <h2 className="mt-0 mb-4 text-lg font-semibold text-ink">
            {t('consent.decisionTitle')}
          </h2>
          <div className="flex flex-wrap items-center gap-3">
            <Button
              type="button"
              disabled={give.isPending}
              onClick={() =>
                give.mutate(
                  {
                    data: { document: document.document, revision: document.revision },
                  },
                  {
                    onSuccess: (response) => {
                      if (response.status === 201) {
                        onGiven(response.data.data)
                      }
                    },
                  },
                )
              }
            >
              {give.isPending ? `${t('common.saving')}…` : t('consent.agree')}
            </Button>
            <Button type="button" variant="outline" asChild>
              <Link to="/">{t('consent.later')}</Link>
            </Button>
          </div>

          {remaining > 0 ? (
            <p className="mt-4 mb-0 text-steel">
              {t('consent.remaining', { count: remaining })}
            </p>
          ) : null}
        </section>
      ) : (
        <section className="border-l-4 border-brass bg-brass-wash px-4 py-4" role="note">
          <h2 className="m-0 text-lg font-semibold text-ink">{t('consent.atPostTitle')}</h2>
          <p className="mt-2 mb-0 text-ink">{t('consent.atPostBody')}</p>
        </section>
      )}
    </section>
  )
}

function ConsentReceipt({
  record,
  onContinue,
}: {
  record: ConsentRecord
  onContinue: () => void
}) {
  const { t } = useTranslation()
  const formatters = useFormatters()

  return (
    <section className="border-l-4 border-prussian bg-prussian-wash px-4 py-4" role="status">
      <h2 className="m-0 text-lg font-semibold text-ink">{t('consent.recordedTitle')}</h2>
      <dl className="mt-3 mb-0 grid gap-x-4 gap-y-1 sm:grid-cols-[minmax(0,auto)_minmax(0,1fr)]">
        <dt className="label-caps">{t('consent.fields.document')}</dt>
        <dd className="m-0 break-words">{record.title}</dd>
        <dt className="label-caps">{t('consent.fields.revision')}</dt>
        <dd className="m-0">{record.revision}</dd>
        <dt className="label-caps">{t('consent.fields.acceptedAt')}</dt>
        <dd className="m-0">{formatters.dateTime(record.accepted_at)}</dd>
      </dl>
      <div className="mt-4 flex flex-wrap items-center gap-3">
        <Button type="button" onClick={onContinue}>
          {t('consent.continue')}
        </Button>
        <Button type="button" variant="outline" asChild>
          <Link to="/consents">{t('consent.toHistory')}</Link>
        </Button>
      </div>
    </section>
  )
}

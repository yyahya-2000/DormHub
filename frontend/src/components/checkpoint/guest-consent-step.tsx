import { useTranslation } from 'react-i18next'

import { useRecordGuestConsent } from '@/api/generated/dormitory'
import type { ApiError } from '@/api/http-client'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'

/**
 * FR-35, guest half, taken at the desk and before any entry is recorded.
 *
 * **Why this is a step of its own and not a tick on the entry.** Art. 9 part 1
 * of Federal Law No. 152-FZ has consent «executed separately from other
 * documents», and the contract obeys it on the wire: there is no consent field
 * on the check-in body, and `POST /checkpoint/check-in` answers 409 until the
 * consent route has been called. The screen is that rule applied to the
 * terminal — the card and its buttons go away, the text takes the whole width,
 * and the officer's next press is about this document and nothing else.
 *
 * **Why the guest and not the resident.** The request was filed by the resident
 * while the personal data belong to the guest (§2.7.1). A guest is not a party
 * to the accommodation contract, so consent is the only ground there is, and
 * consent given «on the guest's behalf» at submission would be the operator
 * asserting something no guest ever did.
 *
 * **The revision is the server's, always.** It arrives on the 409 and is sent
 * back untouched. The wording below is a transcript the interface carries so
 * that the person standing at the desk has something to read; it names the
 * revision it was taken from, and when the two disagree the transcript is
 * withheld and the officer is sent to the approved text. A screen showing one
 * wording while recording the identifier of another would prove nothing, and
 * art. 9 part 3 puts the burden of proving consent on the operator.
 */

/**
 * The revision the transcript in the locale files was taken from. It is not the
 * revision that gets recorded — that one comes down with the refusal — and it
 * is only ever compared, never sent.
 */
const TRANSCRIBED_REVISION = '2026-09-01'

export function GuestConsentStep({
  guestRequestId,
  guestName,
  revision,
  onRecorded,
  onCancel,
}: {
  guestRequestId: number
  guestName: string
  revision: string
  onRecorded: () => void
  onCancel: () => void
}) {
  const { t } = useTranslation()
  const record = useRecordGuestConsent<ApiError>()

  const transcriptMatches = revision === TRANSCRIBED_REVISION

  return (
    <section className="border-4 border-prussian bg-paper-raised text-lg">
      <header className="border-b border-rule bg-prussian px-4 py-3 text-white">
        <h2 className="m-0 text-xl font-semibold">{t('checkpoint.consent.heading')}</h2>
        <p className="m-0 text-white/80">
          {t('checkpoint.consent.forGuest', { name: guestName })}
        </p>
      </header>

      <div className="grid gap-4 px-4 py-4">
        <p className="m-0 border-l-4 border-prussian bg-prussian-wash px-3 py-2 text-ink">
          {t('checkpoint.consent.separate')}
        </p>

        <p className="label-caps m-0">
          {t('checkpoint.consent.revision', { revision })}
        </p>

        {transcriptMatches ? (
          <article className="grid gap-3 border border-rule bg-paper px-4 py-4 text-ink">
            <h3 className="m-0 text-xl font-semibold">
              {t('checkpoint.consent.text.title')}
            </h3>
            <p className="m-0">{t('checkpoint.consent.text.draft')}</p>
            <p className="m-0">{t('checkpoint.consent.text.purpose')}</p>
            <p className="m-0">
              <span className="font-semibold">
                {t('checkpoint.consent.text.processedLabel')}
              </span>{' '}
              {t('checkpoint.consent.text.processed')}
            </p>
            <p className="m-0">
              <span className="font-semibold">
                {t('checkpoint.consent.text.notProcessedLabel')}
              </span>{' '}
              {t('checkpoint.consent.text.notProcessed')}
            </p>
            <p className="m-0">
              <span className="font-semibold">
                {t('checkpoint.consent.text.groundLabel')}
              </span>{' '}
              {t('checkpoint.consent.text.ground')}
            </p>
            <p className="m-0">
              <span className="font-semibold">
                {t('checkpoint.consent.text.withdrawalLabel')}
              </span>{' '}
              {t('checkpoint.consent.text.withdrawal')}
            </p>
          </article>
        ) : (
          <article className="border-l-4 border-brick bg-brick-wash px-4 py-4 text-ink">
            <h3 className="m-0 text-xl font-semibold">
              {t('checkpoint.consent.mismatchTitle')}
            </h3>
            <p className="mt-2 mb-0">
              {t('checkpoint.consent.mismatchBody', {
                server: revision,
                screen: TRANSCRIBED_REVISION,
              })}
            </p>
          </article>
        )}

        {record.isError ? <RequestRefusal error={record.error} /> : null}

        <p className="m-0 text-steel">{t('checkpoint.consent.whatIsStored')}</p>

        <div className="grid gap-3 sm:grid-cols-2">
          <Button
            type="button"
            size="lg"
            className="h-auto min-h-14 min-w-0 text-xl whitespace-normal"
            disabled={record.isPending}
            onClick={() =>
              record.mutate(
                { data: { guest_request_id: guestRequestId, revision } },
                { onSuccess: () => onRecorded() },
              )
            }
          >
            {record.isPending ? `${t('common.saving')}…` : t('checkpoint.consent.agree')}
          </Button>
          <Button
            type="button"
            size="lg"
            variant="outline"
            className="h-auto min-h-14 min-w-0 text-xl whitespace-normal"
            onClick={onCancel}
          >
            {t('checkpoint.consent.refuse')}
          </Button>
        </div>

        <p className="m-0 text-steel">{t('checkpoint.consent.refuseNote')}</p>
      </div>
    </section>
  )
}

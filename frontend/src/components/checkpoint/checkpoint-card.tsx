import { useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

import type { CheckpointCard as Card } from '@/api/generated/model'
import { FormField } from '@/components/form-field'
import { GuestStatusTag, VisitStatusTag } from '@/components/guest/guest-status-tag'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useFormatters } from '@/lib/format'
import { cn } from '@/lib/utils'

/**
 * FR-18: the card the officer compares with the document in the guest's hand.
 *
 * **It changes nothing.** `POST /checkpoint/verify` reads, and the entry is a
 * second call the officer makes afterwards. The split is the reason this
 * component exists as a step of its own rather than as a confirmation dialog
 * over the entry: it lets the officer look at the card, look at the passport,
 * and turn the person away without the system having recorded an entry that
 * never happened.
 *
 * **The five fields, and the verdict beside them.** Guest, inviting resident,
 * room, departure deadline, status — FR-18's list, in that order, and each set
 * large enough to read from the officer's working position rather than from the
 * keyboard. The verdict is the server's: `admission.allowed` decides whether
 * the entry button is offered at all, so the screen cannot present a control
 * the API would refuse.
 *
 * The guest is a name and no more: the document itself is in the officer's
 * hand, and the register keeps no copy of it.
 */
export function CheckpointCardView({
  card,
  onCheckIn,
  onCheckOut,
  entering,
  leaving,
  entryError,
  exitError,
  onBack,
}: {
  card: Card
  onCheckIn: (overrideReason: string | null) => void
  onCheckOut: () => void
  entering: boolean
  leaving: boolean
  entryError: unknown
  exitError: unknown
  onBack: () => void
}) {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const [overrideReason, setOverrideReason] = useState('')

  const admission = card.admission
  const allowed = admission?.allowed === true
  const overridable = admission?.override_available === true
  const reasonRequired = admission?.override_requires_reason === true
  const inside = card.visit?.status === 'in_building' || card.visit?.status === 'overdue'

  return (
    <section className="border-4 border-prussian bg-paper-raised text-lg">
      <header className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-rule bg-prussian px-4 py-3 text-white">
        <h2 className="m-0 text-xl font-semibold">{t('checkpoint.card.heading')}</h2>
        <p className="m-0 text-white/80">
          {t('checkpoint.card.checkedAt', {
            time: formatters.time(card.checked_at),
          })}
        </p>
      </header>

      {/*
        The name first and largest. Everything else on this card is read against
        it, and it is the line the officer reads off the document.
      */}
      <div className="border-b border-rule px-4 py-4">
        <p className="label-caps m-0">{t('checkpoint.card.guest')}</p>
        <p className="m-0 text-2xl font-semibold break-words text-ink">
          {card.guest?.full_name}
        </p>
      </div>

      <dl className="m-0 grid gap-x-6 gap-y-0 sm:grid-cols-2">
        <Fact label={t('checkpoint.card.host')}>
          {card.inviting_resident?.full_name ?? t('common.empty')}
        </Fact>
        <Fact label={t('checkpoint.card.room')}>{card.room ?? t('common.empty')}</Fact>
        <Fact label={t('checkpoint.card.interval')}>
          {card.permitted_interval ?? t('common.empty')}
        </Fact>
        <Fact label={t('checkpoint.card.dueAt')}>{formatters.dateTime(card.due_at)}</Fact>
      </dl>

      <div className="flex flex-wrap items-center gap-3 border-t border-rule px-4 py-3">
        <span className="label-caps">{t('checkpoint.card.status')}</span>
        {card.status !== undefined ? (
          <GuestStatusTag status={card.status} label={card.status_label} />
        ) : null}
        {card.visit?.status !== undefined ? (
          <VisitStatusTag status={card.visit.status} />
        ) : null}
        {card.access_code !== null && card.access_code !== undefined ? (
          <span className="font-mono text-xl tracking-[0.2em] text-prussian">
            {card.access_code}
          </span>
        ) : null}
      </div>

      {/*
        The verdict, in the tone of what it says. The sentence is the server's
        `reason`; the translated line is preferred when the code is one this
        interface knows, so that a Russian screen does not read English.
      */}
      <div
        className={cn(
          'border-t-4 px-4 py-4',
          allowed ? 'border-prussian bg-prussian-wash' : 'border-brick bg-brick-wash',
        )}
        role="status"
      >
        <p className="m-0 text-xl font-semibold text-ink">
          {allowed ? t('checkpoint.verdict.allowed') : t('checkpoint.verdict.refused')}
        </p>
        {!allowed && admission !== undefined ? (
          <p className="mt-1 mb-0 text-ink">
            {t(`checkpoint.reason.${admission.reason_code}`, {
              defaultValue: admission.reason ?? '',
            })}
          </p>
        ) : null}
      </div>

      <div className="grid gap-3 border-t border-rule px-4 py-4">
        {entryError !== null && entryError !== undefined ? (
          <RequestRefusal error={entryError} />
        ) : null}
        {exitError !== null && exitError !== undefined ? (
          <RequestRefusal error={exitError} />
        ) : null}

        {/*
          The exit. Offered whenever the visit on the card is open, so a guest
          admitted at another terminal or on an earlier shift can still be
          written out here — the board on the right holds only what this
          terminal recorded, and the record is what matters.
        */}
        {inside && card.visit?.id !== undefined ? (
          <Button
            type="button"
            size="lg"
            className="h-auto min-h-16 w-full min-w-0 text-xl whitespace-normal"
            disabled={leaving}
            onClick={onCheckOut}
          >
            {leaving ? `${t('common.saving')}…` : t('checkpoint.exit.action')}
          </Button>
        ) : null}

        {!inside && allowed ? (
          <Button
            type="button"
            size="lg"
            className="h-auto min-h-16 w-full min-w-0 text-xl whitespace-normal"
            disabled={entering}
            onClick={() => onCheckIn(null)}
          >
            {entering ? `${t('common.saving')}…` : t('checkpoint.entry.action')}
          </Button>
        ) : null}

        {/*
          §2.4.2's «admit on the responsible officer's decision». Offered only
          when the server said the refusal is one that may be set aside, and the
          reason is required because the override is recorded twice — on the
          visit and in the audit log — so that «show me every entry admitted
          against the interval» is one query.
        */}
        {!inside && !allowed && overridable ? (
          <div className="grid gap-3 border border-brick/40 bg-brick-wash px-3 py-3">
            <p className="m-0 font-semibold text-ink">
              {t('checkpoint.override.heading')}
            </p>
            <FormField
              id="override-reason"
              label={t('checkpoint.override.reasonLabel')}
              required
            >
              <Input
                id="override-reason"
                className="h-auto min-h-14 min-w-0 text-lg whitespace-normal"
                value={overrideReason}
                maxLength={500}
                autoComplete="off"
                onChange={(event) => setOverrideReason(event.target.value)}
              />
            </FormField>
            <Button
              type="button"
              size="lg"
              variant="destructive"
              className="h-auto min-h-16 w-full min-w-0 text-xl whitespace-normal"
              disabled={
                entering || (reasonRequired && overrideReason.trim().length < 3)
              }
              onClick={() => onCheckIn(overrideReason.trim())}
            >
              {entering ? `${t('common.saving')}…` : t('checkpoint.override.action')}
            </Button>
          </div>
        ) : null}

        <Button type="button" variant="outline" size="lg" onClick={onBack}>
          {t('checkpoint.card.back')}
        </Button>
      </div>
    </section>
  )
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="border-t border-rule/70 px-4 py-3">
      <dt className="label-caps">{label}</dt>
      <dd className="m-0 text-xl break-words text-ink">{children}</dd>
    </div>
  )
}

import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'

import {
  useAcceptLostFoundClaim,
  useDecideLostFoundClaim,
  useDeclineLostFoundClaim,
} from '@/api/generated/dormitory'
import type { LostFoundClaim } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { FormField, selectClassName } from '@/components/form-field'
import { ClaimStatusTag } from '@/components/lost-found/lost-found-tags'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useFormatters } from '@/lib/format'
import { awaitsHolder, awaitsJudge } from '@/lib/lost-found'

/** FR-26, the answering end: the claims made against one entry, and the moves the reader can make. */
export function ClaimList({
  claims,
  holder,
  judge,
  onDone,
}: {
  claims: LostFoundClaim[]
  holder: boolean
  judge: boolean
  onDone: () => void
}) {
  const { t } = useTranslation()

  if (claims.length === 0) {
    return <p className="px-4 py-6 text-steel">{t('lostFound.claims.none')}</p>
  }

  return (
    <ul className="m-0 list-none p-0">
      {claims.map((claim) => (
        <li key={claim.id} className="border-b border-rule/70 last:border-b-0">
          <ClaimRow claim={claim} holder={holder} judge={judge} onDone={onDone} />
        </li>
      ))}
    </ul>
  )
}

function ClaimRow({
  claim,
  holder,
  judge,
  onDone,
}: {
  claim: LostFoundClaim
  holder: boolean
  judge: boolean
  onDone: () => void
}) {
  const { t } = useTranslation()
  const formatters = useFormatters()

  return (
    <article className="grid gap-3 px-4 py-4">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h3 className="m-0 min-w-0 text-lg font-semibold break-words text-ink">
          {claim.claimant_name ?? t('lostFound.claims.unnamed')}
        </h3>
        <ClaimStatusTag status={claim.status} label={claim.status_label} />
      </div>

      <p className="m-0 break-words whitespace-pre-line text-ink">{claim.message}</p>

      <dl className="m-0 grid gap-x-4 gap-y-1 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)]">
        <dt className="label-caps">{t('lostFound.claims.filed')}</dt>
        <dd className="m-0 text-ink">{formatters.dateTime(claim.created_at)}</dd>

        {claim.referred_at !== null && claim.referred_at !== undefined ? (
          <>
            <dt className="label-caps">{t('lostFound.claims.referredAt')}</dt>
            <dd className="m-0 text-ink">{formatters.dateTime(claim.referred_at)}</dd>
          </>
        ) : null}

        {claim.decided_at !== null && claim.decided_at !== undefined ? (
          <>
            <dt className="label-caps">{t('lostFound.claims.decidedAt')}</dt>
            <dd className="m-0 text-ink">
              {formatters.dateTime(claim.decided_at)}
              {claim.decided_by_name === undefined
                ? null
                : ` · ${t('lostFound.claims.decidedBy', { name: claim.decided_by_name })}`}
            </dd>
          </>
        ) : null}

        {claim.decision_note !== null &&
        claim.decision_note !== undefined &&
        claim.decision_note !== '' ? (
          <>
            <dt className="label-caps">{t('lostFound.claims.note')}</dt>
            <dd className="m-0 break-words whitespace-pre-line text-ink">
              {claim.decision_note}
            </dd>
          </>
        ) : null}

        {claim.handover_point !== null &&
        claim.handover_point !== undefined &&
        claim.handover_point !== '' ? (
          <>
            <dt className="label-caps">{t('lostFound.claims.handover')}</dt>
            <dd className="m-0 break-words text-ink">{claim.handover_point}</dd>
          </>
        ) : null}
      </dl>

      {holder && awaitsHolder(claim) ? (
        <HolderAnswer claim={claim} onDone={onDone} />
      ) : null}

      {judge && awaitsJudge(claim) ? <JudgeDecision claim={claim} onDone={onDone} /> : null}
    </article>
  )
}

/** The holder's two answers: accept with a handover point, or decline. */
function HolderAnswer({ claim, onDone }: { claim: LostFoundClaim; onDone: () => void }) {
  const { t } = useTranslation()

  const [declining, setDeclining] = useState(false)
  const [handover, setHandover] = useState('')
  const [note, setNote] = useState('')
  const [reason, setReason] = useState('')

  const accept = useAcceptLostFoundClaim<ApiError>()
  const decline = useDeclineLostFoundClaim<ApiError>()

  function sendAcceptance(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    accept.mutate(
      {
        lostFoundClaim: claim.id,
        data: {
          handover_point: handover.trim(),
          ...(note.trim() === '' ? {} : { note: note.trim() }),
        },
      },
      { onSuccess: () => onDone() },
    )
  }

  function sendRefusal(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    decline.mutate(
      {
        lostFoundClaim: claim.id,
        data: reason.trim() === '' ? {} : { reason: reason.trim() },
      },
      {
        onSuccess: () => {
          setDeclining(false)
          setReason('')
          onDone()
        },
      },
    )
  }

  return (
    <div className="grid gap-3 border-t border-rule/70 pt-3">
      {accept.isError ? (
        <RequestRefusal error={accept.error} vocabulary="lostFound" />
      ) : null}
      {decline.isError ? (
        <RequestRefusal error={decline.error} vocabulary="lostFound" />
      ) : null}

      {declining ? (
        <form
          className="grid gap-3 border-l-4 border-brick bg-brick-wash px-3 py-3"
          onSubmit={sendRefusal}
        >
          <FormField
            id={`lost-found-reason-${claim.id}`}
            label={t('lostFound.claims.reason')}
          >
            <textarea
              id={`lost-found-reason-${claim.id}`}
              className={`${selectClassName} h-24 py-2`}
              value={reason}
              maxLength={1000}
              onChange={(event) => setReason(event.target.value)}
            />
          </FormField>
          <div className="grid gap-2 sm:grid-cols-2">
            <Button
              type="submit"
              size="lg"
              variant="destructive"
              className="h-auto min-h-12 min-w-0 whitespace-normal"
              disabled={decline.isPending}
            >
              {decline.isPending
                ? `${t('common.saving')}…`
                : t('lostFound.claims.declineConfirm')}
            </Button>
            <Button
              type="button"
              size="lg"
              variant="outline"
              className="h-auto min-h-12 min-w-0 whitespace-normal"
              onClick={() => setDeclining(false)}
            >
              {t('common.cancel')}
            </Button>
          </div>
        </form>
      ) : (
        <form className="grid gap-3" onSubmit={sendAcceptance}>
          <FormField
            id={`lost-found-handover-${claim.id}`}
            label={t('lostFound.claims.handover')}
            required
          >
            <Input
              id={`lost-found-handover-${claim.id}`}
              value={handover}
              minLength={3}
              maxLength={255}
              autoComplete="off"
              required
              onChange={(event) => setHandover(event.target.value)}
            />
          </FormField>

          <FormField
            id={`lost-found-accept-note-${claim.id}`}
            label={t('lostFound.claims.note')}
          >
            <textarea
              id={`lost-found-accept-note-${claim.id}`}
              className={`${selectClassName} h-24 py-2`}
              value={note}
              maxLength={1000}
              onChange={(event) => setNote(event.target.value)}
            />
          </FormField>

          <div className="grid gap-2 sm:grid-cols-2">
            <Button
              type="submit"
              size="lg"
              className="h-auto min-h-12 min-w-0 whitespace-normal"
              disabled={accept.isPending || handover.trim().length < 3}
            >
              {accept.isPending ? `${t('common.saving')}…` : t('lostFound.claims.accept')}
            </Button>
            <Button
              type="button"
              size="lg"
              variant="outline"
              className="h-auto min-h-12 min-w-0 whitespace-normal"
              onClick={() => setDeclining(true)}
            >
              {t('lostFound.claims.decline')}
            </Button>
          </div>
        </form>
      )}
    </div>
  )
}

/** FR-26's other ending: the warden decides a claim whose refusal was referred to them. */
function JudgeDecision({ claim, onDone }: { claim: LostFoundClaim; onDone: () => void }) {
  const { t } = useTranslation()

  const [upheld, setUpheld] = useState(true)
  const [note, setNote] = useState('')
  const [handover, setHandover] = useState('')

  const decide = useDecideLostFoundClaim<ApiError>()

  function send(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    decide.mutate(
      {
        lostFoundClaim: claim.id,
        data: {
          upheld,
          note: note.trim(),
          ...(upheld ? { handover_point: handover.trim() } : {}),
        },
      },
      { onSuccess: () => onDone() },
    )
  }

  return (
    <form
      className="grid gap-3 border-l-4 border-brass bg-brass-wash px-3 py-3"
      onSubmit={send}
    >
      {decide.isError ? (
        <RequestRefusal error={decide.error} vocabulary="lostFound" />
      ) : null}

      <FormField
        id={`lost-found-verdict-${claim.id}`}
        label={t('lostFound.claims.verdict')}
        required
      >
        <select
          id={`lost-found-verdict-${claim.id}`}
          className={selectClassName}
          value={upheld ? 'upheld' : 'refused'}
          onChange={(event) => setUpheld(event.target.value === 'upheld')}
        >
          <option value="upheld">{t('lostFound.claims.verdictUpheld')}</option>
          <option value="refused">{t('lostFound.claims.verdictRefused')}</option>
        </select>
      </FormField>

      {upheld ? (
        <FormField
          id={`lost-found-judge-handover-${claim.id}`}
          label={t('lostFound.claims.handover')}
          required
        >
          <Input
            id={`lost-found-judge-handover-${claim.id}`}
            value={handover}
            minLength={3}
            maxLength={255}
            autoComplete="off"
            required
            onChange={(event) => setHandover(event.target.value)}
          />
        </FormField>
      ) : null}

      <FormField
        id={`lost-found-judge-note-${claim.id}`}
        label={t('lostFound.claims.note')}
        required
      >
        <textarea
          id={`lost-found-judge-note-${claim.id}`}
          className={`${selectClassName} h-24 py-2`}
          value={note}
          minLength={3}
          maxLength={1000}
          required
          onChange={(event) => setNote(event.target.value)}
        />
      </FormField>

      <div>
        <Button
          type="submit"
          size="lg"
          className="h-auto min-h-12 min-w-0 whitespace-normal"
          disabled={
            decide.isPending ||
            note.trim().length < 3 ||
            (upheld && handover.trim().length < 3)
          }
        >
          {decide.isPending ? `${t('common.saving')}…` : t('lostFound.claims.decide')}
        </Button>
      </div>
    </form>
  )
}

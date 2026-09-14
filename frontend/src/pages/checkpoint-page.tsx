import { useRef, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'

import {
  useCheckInGuest,
  useCheckOutGuest,
  useVerifyGuestAtCheckpoint,
} from '@/api/generated/dormitory'
import type { CheckpointCard, GuestVisit } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { buildingsWith, Permission } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { CheckpointBoard } from '@/components/checkpoint/checkpoint-board'
import { CheckpointCardView } from '@/components/checkpoint/checkpoint-card'
import { GuestConsentStep } from '@/components/checkpoint/guest-consent-step'
import { FormField, selectClassName } from '@/components/form-field'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { asConsentRequired, statusOf } from '@/lib/api-refusal'
import {
  forgetEntry,
  loadBoard,
  rememberEntry,
  rememberExit,
  type BoardEntry,
} from '@/lib/checkpoint-board'
import { useFormatters } from '@/lib/format'

/**
 * FR-18 and FR-19: the terminal of the security post. The screen the whole work
 * is built around, and the one whose requirements are numbers rather than
 * adjectives.
 *
 * **Three actions to record an entry (NFR-10).** Enter the code — a scanner
 * types it and presses Enter, which is one — press «Найти», press «Отметить
 * вход». Nothing is behind a tab, nothing needs a second screen, and a single
 * match selects itself so that no press is spent choosing between one option
 * and none. The consent of a first-time guest is a fourth act and is meant to
 * be: art. 9 part 1 of Federal Law No. 152-FZ has it «executed separately from
 * other documents», so folding it into the entry is precisely what may not be
 * done.
 *
 * **Verification and the entry are separate steps.** `verify` reads and
 * renders; `check-in` writes. That is §3.5.1's design note and it is the reason
 * the officer can look at the card, look at the passport and turn the person
 * away without the system holding a record of an entry that never happened.
 *
 * **The type is larger than the minimum.** NFR-10 puts the floor at 16 px; the
 * whole scale is redefined to that floor in `index.css`, and this screen sits
 * above it — the card is read from the officer's working position, a step back
 * from the keyboard, with a person waiting on the other side of the desk.
 *
 * **The system restricts nobody's movement.** FR-20 says so and §2.4.2 explains
 * why: the ground for refusing a person entry to a dormitory is the
 * university's local act and the action of the security service, not a program.
 * What this terminal refuses is a *record* — it will not assert that an entry
 * was within the rules when the interval says it was not.
 */

type Stage =
  | { kind: 'search' }
  | { kind: 'matches'; cards: CheckpointCard[] }
  | { kind: 'card'; card: CheckpointCard }
  /*
   * The override reason travels with the consent step. The officer types it
   * once, before the refusal that sends them here, and an entry recorded after
   * the consent has to carry the same sentence — asking for it twice would be
   * asking the same question about the same decision.
   */
  | {
      kind: 'consent'
      card: CheckpointCard
      revision: string
      overrideReason: string | null
    }
  | { kind: 'entered'; card: CheckpointCard; visit: GuestVisit }
  | { kind: 'left'; visit: GuestVisit }

type SearchBy = 'code' | 'surname'

export function CheckpointPage() {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const { session } = useSession()

  const user = session.status === 'authenticated' ? session.user : null
  const posts = user === null ? [] : buildingsWith(user, Permission.operateCheckpoint)
  const [chosenBuilding, setChosenBuilding] = useState<number | null>(null)
  const buildingId = chosenBuilding ?? posts[0] ?? null

  const [by, setBy] = useState<SearchBy>('code')
  const [term, setTerm] = useState('')
  const [stage, setStage] = useState<Stage>({ kind: 'search' })
  /*
   * Read once, at the first render, and thereafter changed only by what this
   * terminal does. A tab reopened after a reload finds the shift where it left
   * it; a tab opened tomorrow finds nothing, because the store drops rows from
   * an earlier day as it loads them.
   */
  const [board, setBoard] = useState<BoardEntry[]>(() =>
    posts[0] === undefined ? [] : loadBoard(posts[0]),
  )
  const [leavingVisitId, setLeavingVisitId] = useState<number | null>(null)
  const searchField = useRef<HTMLInputElement | null>(null)

  const verify = useVerifyGuestAtCheckpoint<ApiError>()
  const checkIn = useCheckInGuest<ApiError>()
  const checkOut = useCheckOutGuest<ApiError>()

  function restart() {
    setStage({ kind: 'search' })
    setTerm('')
    verify.reset()
    checkIn.reset()
    checkOut.reset()
    searchField.current?.focus()
  }

  function search(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (buildingId === null || term.trim() === '') {
      return
    }
    checkIn.reset()
    checkOut.reset()
    verify.mutate(
      {
        data: {
          building_id: buildingId,
          ...(by === 'code' ? { code: term.trim() } : { surname: term.trim() }),
        },
      },
      {
        onSuccess: (response) => {
          if (response.status !== 200) {
            return
          }
          const cards = response.data.data
          // One match selects itself. A press spent choosing between one option
          // and none is a press NFR-10 does not have to spare.
          setStage(
            cards.length === 1
              ? { kind: 'card', card: cards[0] }
              : { kind: 'matches', cards },
          )
        },
      },
    )
  }

  function recordEntry(card: CheckpointCard, overrideReason: string | null) {
    const id = card.guest_request_id
    if (id === undefined || buildingId === null) {
      return
    }
    checkIn.mutate(
      {
        data: {
          guest_request_id: id,
          ...(overrideReason === null || overrideReason === ''
            ? {}
            : { override_reason: overrideReason }),
        },
      },
      {
        onSuccess: (response) => {
          if (response.status !== 201) {
            return
          }
          const visit = response.data.data
          setBoard(
            rememberEntry(buildingId, {
              guestRequestId: id,
              visitId: visit.id,
              accessCode: card.access_code ?? null,
              guestName: card.guest?.full_name ?? '',
              room: card.room ?? null,
              dueAt: visit.due_at ?? card.due_at ?? null,
              checkedInAt: visit.checked_in_at,
              checkedOutAt: null,
            }),
          )
          setStage({ kind: 'entered', card, visit })
        },
        onError: (error) => {
          /*
           * FR-35 at the point it bites. The 409 names the document and the
           * revision, and the revision is what the consent step then records —
           * the client never names one of its own. The entry is written on the
           * call that follows the consent, which is the order §2.7.1 asks for:
           * consent from the guest, in person, before the record.
           */
          const needed = asConsentRequired(error)
          if (needed !== null) {
            setStage({
              kind: 'consent',
              card,
              revision: needed.revision,
              overrideReason,
            })
            return
          }
          // Any other refusal belongs beside the card, where the officer can
          // read it against the five fields and the verdict. A refusal shown on
          // the consent step would be a refusal about the entry displayed under
          // a heading about a document.
          setStage({ kind: 'card', card })
        },
      },
    )
  }

  function recordExit(visitId: number, onDone?: () => void) {
    if (buildingId === null) {
      return
    }
    setLeavingVisitId(visitId)
    checkOut.mutate(
      { data: { guest_visit_id: visitId } },
      {
        onSuccess: (response) => {
          setLeavingVisitId(null)
          if (response.status !== 200) {
            return
          }
          const visit = response.data.data
          setBoard(rememberExit(buildingId, visitId, visit.checked_out_at ?? null))
          onDone?.()
          setStage({ kind: 'left', visit })
        },
        onError: () => setLeavingVisitId(null),
      },
    )
  }

  const notFound = statusOf(verify.error) === 404
  const noPost = posts.length === 0

  return (
    <div className="grid grid-cols-1 gap-6 text-lg">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">{t('checkpoint.heading')}</h1>
      </div>

      {noPost ? (
        <section className="border border-rule bg-paper-raised px-4 py-6">
          <p className="m-0 text-ink">{t('checkpoint.noPost')}</p>
        </section>
      ) : null}

      {posts.length > 1 ? (
        <FormField id="post-building" label={t('checkpoint.buildingChoice')}>
          <select
            id="post-building"
            className={selectClassName}
            value={String(buildingId ?? '')}
            onChange={(event) => {
              const next = Number(event.target.value)
              setChosenBuilding(next)
              setBoard(loadBoard(next))
              restart()
            }}
          >
            {posts.map((id) => (
              <option key={id} value={id}>
                {t('roles.scopeBuilding', { id })}
              </option>
            ))}
          </select>
        </FormField>
      ) : null}

      {buildingId !== null ? (
        <Panel caption={t('checkpoint.search.heading')}>
          <form className="grid gap-4 px-4 py-4" onSubmit={search}>
            <div className="grid gap-4 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)]">
              <FormField id="post-by" label={t('checkpoint.search.by')}>
                <select
                  id="post-by"
                  className={`${selectClassName} h-14 text-lg`}
                  value={by}
                  onChange={(event) => setBy(event.target.value as SearchBy)}
                >
                  <option value="code">{t('checkpoint.search.byCode')}</option>
                  <option value="surname">{t('checkpoint.search.bySurname')}</option>
                </select>
              </FormField>
              <FormField
                id="post-term"
                label={
                  by === 'code'
                    ? t('checkpoint.search.codeLabel')
                    : t('checkpoint.search.surnameLabel')
                }
              >
                <Input
                  id="post-term"
                  ref={searchField}
                  className="h-14 font-mono text-2xl tracking-[0.15em]"
                  value={term}
                  maxLength={64}
                  autoComplete="off"
                  autoFocus
                  onChange={(event) => setTerm(event.target.value)}
                />
              </FormField>
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
              <Button
                type="submit"
                size="lg"
                className="h-auto min-h-16 min-w-0 text-xl whitespace-normal"
                disabled={verify.isPending || term.trim() === ''}
              >
                {verify.isPending
                  ? `${t('common.loading')}…`
                  : t('checkpoint.search.action')}
              </Button>
              <Button
                type="button"
                size="lg"
                variant="outline"
                className="h-auto min-h-16 min-w-0 text-xl whitespace-normal"
                onClick={restart}
              >
                {t('checkpoint.search.clear')}
              </Button>
            </div>
          </form>
        </Panel>
      ) : null}

      {/*
        «No such code» and «nobody by that name today» are different answers and
        are drawn differently. The API distinguishes them — 404 against an empty
        list — precisely so that a terminal does not show them the same way.
      */}
      {notFound ? (
        <section
          className="border-l-4 border-brick bg-brick-wash px-4 py-4"
          role="status"
        >
          <p className="m-0 text-xl font-semibold text-ink">
            {by === 'code'
              ? t('checkpoint.search.noCodeTitle')
              : t('checkpoint.search.noNameTitle')}
          </p>
        </section>
      ) : null}

      {verify.isError && !notFound ? <RequestRefusal error={verify.error} /> : null}

      {stage.kind === 'matches' ? (
        <Panel
          caption={t('checkpoint.matches.heading')}
          aside={t('checkpoint.matches.count', { count: stage.cards.length })}
        >
          {stage.cards.length === 0 ? (
            <p className="px-4 py-6 text-steel">{t('checkpoint.matches.empty')}</p>
          ) : (
            <ul className="m-0 list-none p-0">
              {stage.cards.map((card) => (
                <li
                  key={card.guest_request_id}
                  className="border-b border-rule/70 px-4 py-4 last:border-b-0"
                >
                  <p className="m-0 text-xl font-semibold break-words text-ink">
                    {card.guest?.full_name}
                  </p>
                  <p className="m-0 text-ink">
                    {card.permitted_interval} ·{' '}
                    {t('checkpoint.card.room')}: {card.room ?? t('common.empty')}
                  </p>
                  <div className="mt-2">
                    <Button
                      type="button"
                      size="lg"
                      onClick={() => setStage({ kind: 'card', card })}
                    >
                      {t('checkpoint.matches.open')}
                    </Button>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      ) : null}

      {stage.kind === 'card' ? (
        <CheckpointCardView
          card={stage.card}
          entering={checkIn.isPending}
          leaving={checkOut.isPending}
          entryError={
            checkIn.isError && asConsentRequired(checkIn.error) === null
              ? checkIn.error
              : null
          }
          exitError={checkOut.isError ? checkOut.error : null}
          onCheckIn={(reason) => recordEntry(stage.card, reason)}
          onCheckOut={() => {
            const visitId = stage.card.visit?.id
            if (visitId !== undefined) {
              recordExit(visitId)
            }
          }}
          onBack={restart}
        />
      ) : null}

      {stage.kind === 'consent' ? (
        <GuestConsentStep
          guestRequestId={stage.card.guest_request_id ?? 0}
          guestName={stage.card.guest?.full_name ?? ''}
          revision={stage.revision}
          onRecorded={() => {
            checkIn.reset()
            recordEntry(stage.card, stage.overrideReason)
          }}
          onCancel={restart}
        />
      ) : null}

      {stage.kind === 'entered' ? (
        <section className="border-4 border-prussian bg-prussian-wash px-4 py-6" role="status">
          <h2 className="m-0 text-2xl font-semibold text-ink">
            {t('checkpoint.entry.recordedTitle')}
          </h2>
          <p className="mt-2 mb-0 text-xl break-words text-ink">
            {stage.card.guest?.full_name}
          </p>
          <p className="mt-1 mb-0 text-ink">
            {t('checkpoint.entry.recordedAt', {
              time: formatters.time(stage.visit.checked_in_at),
            })}{' '}
            ·{' '}
            {t('checkpoint.board.dueAt', {
              time: formatters.time(stage.visit.due_at ?? stage.card.due_at),
            })}
          </p>
          <div className="mt-4">
            <Button
              type="button"
              size="lg"
              className="h-auto min-h-16 min-w-0 text-xl whitespace-normal"
              onClick={restart}
            >
              {t('checkpoint.entry.next')}
            </Button>
          </div>
        </section>
      ) : null}

      {stage.kind === 'left' ? (
        <section className="border-4 border-prussian bg-paper-raised px-4 py-6" role="status">
          <h2 className="m-0 text-2xl font-semibold text-ink">
            {t('checkpoint.exit.recordedTitle')}
          </h2>
          <p className="mt-2 mb-0 text-ink">
            {t('checkpoint.exit.recordedAt', {
              time: formatters.time(stage.visit.checked_out_at),
            })}
          </p>
          <div className="mt-4">
            <Button
              type="button"
              size="lg"
              className="h-auto min-h-16 min-w-0 text-xl whitespace-normal"
              onClick={restart}
            >
              {t('checkpoint.entry.next')}
            </Button>
          </div>
        </section>
      ) : null}

      {checkOut.isError && stage.kind !== 'card' ? (
        <RequestRefusal error={checkOut.error} />
      ) : null}

      {buildingId !== null ? (
        <CheckpointBoard
          entries={board}
          leavingVisitId={leavingVisitId}
          onCheckOut={(entry) => recordExit(entry.visitId)}
          onDismiss={(entry) => setBoard(forgetEntry(buildingId, entry.visitId))}
        />
      ) : null}
    </div>
  )
}

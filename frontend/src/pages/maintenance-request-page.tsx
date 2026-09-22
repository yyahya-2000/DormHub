import { useState, type FormEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import type { UseMutationResult } from '@tanstack/react-query'

import {
  useAcceptMaintenanceRequest,
  useCompleteMaintenanceWork,
  useConfirmMaintenanceWork,
  useRejectMaintenanceRequest,
  useReopenMaintenanceRequest,
  useShowMaintenanceRequest,
  useStartMaintenanceWork,
  type showMaintenanceRequestResponse,
} from '@/api/generated/dormitory'
import {
  MaintenanceRequestStatus,
  MaintenanceUrgency,
  type MaintenanceCommentInput,
  type MaintenanceRequest,
  type MaintenanceUrgency as Urgency,
} from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { triagesMaintenance } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { FormField, selectClassName } from '@/components/form-field'
import {
  MaintenanceStatusTag,
  MaintenanceUrgencyTag,
  OverdueTag,
} from '@/components/maintenance/maintenance-tags'
import { WorkLog } from '@/components/maintenance/work-log'
import { FieldRow, Panel } from '@/components/panel'
import { MaintenancePhotographs } from '@/components/photograph'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { useBuildingStaff } from '@/hooks/use-building-staff'
import { useFormatters } from '@/lib/format'
import {
  MAINTENANCE_URGENCIES,
  awaitsCompletion,
  awaitsReporter,
  awaitsStart,
  awaitsTriage,
  earliestTargetDate,
  isOverdue,
  isReporter,
} from '@/lib/maintenance'
import { useMaintenanceRefresh } from '@/lib/maintenance-cache'

/**
 * One maintenance request, with its history and every move that can be made on
 * it — FR-37, FR-38 and FR-39 on a single card.
 *
 * **Two different kinds of actor share this screen and never share a button.**
 * The staff who triage in this dormitory accept, refuse, start and complete;
 * the resident who filed it confirms or disputes. The line between them is not
 * a matter of seniority: **a request is closed by the person who reported it,
 * and not by the person who fixed it** (§3.5.2). A status set by whoever did the
 * work proves nothing — confirmation by the resident produces a two-sided
 * record — so `completed` is a waiting state and not an ending, and the only
 * ways out of it are the reporter's word and the clock.
 *
 * **A warden who thinks a resident unreasonable has no button here.** He waits
 * out the confirmation window, and the request then closes marked
 * `auto_closed`, which is a different and more honest record than one marked
 * confirmed. The card says which of the two endings a closed request had,
 * because that is the whole difference between evidence the defect was fixed
 * and evidence that the dormitory stopped waiting.
 *
 * **The history is where the reasons live.** There is no `rejection_reason` on
 * the request to disagree with the work log (§3.4.1, decision 6), so the reason
 * for a refusal and the comment on each move are read out of the journal below
 * and nowhere else.
 *
 * **The photographs are shown, one request each.** `photo_paths` carries paths
 * into the object store rather than URLs — a signed URL embedded in a list is
 * stale by the time somebody scrolls to it — so each thumbnail asks for its own
 * link at the moment it is drawn, under the rule that governs reading the
 * request itself. A link that does not arrive leaves the card without that
 * picture and without a warning about it.
 */
export function MaintenanceRequestPage() {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const { session } = useSession()
  const params = useParams<{ maintenanceRequestId: string }>()
  const requestId = Number(params.maintenanceRequestId)

  const card = useShowMaintenanceRequest<showMaintenanceRequestResponse, ApiError>(
    requestId,
    { query: { enabled: Number.isInteger(requestId), retry: false } },
  )
  const request = card.data?.status === 200 ? card.data.data.data : null

  const user = session.status === 'authenticated' ? session.user : null
  const triages =
    user !== null && request !== null && triagesMaintenance(user, request.building_id)
  const reporter = user !== null && request !== null && isReporter(request, user.id)

  return (
    <div className="grid grid-cols-1 gap-8">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold break-words text-ink">
          {request === null ? t('maintenance.cardHeading') : subjectOf(request, t)}
        </h1>
        <p className="mt-1 text-steel">
          {t('maintenance.cardLead', { number: requestId })}
        </p>
      </div>

      <div className="flex flex-wrap gap-2">
        <Button asChild variant="outline" className="h-auto min-h-11 min-w-0 whitespace-normal">
          <Link to="/maintenance">{t('maintenance.backToMine')}</Link>
        </Button>
        {request !== null && triages ? (
          <Button asChild variant="outline" className="h-auto min-h-11 min-w-0 whitespace-normal">
            <Link to={`/buildings/${request.building_id}/maintenance`}>
              {t('maintenance.backToQueue')}
            </Link>
          </Button>
        ) : null}
      </div>

      {card.isError ? (
        <RequestRefusal error={card.error} vocabulary="maintenance" />
      ) : null}

      {card.isPending && !card.isError ? (
        <div className="grid gap-2" aria-hidden="true">
          <Skeleton className="h-48 w-full" />
          <Skeleton className="h-64 w-full" />
        </div>
      ) : null}

      {request !== null ? (
        <>
          <Panel
            caption={t('maintenance.cardHeading')}
            aside={
              <span className="flex flex-wrap gap-2">
                {isOverdue(request) ? <OverdueTag /> : null}
                <MaintenanceStatusTag status={request.status} label={request.status_label} />
              </span>
            }
          >
            <dl className="m-0">
              <FieldRow label={t('maintenance.fields.place')}>
                {request.place ?? t('common.empty')}
              </FieldRow>
              <FieldRow label={t('maintenance.fields.category')}>
                {t(`maintenanceCategory.${request.category}`, {
                  defaultValue: request.category_label ?? request.category,
                })}
              </FieldRow>
              <FieldRow label={t('maintenance.fields.urgency')}>
                {request.urgency === undefined ? (
                  t('common.empty')
                ) : (
                  <MaintenanceUrgencyTag
                    urgency={request.urgency}
                    label={request.urgency_label}
                  />
                )}
              </FieldRow>
              <FieldRow label={t('maintenance.fields.description')}>
                <span className="whitespace-pre-line break-words">
                  {request.description}
                </span>
              </FieldRow>
              <FieldRow label={t('maintenance.fields.reporter')}>
                {request.reporter_name ?? t('common.empty')}
              </FieldRow>
              <FieldRow label={t('maintenance.fields.filed')}>
                {formatters.dateTime(request.created_at)}
              </FieldRow>
              <FieldRow label={t('maintenance.fields.targetDate')}>
                {request.target_date === null || request.target_date === undefined
                  ? t('common.empty')
                  : formatters.date(request.target_date)}
              </FieldRow>
              <FieldRow label={t('maintenance.fields.assignee')}>
                {request.assignee_name ?? t('maintenance.unassigned')}
              </FieldRow>
              <FieldRow label={t('maintenance.fields.photos')}>
                {photographsOf(request) === 0 ? (
                  t('common.empty')
                ) : (
                  <MaintenancePhotographs
                    requestId={request.id}
                    count={photographsOf(request)}
                    subject={subjectOf(request, t)}
                  />
                )}
              </FieldRow>
              {/*
                FR-39. `closed_at` is written by three different endings and the
                card must not confuse them. A refusal is not a closure at all —
                the request never entered work and the reason is in the history.
                Of the two real closures, one is the reporter's word, which is
                evidence the defect was fixed, and the other is the clock running
                out, which is evidence of nothing but that the dormitory stopped
                waiting. `confirmed_at` and `auto_closed` are the two columns
                that tell them apart, and a CHECK constraint refuses a row
                claiming both.
              */}
              {request.closed_at !== null && request.closed_at !== undefined ? (
                <FieldRow
                  label={
                    request.status === MaintenanceRequestStatus.rejected
                      ? t('maintenance.fields.rejected')
                      : t('maintenance.fields.closed')
                  }
                  note={ending(request, t)}
                >
                  {formatters.dateTime(request.closed_at)}
                </FieldRow>
              ) : null}
            </dl>
          </Panel>

          {/*
            FR-39's two buttons, and the identity check that decides them. Not a
            capability over a class of objects: the fact that one particular
            person filed one particular request. The server compares the same
            two identifiers and answers 403.
          */}
          {reporter && awaitsReporter(request) ? (
            <ReporterDecision request={request} />
          ) : null}

          {triages ? <TriagePanel request={request} /> : null}

          <Panel caption={t('maintenance.log.heading')}>
            <WorkLog entries={request.work_log ?? []} />
          </Panel>
        </>
      ) : null}
    </div>
  )
}

/**
 * FR-39. The reporter's two answers to «the work is done»: it is, or it is not.
 *
 * Reopening asks for no reason and does not require one — FR-37 makes a refusal
 * without one impossible and FR-39 makes no such demand, and a resident who has
 * already waited through one repair is the wrong person to put a form in front
 * of. The history gets a sentence either way.
 */
function ReporterDecision({ request }: { request: MaintenanceRequest }) {
  const { t } = useTranslation()
  const refresh = useMaintenanceRefresh()

  const [comment, setComment] = useState('')
  const confirm = useConfirmMaintenanceWork<ApiError>()
  const reopen = useReopenMaintenanceRequest<ApiError>()

  const payload = comment.trim() === '' ? {} : { comment: comment.trim() }

  return (
    <Panel caption={t('maintenance.reporterHeading')}>
      <div className="grid gap-4 px-4 py-4">
        {confirm.isError ? (
          <RequestRefusal error={confirm.error} vocabulary="maintenance" />
        ) : null}
        {reopen.isError ? (
          <RequestRefusal error={reopen.error} vocabulary="maintenance" />
        ) : null}

        <FormField id="maintenance-reporter-comment" label={t('maintenance.fields.comment')}>
          <textarea
            id="maintenance-reporter-comment"
            className={`${selectClassName} h-24 py-2`}
            value={comment}
            maxLength={1000}
            onChange={(event) => setComment(event.target.value)}
          />
        </FormField>

        <div className="grid gap-2 sm:grid-cols-2">
          <Button
            type="button"
            size="lg"
            className="h-auto min-h-12 min-w-0 whitespace-normal"
            disabled={confirm.isPending}
            onClick={() =>
              confirm.mutate(
                { maintenanceRequest: request.id, data: payload },
                { onSuccess: () => refresh() },
              )
            }
          >
            {confirm.isPending ? `${t('common.saving')}…` : t('maintenance.confirm')}
          </Button>
          <Button
            type="button"
            size="lg"
            variant="outline"
            className="h-auto min-h-12 min-w-0 whitespace-normal"
            disabled={reopen.isPending}
            onClick={() =>
              reopen.mutate(
                { maintenanceRequest: request.id, data: payload },
                { onSuccess: () => refresh() },
              )
            }
          >
            {reopen.isPending ? `${t('common.saving')}…` : t('maintenance.reopen')}
          </Button>
        </div>
      </div>
    </Panel>
  )
}

/**
 * FR-37 and FR-38, the staff side. Which controls are drawn follows the graph
 * and not the role: the graph has no `accepted → completed` edge, so work
 * cannot be reported complete on a request nobody started, and the button is
 * simply not there. The server refuses the same move with 409 for a screen left
 * open while somebody else moved the row.
 */
function TriagePanel({ request }: { request: MaintenanceRequest }) {
  const { t } = useTranslation()
  const refresh = useMaintenanceRefresh()

  /*
   * Both transitions are prepared on every render and only one is drawn. The
   * alternative — choosing the hook after the branch — would make the order of
   * hooks depend on the state of a row that changes under the screen, which is
   * the one thing React forbids. A prepared mutation nobody fires costs a
   * closure and no request.
   */
  const start = useStartMaintenanceWork<ApiError>()
  const complete = useCompleteMaintenanceWork<ApiError>()

  if (awaitsTriage(request.status)) {
    return <AcceptOrReject request={request} />
  }

  if (awaitsStart(request.status)) {
    return (
      <TransitionPanel
        caption={t('maintenance.startHeading')}
        action={t('maintenance.start')}
        fieldId={`start-${request.id}`}
        request={request}
        move={start}
        onDone={refresh}
      />
    )
  }

  if (awaitsCompletion(request.status)) {
    return (
      <TransitionPanel
        caption={t('maintenance.completeHeading')}
        action={t('maintenance.complete')}
        fieldId={`complete-${request.id}`}
        request={request}
        move={complete}
        onDone={refresh}
      />
    )
  }

  return (
    <Panel caption={t('maintenance.triageHeading')}>
      <p className="m-0 px-4 py-6 text-steel">
        {t('maintenance.nothingToDo', {
          status: t(`maintenanceStatus.${request.status}`, {
            defaultValue: request.status,
          }),
        })}
      </p>
    </Panel>
  )
}

/**
 * FR-37's two impossibilities, side by side and both enforced three times over:
 * by this form, by the route's validation, and by a constraint in the database.
 *
 * Acceptance without a planned completion date is impossible, and a date
 * already past is refused — the resident is told that date the moment this
 * succeeds. Rejection without a reason is impossible. The responsible party and
 * the change of urgency are optional beside them, because a dormitory whose
 * warden does the work himself has nobody else to name, and a required field
 * would be filled in with his own name on every row.
 */
function AcceptOrReject({ request }: { request: MaintenanceRequest }) {
  const { t } = useTranslation()
  const refresh = useMaintenanceRefresh()

  const [rejecting, setRejecting] = useState(false)
  const [targetDate, setTargetDate] = useState('')
  const [assignee, setAssignee] = useState('')
  const [urgency, setUrgency] = useState<Urgency | ''>('')
  const [comment, setComment] = useState('')
  const [reason, setReason] = useState('')

  const accept = useAcceptMaintenanceRequest<ApiError>()
  const reject = useRejectMaintenanceRequest<ApiError>()

  /*
   * Who the work may be given to. `StaffOfThisDormitory` admits staff and
   * nobody else, so the whole roll — which is mostly residents — offered
   * forty-two names that were certain to come back 422, and read their
   * contacts to do it. Read by post instead, and the list is the three people
   * who can actually be named. A refusal empties it and leaves the rest of the
   * form working: naming somebody is optional.
   */
  const staff = useBuildingStaff(request.building_id, true)

  function sendAcceptance(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    accept.mutate(
      {
        maintenanceRequest: request.id,
        data: {
          target_date: targetDate,
          ...(assignee === '' ? {} : { assigned_to: Number(assignee) }),
          ...(urgency === '' ? {} : { urgency }),
          ...(comment.trim() === '' ? {} : { comment: comment.trim() }),
        },
      },
      { onSuccess: () => refresh() },
    )
  }

  function sendRejection(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    reject.mutate(
      { maintenanceRequest: request.id, data: { reason: reason.trim() } },
      {
        onSuccess: () => {
          setRejecting(false)
          setReason('')
          refresh()
        },
      },
    )
  }

  return (
    <Panel caption={t('maintenance.triageHeading')}>
      <div className="grid gap-4 px-4 py-4">
        {accept.isError ? (
          <RequestRefusal error={accept.error} vocabulary="maintenance" />
        ) : null}
        {reject.isError ? (
          <RequestRefusal error={reject.error} vocabulary="maintenance" />
        ) : null}

        {rejecting ? (
          <form
            className="grid gap-3 border-l-4 border-brick bg-brick-wash px-3 py-3"
            onSubmit={sendRejection}
          >
            <FormField
              id={`maintenance-reason-${request.id}`}
              label={t('maintenance.fields.reason')}
              required
            >
              <textarea
                id={`maintenance-reason-${request.id}`}
                className={`${selectClassName} h-24 py-2`}
                value={reason}
                minLength={3}
                maxLength={1000}
                required
                onChange={(event) => setReason(event.target.value)}
              />
            </FormField>
            <div className="grid gap-2 sm:grid-cols-2">
              <Button
                type="submit"
                size="lg"
                variant="destructive"
                className="h-auto min-h-12 min-w-0 whitespace-normal"
                disabled={reject.isPending || reason.trim().length < 3}
              >
                {reject.isPending
                  ? `${t('common.saving')}…`
                  : t('maintenance.rejectConfirm')}
              </Button>
              <Button
                type="button"
                size="lg"
                variant="outline"
                className="h-auto min-h-12 min-w-0 whitespace-normal"
                onClick={() => setRejecting(false)}
              >
                {t('common.cancel')}
              </Button>
            </div>
          </form>
        ) : (
          <form className="grid gap-4" onSubmit={sendAcceptance}>
            <FormField
              id={`maintenance-target-${request.id}`}
              label={t('maintenance.fields.targetDate')}
              required
            >
              <Input
                id={`maintenance-target-${request.id}`}
                type="date"
                value={targetDate}
                min={earliestTargetDate()}
                required
                onChange={(event) => setTargetDate(event.target.value)}
              />
            </FormField>

            <FormField
              id={`maintenance-assignee-${request.id}`}
              label={t('maintenance.fields.assignee')}
            >
              <select
                id={`maintenance-assignee-${request.id}`}
                className={selectClassName}
                value={assignee}
                onChange={(event) => setAssignee(event.target.value)}
              >
                <option value="">{t('maintenance.unassigned')}</option>
                {staff.groups.map((group) => (
                  <optgroup key={group.role} label={t(`roles.${group.role}`)}>
                    {group.people.map((person) => (
                      <option key={person.id} value={String(person.id)}>
                        {person.full_name}
                      </option>
                    ))}
                  </optgroup>
                ))}
              </select>
            </FormField>

            <FormField
              id={`maintenance-urgency-${request.id}`}
              label={t('maintenance.fields.urgency')}
            >
              <select
                id={`maintenance-urgency-${request.id}`}
                className={selectClassName}
                value={urgency}
                onChange={(event) => setUrgency(event.target.value as Urgency | '')}
              >
                <option value="">
                  {t('maintenance.keepUrgency', {
                    urgency: t(
                      `maintenanceUrgency.${request.urgency ?? MaintenanceUrgency.routine}`,
                    ),
                  })}
                </option>
                {MAINTENANCE_URGENCIES.map((value) => (
                  <option key={value} value={value}>
                    {t(`maintenanceUrgency.${value}`)}
                  </option>
                ))}
              </select>
            </FormField>

            <FormField
              id={`maintenance-accept-comment-${request.id}`}
              label={t('maintenance.fields.comment')}
            >
              <textarea
                id={`maintenance-accept-comment-${request.id}`}
                className={`${selectClassName} h-24 py-2`}
                value={comment}
                maxLength={1000}
                onChange={(event) => setComment(event.target.value)}
              />
            </FormField>

            <div className="grid gap-2 sm:grid-cols-2">
              <Button
                type="submit"
                size="lg"
                className="h-auto min-h-12 min-w-0 whitespace-normal"
                disabled={accept.isPending || targetDate === ''}
              >
                {accept.isPending ? `${t('common.saving')}…` : t('maintenance.accept')}
              </Button>
              <Button
                type="button"
                size="lg"
                variant="outline"
                className="h-auto min-h-12 min-w-0 whitespace-normal"
                onClick={() => setRejecting(true)}
              >
                {t('maintenance.reject')}
              </Button>
            </div>
          </form>
        )}
      </div>
    </Panel>
  )
}

/**
 * The two transitions that carry nothing but an optional sentence — starting
 * the work and reporting it done. One component rather than two, because the
 * only difference between them is the wording and which hook is called; the
 * comment lands in the same append-only journal either way.
 */

/** The variables of the two transitions that carry only an optional sentence. */
type MaintenanceMove = { maintenanceRequest: number; data?: MaintenanceCommentInput }

/**
 * What this request is called on a screen and in the text alternative of its
 * photographs: the short title the resident gave it, or, when the form was sent
 * without one, the category it was filed under.
 */
function subjectOf(
  request: MaintenanceRequest,
  t: (key: string, options?: Record<string, unknown>) => string,
): string {
  if (request.title !== null && request.title !== undefined && request.title !== '') {
    return request.title
  }
  return t(`maintenanceCategory.${request.category}`, {
    defaultValue: request.category_label ?? request.category,
  })
}

/**
 * How many photographs the record claims. `photo_count` is the server's own
 * answer; the length of `photo_paths` is the same number for a reader whose
 * grant lets them see the paths, and is the fallback for one whose does not.
 */
function photographsOf(request: MaintenanceRequest): number {
  return request.photo_count ?? request.photo_paths?.length ?? 0
}

/**
 * How a request ended, in one sentence — or in none, when the row does not say.
 *
 * Read off the two columns and never inferred from the absence of one: a closure
 * whose `auto_closed` is false and whose `confirmed_at` is empty is a record
 * this build does not recognise, and it gets no sentence rather than the wrong
 * one.
 */
function ending(
  request: MaintenanceRequest,
  t: (key: string, options?: Record<string, unknown>) => string,
): string | undefined {
  if (request.status === MaintenanceRequestStatus.rejected) {
    return t('maintenance.rejectedNote')
  }
  if (request.auto_closed === true) {
    return t('maintenance.autoClosedNote', {
      count: request.confirmation_window_days ?? 0,
    })
  }
  if (request.confirmed_at !== null && request.confirmed_at !== undefined) {
    return t('maintenance.confirmedNote')
  }
  return undefined
}
function TransitionPanel<TResponse>({
  caption,
  action,
  fieldId,
  request,
  move,
  onDone,
}: {
  caption: string
  action: string
  fieldId: string
  request: MaintenanceRequest
  move: UseMutationResult<TResponse, ApiError, MaintenanceMove>
  onDone: () => void
}) {
  const { t } = useTranslation()
  const [comment, setComment] = useState('')

  return (
    <Panel caption={caption}>
      <div className="grid gap-4 px-4 py-4">
        {move.isError ? (
          <RequestRefusal error={move.error} vocabulary="maintenance" />
        ) : null}

        <FormField id={fieldId} label={t('maintenance.fields.comment')}>
          <textarea
            id={fieldId}
            className={`${selectClassName} h-24 py-2`}
            value={comment}
            maxLength={1000}
            onChange={(event) => setComment(event.target.value)}
          />
        </FormField>

        <div>
          <Button
            type="button"
            size="lg"
            className="h-auto min-h-12 min-w-0 whitespace-normal"
            disabled={move.isPending}
            onClick={() =>
              move.mutate(
                {
                  maintenanceRequest: request.id,
                  data: comment.trim() === '' ? {} : { comment: comment.trim() },
                },
                {
                  onSuccess: () => {
                    setComment('')
                    onDone()
                  },
                },
              )
            }
          >
            {move.isPending ? `${t('common.saving')}…` : action}
          </Button>
        </div>
      </div>
    </Panel>
  )
}

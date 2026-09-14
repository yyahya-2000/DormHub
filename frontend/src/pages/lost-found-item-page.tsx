import { useState, type FormEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  useClaimLostFoundItem,
  useListLostFoundClaims,
  useResolveLostFoundItem,
  useShowLostFoundItem,
  type listLostFoundClaimsResponse,
  type showLostFoundItemResponse,
} from '@/api/generated/dormitory'
import {
  LostFoundCustody,
  type LostFoundClaim,
  type LostFoundItem,
} from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { decidesLostFoundDisputes, holdsLostFoundItems } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { FormField, selectClassName } from '@/components/form-field'
import { ClaimList } from '@/components/lost-found/claim-list'
import {
  CustodyTag,
  ItemKindTag,
  ItemStatusTag,
} from '@/components/lost-found/lost-found-tags'
import { FieldRow, Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { useFormatters } from '@/lib/format'
import {
  acceptedClaimOf,
  admitsClaims,
  decidesClaimsOn,
  isResolved,
  outstandingClaims,
} from '@/lib/lost-found'
import { useLostFoundRefresh } from '@/lib/lost-found-cache'

/**
 * One entry of the bureau, with every move that can be made on it — FR-25 and
 * FR-26 on a single card.
 *
 * **Nobody's name is at the top of this screen.** The response carries no
 * `reporter_id`, no `reporter_name`, no telephone and no e-mail, and the
 * omission lives in the API Resource rather than in the query: the service
 * still holds the identifier to answer «whose decision is this», and a Resource
 * that never serialises the column cannot leak it through a later change to the
 * query. The card follows the same rule — it has nowhere to put a finder, and
 * offers no path to one. What it offers instead is a claim.
 *
 * **Three readers share this screen and none of them shares a control.** A
 * resident of the dormitory may claim the entry and sees the form; the person
 * holding the object answers the claims and closes the entry; the warden or
 * manager settles a refusal that was put to them. The claims themselves are
 * fetched only for the last two — the identifying marks are what makes a claim
 * checkable, and a list of them readable by the corridor would tell the next
 * claimant exactly what to write. A request made anyway is refused with 403,
 * and a 403 is a line in the audit log, so the question is asked before the
 * call rather than by making it.
 *
 * **Closure is a separate act from acceptance, and deliberately.** FR-26 admits
 * a closure on a claim the finder accepted or on a warden's decision on a
 * referred one — both of which arrive at one place, a claim in status
 * `accepted` — but neither of them closes anything. The entry is marked
 * returned when the object has actually changed hands, by the only person who
 * can know that it did. A warden who upheld a claim made the closure
 * admissible and did not perform it.
 *
 * **Nothing here counts a storage period.** Two dates are shown and named apart
 * (§3.4.2): the day of the finding, and the day the find was declared to the
 * police or to a local self-government body, from which the six-month period of
 * Civil Code art. 228 cl. 1 runs. Counting those days is FR-27 and is outside
 * this iteration, so the card states the dates and promises no clock.
 */
export function LostFoundItemPage() {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const { session } = useSession()
  const refresh = useLostFoundRefresh()
  const params = useParams<{ lostFoundItemId: string }>()
  const itemId = Number(params.lostFoundItemId)

  const card = useShowLostFoundItem<showLostFoundItemResponse, ApiError>(itemId, {
    query: { enabled: Number.isInteger(itemId), retry: false },
  })
  const item = card.data?.status === 200 ? card.data.data.data : null

  const user = session.status === 'authenticated' ? session.user : null

  /*
   * The two capabilities, asked in the dormitory the entry belongs to. The
   * holder of the ordinary path is not a capability at all and is worked out
   * from the row — see `isTheFinder` for why `can_claim` is enough to say it.
   */
  const holder =
    user !== null &&
    item !== null &&
    decidesClaimsOn(item, (buildingId) => holdsLostFoundItems(user, buildingId))
  const judge =
    user !== null && item !== null && decidesLostFoundDisputes(user, item.building_id)

  /*
   * §3.3.2 at its narrowest. The list is asked for only by the two readers the
   * route admits, so the screen never spends a refusal to find out what it
   * could have known: an entry of somebody else's, a loss, or a closed record
   * simply has no claims panel.
   */
  const readsClaims = item !== null && admitsClaims(item) && (holder || judge)
  const claimQuery = useListLostFoundClaims<listLostFoundClaimsResponse, ApiError>(
    itemId,
    { query: { enabled: readsClaims, retry: false } },
  )
  const claims = claimQuery.data?.status === 200 ? claimQuery.data.data.data : null

  return (
    <div className="grid grid-cols-1 gap-6">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold break-words text-ink">
          {item === null ? t('lostFound.cardHeading') : item.title}
        </h1>
        <p className="mt-1 text-steel">{t('lostFound.cardLead', { number: itemId })}</p>
      </div>

      <div>
        <Button
          asChild
          variant="outline"
          className="h-auto min-h-11 min-w-0 whitespace-normal"
        >
          <Link to="/lost-found">{t('lostFound.backToFeed')}</Link>
        </Button>
      </div>

      {card.isError ? (
        <RequestRefusal error={card.error} vocabulary="lostFound" />
      ) : null}

      {card.isPending && !card.isError ? (
        <div className="grid gap-2" aria-hidden="true">
          <Skeleton className="h-48 w-full" />
          <Skeleton className="h-32 w-full" />
        </div>
      ) : null}

      {item !== null ? (
        <>
          <Panel
            caption={t('lostFound.cardHeading')}
            aside={
              <span className="flex flex-wrap gap-2">
                <ItemKindTag kind={item.kind} label={item.kind_label} />
                <ItemStatusTag status={item.status} label={item.status_label} />
                {item.custody === LostFoundCustody.administration ? (
                  <CustodyTag custody={item.custody} label={item.custody_label} />
                ) : null}
              </span>
            }
          >
            <dl className="m-0">
              <FieldRow label={t('lostFound.fields.place')}>{item.place}</FieldRow>
              <FieldRow label={t(`lostFound.fields.happenedOn.${item.kind}`)}>
                {formatters.date(item.happened_on)}
              </FieldRow>
              {/*
                The second date, shown only where there is one. A row reading
                «—» beside «declared to the police» on every umbrella in the
                building would invite the reader to think a declaration was
                due; art. 227 cl. 2 puts that duty on the finder where the
                entitled person is unknown, and whether the university files
                such declarations is the university's to settle.
              */}
              {item.declared_on !== null && item.declared_on !== undefined ? (
                <FieldRow
                  label={t('lostFound.fields.declaredOn')}
                  note={t('lostFound.fields.declaredOnCardNote')}
                >
                  {formatters.date(item.declared_on)}
                </FieldRow>
              ) : null}
              <FieldRow label={t('lostFound.fields.description')}>
                {item.description === null ||
                item.description === undefined ||
                item.description === '' ? (
                  t('common.empty')
                ) : (
                  <span className="break-words whitespace-pre-line">
                    {item.description}
                  </span>
                )}
              </FieldRow>
              <FieldRow label={t('lostFound.fields.building')}>
                {item.building_name ?? t('roles.scopeBuilding', { id: item.building_id })}
              </FieldRow>
              <FieldRow
                label={t('lostFound.fields.custody')}
                note={
                  item.custody === LostFoundCustody.administration
                    ? t('lostFound.fields.custodyCardAdministration')
                    : t('lostFound.fields.custodyCardFinder')
                }
              >
                {t(`lostFoundCustody.${item.custody ?? LostFoundCustody.finder}`, {
                  defaultValue: item.custody_label ?? t('common.empty'),
                })}
              </FieldRow>
              {/*
                `photo_path` is a path in the object store and not a URL — a
                signed URL embedded in a list is stale by the time somebody
                scrolls to it — and this iteration has no route that exchanges
                one for a link. The card says whether a photograph is attached
                rather than drawing a broken image.
              */}
              <FieldRow
                label={t('lostFound.fields.photo')}
                note={
                  item.has_photograph === true
                    ? t('lostFound.photograph.storedNote')
                    : undefined
                }
              >
                {item.has_photograph === true
                  ? t('lostFound.photograph.attached')
                  : t('lostFound.photograph.none')}
              </FieldRow>
              {item.claim_count !== undefined && admitsClaims(item) ? (
                <FieldRow
                  label={t('lostFound.fields.claims')}
                  note={t('lostFound.fields.claimsNote')}
                >
                  {t('lostFound.claims.count', { count: item.claim_count })}
                </FieldRow>
              ) : null}
              {item.resolved_at !== null && item.resolved_at !== undefined ? (
                <FieldRow
                  label={t('lostFound.fields.resolvedAt')}
                  note={t('lostFound.fields.resolvedNote')}
                >
                  {formatters.dateTime(item.resolved_at)}
                </FieldRow>
              ) : null}
            </dl>
          </Panel>

          {/*
            FR-26's claim form, drawn from the three facts the route would
            refuse one on rather than from a guess: `can_claim` is false for
            one's own entry, for a notice of a loss and for an entry that has
            gone home. A button drawn anyway would be answered with 422.
          */}
          {item.can_claim === true ? <ClaimForm item={item} /> : null}

          {!admitsClaims(item) ? (
            <p className="m-0 border-l-4 border-rule bg-paper-raised px-4 py-3 text-steel">
              {t('lostFound.lossNote')}
            </p>
          ) : null}

          {readsClaims ? (
            <Panel
              caption={t('lostFound.claims.heading')}
              aside={
                claims === null
                  ? undefined
                  : t('lostFound.claims.count', { count: claims.length })
              }
            >
              {claimQuery.isError ? (
                <div className="px-4 py-4">
                  <RequestRefusal error={claimQuery.error} vocabulary="lostFound" />
                </div>
              ) : null}

              {claimQuery.isPending && !claimQuery.isError ? (
                <div className="grid gap-2 px-4 py-4" aria-hidden="true">
                  <Skeleton className="h-24 w-full" />
                </div>
              ) : null}

              {claims !== null ? (
                <ClaimList
                  claims={claims}
                  holder={holder}
                  judge={judge}
                  onDone={refresh}
                />
              ) : null}
            </Panel>
          ) : null}

          {holder && !isResolved(item) && claims !== null ? (
            <ResolvePanel item={item} claims={claims} />
          ) : null}
        </>
      ) : null}
    </div>
  )
}

/**
 * FR-26: «the resident submits a claim describing identifying features».
 *
 * The floor of ten characters is the route's and is real: a claim that says
 * «it's mine» gives the person holding the object nothing to judge, and the
 * whole peer-to-peer arrangement of §2.5.4 rests on their refusals being
 * answerable.
 *
 * **Where the answer arrives is said here**, because it arrives nowhere else on
 * this screen. The claims on an entry are readable by the person who has to
 * answer them and by the warden who may be asked to review a refusal, and by
 * nobody else — so a claimant does not watch their own claim on this card. They
 * are told, in the personal account and by mail, and a refusal comes with the
 * offer FR-26 puts in the place of a required reason.
 */
function ClaimForm({ item }: { item: LostFoundItem }) {
  const { t } = useTranslation()
  const refresh = useLostFoundRefresh()

  const [message, setMessage] = useState('')
  const [filed, setFiled] = useState(false)
  const claim = useClaimLostFoundItem<ApiError>()

  function send(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    claim.mutate(
      { lostFoundItem: item.id, data: { message: message.trim() } },
      {
        onSuccess: (response) => {
          if (response.status !== 201) {
            return
          }
          setFiled(true)
          setMessage('')
          refresh()
        },
      },
    )
  }

  if (filed) {
    return (
      <section
        className="border-l-4 border-prussian bg-prussian-wash px-4 py-4"
        role="status"
      >
        <h2 className="m-0 text-lg font-semibold text-ink">
          {t('lostFound.claimFiledTitle')}
        </h2>
        <p className="mt-2 mb-0 text-ink">{t('lostFound.claimFiledBody')}</p>
        <p className="mt-2 mb-0 text-steel">{t('lostFound.claimFiledWhere')}</p>
      </section>
    )
  }

  return (
    <Panel caption={t('lostFound.claimHeading')}>
      <form className="grid gap-4 px-4 py-4" onSubmit={send}>
        <p className="m-0 text-ink">{t('lostFound.claimLead')}</p>

        {claim.isError ? (
          <RequestRefusal error={claim.error} vocabulary="lostFound" />
        ) : null}

        <FormField
          id={`lost-found-claim-${item.id}`}
          label={t('lostFound.claims.marks')}
          note={t('lostFound.claims.marksNote')}
        >
          <textarea
            id={`lost-found-claim-${item.id}`}
            className={`${selectClassName} h-32 py-2`}
            value={message}
            minLength={10}
            maxLength={2000}
            required
            onChange={(event) => setMessage(event.target.value)}
          />
        </FormField>

        <div>
          <Button
            type="submit"
            size="lg"
            className="h-auto min-h-12 min-w-0 whitespace-normal"
            disabled={claim.isPending || message.trim().length < 10}
          >
            {claim.isPending ? `${t('common.saving')}…` : t('lostFound.claimSubmit')}
          </Button>
        </div>
      </form>
    </Panel>
  )
}

/**
 * FR-26's closure: the object went back to its owner, and the entry leaves the
 * list.
 *
 * The button is drawn against the claims rather than against the status, and
 * that is FR-26's «only» read off the data the screen already has: a find
 * closes as returned on a claim somebody accepted, so with nothing accepted
 * there is nobody it went to and the route answers 409 with the number of
 * claims still waiting. The refusal is still read, because a screen left open
 * while somebody else answered the last claim is exactly the case it is for.
 */
function ResolvePanel({ item, claims }: { item: LostFoundItem; claims: LostFoundClaim[] }) {
  const { t } = useTranslation()
  const refresh = useLostFoundRefresh()
  const resolve = useResolveLostFoundItem<ApiError>()

  const accepted = acceptedClaimOf(claims)
  const waiting = outstandingClaims(claims)

  return (
    <Panel caption={t('lostFound.resolveHeading')}>
      <div className="grid gap-4 px-4 py-4">
        <p className="m-0 text-ink">
          {accepted === null ? t('lostFound.resolveBlocked') : t('lostFound.resolveLead')}
        </p>

        {accepted === null && waiting > 0 ? (
          <p className="m-0 text-steel">
            {t('lostFound.noAcceptedOutstanding', { count: waiting })}
          </p>
        ) : null}

        {accepted !== null ? (
          <p className="m-0 text-steel">
            {t('lostFound.resolveTo', {
              name: accepted.claimant_name ?? t('lostFound.claims.unnamed'),
              place: accepted.handover_point ?? t('common.empty'),
            })}
          </p>
        ) : null}

        {resolve.isError ? (
          <RequestRefusal error={resolve.error} vocabulary="lostFound" />
        ) : null}

        <div>
          <Button
            type="button"
            size="lg"
            className="h-auto min-h-12 min-w-0 whitespace-normal"
            disabled={resolve.isPending || accepted === null}
            onClick={() =>
              resolve.mutate(
                { lostFoundItem: item.id },
                { onSuccess: () => refresh() },
              )
            }
          >
            {resolve.isPending ? `${t('common.saving')}…` : t('lostFound.resolve')}
          </Button>
        </div>
      </div>
    </Panel>
  )
}

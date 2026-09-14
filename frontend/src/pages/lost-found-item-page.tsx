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
import { LostFoundPhotograph } from '@/components/photograph'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { useFormatters } from '@/lib/format'
import {
  acceptedClaimOf,
  admitsClaims,
  decidesClaimsOn,
  isResolved,
} from '@/lib/lost-found'
import { useLostFoundRefresh } from '@/lib/lost-found-cache'

/** One entry of the bureau, with every move that can be made on it — FR-25 and FR-26. */
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

  const holder =
    user !== null &&
    item !== null &&
    decidesClaimsOn(item, (buildingId) => holdsLostFoundItems(user, buildingId))
  const judge =
    user !== null && item !== null && decidesLostFoundDisputes(user, item.building_id)

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
              {item.declared_on !== null && item.declared_on !== undefined ? (
                <FieldRow label={t('lostFound.fields.declaredOn')}>
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
              <FieldRow label={t('lostFound.fields.custody')}>
                {t(`lostFoundCustody.${item.custody ?? LostFoundCustody.finder}`, {
                  defaultValue: item.custody_label ?? t('common.empty'),
                })}
              </FieldRow>
              <FieldRow label={t('lostFound.fields.photo')}>
                {item.has_photograph === true ? (
                  <LostFoundPhotograph itemId={item.id} title={item.title} />
                ) : (
                  t('lostFound.photograph.none')
                )}
              </FieldRow>
              {item.claim_count !== undefined && admitsClaims(item) ? (
                <FieldRow label={t('lostFound.fields.claims')}>
                  {t('lostFound.claims.count', { count: item.claim_count })}
                </FieldRow>
              ) : null}
              {item.resolved_at !== null && item.resolved_at !== undefined ? (
                <FieldRow label={t('lostFound.fields.resolvedAt')}>
                  {formatters.dateTime(item.resolved_at)}
                </FieldRow>
              ) : null}
            </dl>
          </Panel>

          {item.can_claim === true ? <ClaimForm item={item} /> : null}

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

/** FR-26: «the resident submits a claim describing identifying features». */
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
      </section>
    )
  }

  return (
    <Panel caption={t('lostFound.claimHeading')}>
      <form className="grid gap-4 px-4 py-4" onSubmit={send}>
        {claim.isError ? (
          <RequestRefusal error={claim.error} vocabulary="lostFound" />
        ) : null}

        <FormField
          id={`lost-found-claim-${item.id}`}
          label={t('lostFound.claims.marks')}
          required
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

/** FR-26's closure: the object went back to its owner, and the entry leaves the list. */
function ResolvePanel({ item, claims }: { item: LostFoundItem; claims: LostFoundClaim[] }) {
  const { t } = useTranslation()
  const refresh = useLostFoundRefresh()
  const resolve = useResolveLostFoundItem<ApiError>()

  const accepted = acceptedClaimOf(claims)

  return (
    <Panel caption={t('lostFound.resolveHeading')}>
      <div className="grid gap-4 px-4 py-4">
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

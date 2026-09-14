import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import {
  useListLostFound,
  type listLostFoundResponse,
} from '@/api/generated/dormitory'
import {
  ListLostFoundStatus,
  LostFoundCustody,
  type LostFoundItem,
  type LostFoundItemKind,
} from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { lostFoundBuildingsOf } from '@/auth/navigation'
import { useSession } from '@/auth/session-context'
import { FormField, selectClassName } from '@/components/form-field'
import {
  CustodyTag,
  ItemKindTag,
  ItemStatusTag,
} from '@/components/lost-found/lost-found-tags'
import { Panel } from '@/components/panel'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { useFormatters } from '@/lib/format'
import { LOST_FOUND_KINDS, LOST_FOUND_LIST_STATUSES } from '@/lib/lost-found'

/**
 * FR-25, the feed — the finds and losses of the dormitories this account is
 * attached to.
 *
 * **There is no building chooser here, and the absence is the requirement.**
 * The route carries no building parameter: the dormitories are computed from
 * the grants of the token, so there is no way to phrase a request for another
 * one. FR-07's horizontal boundary is a missing parameter rather than a check
 * somebody has to remember to write, and FR-05 rides on top of it — a resident
 * whose departure date has passed stops reading that feed without this screen
 * knowing anything about it. The administrator's grant names no dormitory and
 * is therefore not confined to one, which is why the building is printed on
 * each row rather than assumed.
 *
 * **No card carries a name or a telephone number.** FR-25's second criterion
 * is the whole of §3.5.3: a list read by several hundred people with the
 * finder beside every entry is a directory of who found what and lives where.
 * The response has no `reporter_id`, no `reporter_name` and no contacts in it
 * — and this screen has nowhere to put one even if a later revision leaked it.
 * The exchange runs through a claim inside the system, and the only piece of
 * location the module ever publishes is the handover point, chosen by the
 * person holding the object and shown to the one claimant they accepted.
 *
 * **Two readings of the list and not three.** The default is `published`,
 * which is what «the published list» means and what makes FR-26's «a declined
 * claim returns the find to the published list» say something; `claimed` is
 * the entries somebody is already waiting on. `resolved` is not offered,
 * because the route refuses it with 422 — after closure the record is out of
 * the public list altogether (FR-26, third criterion), and the person who
 * published it still reads it at its own address.
 */
export function LostFoundPage() {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const { session } = useSession()

  const user = session.status === 'authenticated' ? session.user : null
  const publishesIn = user === null ? [] : lostFoundBuildingsOf(user)

  const [status, setStatus] = useState<ListLostFoundStatus>(ListLostFoundStatus.published)
  const [kind, setKind] = useState<LostFoundItemKind | ''>('')
  /*
   * The typed text and the text the query was last run with are two different
   * things on purpose. A request per keystroke would be a request per keystroke
   * against a route that reads two `LIKE` patterns, and the substring the
   * reader has half-finished typing is rarely the one they meant.
   */
  const [typed, setTyped] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)

  const feed = useListLostFound<listLostFoundResponse, ApiError>(
    {
      status,
      ...(kind === '' ? {} : { kind }),
      ...(search === '' ? {} : { search }),
      page,
    },
    { query: { retry: false } },
  )

  const payload = feed.data?.status === 200 ? feed.data.data : null
  const rows = payload?.data ?? null
  const meta = payload?.meta

  function choose<T>(set: (value: T) => void, value: T) {
    set(value)
    setPage(1)
  }

  function runSearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSearch(typed.trim())
    setPage(1)
  }

  return (
    <div className="grid grid-cols-1 gap-6">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">{t('lostFound.heading')}</h1>
        <p className="mt-1 text-steel">{t('lostFound.lead')}</p>
      </div>

      {/*
        FR-25's second criterion, said out loud rather than merely obeyed. A
        reader who expects a telephone number beside an umbrella and finds none
        will look for the trick; a line saying why there is none is the
        difference between a feature and a missing field.
      */}
      <p className="m-0 border-l-4 border-prussian bg-prussian-wash px-4 py-3 text-ink">
        {t('lostFound.privacyNote')}
      </p>

      {publishesIn.length > 0 ? (
        <div>
          <Button
            asChild
            size="lg"
            className="h-auto min-h-12 min-w-0 whitespace-normal"
          >
            <Link to="/lost-found/new">{t('lostFound.publishLink')}</Link>
          </Button>
        </div>
      ) : null}

      <Panel caption={t('lostFound.filters.heading')}>
        <form className="grid gap-4 px-4 py-4 sm:grid-cols-2" onSubmit={runSearch}>
          <FormField
            id="lost-found-status"
            label={t('lostFound.filters.status')}
            note={t('lostFound.filters.statusNote')}
          >
            <select
              id="lost-found-status"
              className={selectClassName}
              value={status}
              onChange={(event) =>
                choose(setStatus, event.target.value as ListLostFoundStatus)
              }
            >
              {LOST_FOUND_LIST_STATUSES.map((value) => (
                <option key={value} value={value}>
                  {t(`lostFoundStatus.${value}`)}
                </option>
              ))}
            </select>
          </FormField>

          <FormField
            id="lost-found-kind"
            label={t('lostFound.filters.kind')}
            note={t('lostFound.filters.kindNote')}
          >
            <select
              id="lost-found-kind"
              className={selectClassName}
              value={kind}
              onChange={(event) =>
                choose(setKind, event.target.value as LostFoundItemKind | '')
              }
            >
              <option value="">{t('lostFound.filters.kindAll')}</option>
              {LOST_FOUND_KINDS.map((value) => (
                <option key={value} value={value}>
                  {t(`lostFoundKind.${value}`)}
                </option>
              ))}
            </select>
          </FormField>

          <FormField
            id="lost-found-search"
            label={t('lostFound.filters.search')}
            note={t('lostFound.filters.searchNote')}
            className="sm:col-span-2"
          >
            <Input
              id="lost-found-search"
              type="search"
              value={typed}
              maxLength={120}
              autoComplete="off"
              onChange={(event) => setTyped(event.target.value)}
            />
          </FormField>

          <div className="flex flex-wrap gap-2 sm:col-span-2">
            <Button
              type="submit"
              className="h-auto min-h-11 min-w-0 whitespace-normal"
              disabled={feed.isFetching}
            >
              {t('lostFound.filters.apply')}
            </Button>
            {search === '' ? null : (
              <Button
                type="button"
                variant="outline"
                className="h-auto min-h-11 min-w-0 whitespace-normal"
                onClick={() => {
                  setTyped('')
                  setSearch('')
                  setPage(1)
                }}
              >
                {t('lostFound.filters.clear')}
              </Button>
            )}
          </div>
        </form>
      </Panel>

      {feed.isError ? <RequestRefusal error={feed.error} vocabulary="lostFound" /> : null}

      {feed.isError ? null : (
        <Panel
          className="min-w-0"
          caption={t(`lostFound.caption.${status}`)}
          aside={
            meta?.total === undefined
              ? undefined
              : t('lostFound.total', { count: meta.total })
          }
        >
          {feed.isPending ? (
            <div className="grid gap-2 px-4 py-4" aria-hidden="true">
              <Skeleton className="h-28 w-full" />
              <Skeleton className="h-28 w-full" />
            </div>
          ) : null}

          {rows !== null && rows.length === 0 ? (
            <p className="px-4 py-6 text-steel">
              {search === ''
                ? t(`lostFound.empty.${status}`)
                : t('lostFound.empty.search', { search })}
            </p>
          ) : null}

          {rows !== null && rows.length > 0 ? (
            <ul className="m-0 list-none p-0">
              {rows.map((item) => (
                <li key={item.id} className="border-b border-rule/70 last:border-b-0">
                  <FeedRow item={item} />
                </li>
              ))}
            </ul>
          ) : null}

          {meta !== undefined && (meta.last_page ?? 1) > 1 ? (
            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-rule px-4 py-3">
              <span className="text-steel">
                {t('lostFound.page', {
                  current: formatters.count(meta.current_page),
                  total: formatters.count(meta.last_page),
                })}
              </span>
              <div className="flex gap-2">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={(meta.current_page ?? 1) <= 1 || feed.isFetching}
                  onClick={() => setPage((current) => Math.max(1, current - 1))}
                >
                  {t('lostFound.previous')}
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={
                    (meta.current_page ?? 1) >= (meta.last_page ?? 1) || feed.isFetching
                  }
                  onClick={() => setPage((current) => current + 1)}
                >
                  {t('lostFound.next')}
                </Button>
              </div>
            </div>
          ) : null}
        </Panel>
      )}
    </div>
  )
}

/**
 * One entry of the feed. Everything a reader needs to recognise an object of
 * theirs — what it is, where it turned up, when — and nothing at all about who
 * has it.
 */
function FeedRow({ item }: { item: LostFoundItem }) {
  const { t } = useTranslation()
  const formatters = useFormatters()

  return (
    <article className="grid gap-2 px-4 py-4">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h3 className="m-0 min-w-0 text-lg font-semibold break-words text-ink">
          <Link className="text-prussian underline" to={`/lost-found/${item.id}`}>
            {item.title}
          </Link>
        </h3>
        <div className="flex flex-wrap gap-2">
          <ItemKindTag kind={item.kind} label={item.kind_label} />
          <ItemStatusTag status={item.status} label={item.status_label} />
          {item.custody === LostFoundCustody.administration ? (
            <CustodyTag custody={item.custody} label={item.custody_label} />
          ) : null}
        </div>
      </div>

      <dl className="m-0 grid gap-x-4 gap-y-1 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)]">
        <dt className="label-caps">{t('lostFound.fields.place')}</dt>
        <dd className="m-0 break-words text-ink">{item.place}</dd>
        <dt className="label-caps">{t(`lostFound.fields.happenedOn.${item.kind}`)}</dt>
        <dd className="m-0 text-ink">{formatters.date(item.happened_on)}</dd>
        <dt className="label-caps">{t('lostFound.fields.building')}</dt>
        <dd className="m-0 break-words text-ink">
          {item.building_name ?? t('roles.scopeBuilding', { id: item.building_id })}
        </dd>
      </dl>

      {item.has_photograph === true ? (
        <p className="m-0 text-steel">{t('lostFound.photograph.attached')}</p>
      ) : null}
    </article>
  )
}

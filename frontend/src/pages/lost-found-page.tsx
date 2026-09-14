import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { keepPreviousData } from '@tanstack/react-query'
import { Plus, Search, X } from 'lucide-react'
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
import { Pagination } from '@/components/ui/pagination'
import { Skeleton } from '@/components/ui/skeleton'
import { lastPageOf, usePagination } from '@/hooks/use-pagination'
import { useFormatters } from '@/lib/format'
import { LOST_FOUND_KINDS, LOST_FOUND_LIST_STATUSES } from '@/lib/lost-found'

/**
 * FR-25, the feed: the finds and losses of the dormitories this account is
 * attached to. The dormitories come from the token, so the route takes no
 * building parameter; no card carries a name or a telephone number.
 */
export function LostFoundPage() {
  const { t } = useTranslation()
  const { session } = useSession()

  const user = session.status === 'authenticated' ? session.user : null
  const publishesIn = user === null ? [] : lostFoundBuildingsOf(user)

  const [status, setStatus] = useState<ListLostFoundStatus>(ListLostFoundStatus.published)
  const [kind, setKind] = useState<LostFoundItemKind | ''>('')
  /*
   * The typed text and the text the query was last run with are two different
   * things on purpose: a request per keystroke would be a request per keystroke
   * against a route that reads two `LIKE` patterns.
   */
  const [typed, setTyped] = useState('')
  const [search, setSearch] = useState('')
  const paging = usePagination({ resetKey: `${status}:${kind}:${search}` })

  const feed = useListLostFound<listLostFoundResponse, ApiError>(
    {
      status,
      ...(kind === '' ? {} : { kind }),
      ...(search === '' ? {} : { search }),
      page: paging.page,
    },
    { query: { retry: false, placeholderData: keepPreviousData } },
  )

  const payload = feed.data?.status === 200 ? feed.data.data : null
  const rows = payload?.data ?? null
  const meta = payload?.meta

  function runSearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSearch(typed.trim())
  }

  return (
    <div className="grid grid-cols-1 gap-6">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">{t('lostFound.heading')}</h1>
      </div>

      {publishesIn.length > 0 ? (
        <div>
          <Button
            asChild
            size="lg"
            className="h-auto min-h-12 min-w-0 whitespace-normal"
          >
            <Link to="/lost-found/new">
              <Plus aria-hidden="true" className="size-5" />
              {t('lostFound.publishLink')}
            </Link>
          </Button>
        </div>
      ) : null}

      <Panel caption={t('lostFound.filters.heading')}>
        <form className="grid gap-4 px-4 py-4 sm:grid-cols-2" onSubmit={runSearch}>
          <FormField id="lost-found-status" label={t('lostFound.filters.status')}>
            <select
              id="lost-found-status"
              className={selectClassName}
              value={status}
              onChange={(event) =>
                setStatus(event.target.value as ListLostFoundStatus)
              }
            >
              {LOST_FOUND_LIST_STATUSES.map((value) => (
                <option key={value} value={value}>
                  {t(`lostFoundStatus.${value}`)}
                </option>
              ))}
            </select>
          </FormField>

          <FormField id="lost-found-kind" label={t('lostFound.filters.kind')}>
            <select
              id="lost-found-kind"
              className={selectClassName}
              value={kind}
              onChange={(event) => setKind(event.target.value as LostFoundItemKind | '')}
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
              <Search aria-hidden="true" className="size-5" />
              {t('lostFound.filters.apply')}
            </Button>
            {search === '' ? null : (
              <Button
                type="button"
                variant="outline"
                size="icon"
                aria-label={t('lostFound.filters.clear')}
                onClick={() => {
                  setTyped('')
                  setSearch('')
                }}
              >
                <X aria-hidden="true" className="size-5" />
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

          <Pagination
            page={paging.page}
            lastPage={lastPageOf(meta)}
            onPageChange={paging.setPage}
            disabled={feed.isFetching}
          />
        </Panel>
      )}
    </div>
  )
}

/** One entry of the feed: what it is, where it turned up, when. */
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

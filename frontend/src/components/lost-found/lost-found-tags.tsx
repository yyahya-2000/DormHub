import { useTranslation } from 'react-i18next'

import type {
  LostFoundClaimStatus,
  LostFoundCustody,
  LostFoundItemKind,
  LostFoundItemStatus,
} from '@/api/generated/model'
import { CLAIM_STATUS_TONE, ITEM_STATUS_TONE, KIND_TONE } from '@/lib/lost-found'
import { cn } from '@/lib/utils'

/**
 * The marks an entry and a claim carry.
 *
 * `kind_label`, `status_label` and `custody_label` come down with every row and
 * are the server's own sentence about it. They are the fallback and never the
 * first choice — the API answers in English and in no other language, so a
 * Russian screen must not read «With the finder» — but they are worth carrying,
 * because a value this build has not been taught yet then reads as a sentence
 * instead of as a key.
 */
export function ItemKindTag({
  kind,
  label,
  className,
}: {
  kind: LostFoundItemKind
  label?: string
  className?: string
}) {
  const { t } = useTranslation()

  return (
    <span
      className={cn(
        'inline-block border px-2 py-0.5 font-medium',
        KIND_TONE[kind] ?? 'border-rule bg-paper text-steel',
        className,
      )}
    >
      {t(`lostFoundKind.${kind}`, { defaultValue: label ?? kind })}
    </span>
  )
}

export function ItemStatusTag({
  status,
  label,
  className,
}: {
  status: LostFoundItemStatus
  label?: string
  className?: string
}) {
  const { t } = useTranslation()

  return (
    <span
      className={cn(
        'inline-block border px-2 py-0.5 font-medium',
        ITEM_STATUS_TONE[status] ?? 'border-rule bg-paper text-steel',
        className,
      )}
    >
      {t(`lostFoundStatus.${status}`, { defaultValue: label ?? status })}
    </span>
  )
}

/**
 * Which of §2.5.4's two paths the entry took, drawn only for the exception.
 *
 * The ordinary path is the object staying with the resident who found it, and
 * a mark on every row saying so would be noise on the many to make the few
 * visible. The deposited one changes who answers a claim, so it is marked.
 */
export function CustodyTag({
  custody,
  label,
  className,
}: {
  custody: LostFoundCustody
  label?: string
  className?: string
}) {
  const { t } = useTranslation()

  return (
    <span
      className={cn(
        'inline-block border border-brass/45 bg-brass-wash px-2 py-0.5 font-medium text-brass',
        className,
      )}
    >
      {t(`lostFoundCustody.${custody}`, { defaultValue: label ?? custody })}
    </span>
  )
}

export function ClaimStatusTag({
  status,
  label,
  className,
}: {
  status: LostFoundClaimStatus
  label?: string
  className?: string
}) {
  const { t } = useTranslation()

  return (
    <span
      className={cn(
        'inline-block border px-2 py-0.5 font-medium',
        CLAIM_STATUS_TONE[status] ?? 'border-rule bg-paper text-steel',
        className,
      )}
    >
      {t(`lostFoundClaimStatus.${status}`, { defaultValue: label ?? status })}
    </span>
  )
}

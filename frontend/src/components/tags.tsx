import { useTranslation } from 'react-i18next'

import type { RoleGrant, UserStatus } from '@/api/generated/model'
import { cn } from '@/lib/utils'

/**
 * A role always comes with the scope it holds in: «Комендант · корпус № 1».
 * A role without its building is only half of the grant (FR-07), and on this
 * screen the half would be misleading.
 */
export function RoleTag({ grant }: { grant: RoleGrant }) {
  const { t } = useTranslation()
  const scope =
    grant.building_id === null
      ? t('roles.scopeSystem')
      : t('roles.scopeBuilding', { id: grant.building_id })

  return (
    <span className="inline-flex items-baseline gap-2 border border-prussian/25 bg-prussian-wash px-2 py-0.5">
      <span className="font-medium text-prussian">{t(`roles.${grant.role}`)}</span>
      <span className="text-steel">{scope}</span>
    </span>
  )
}

const STATUS_TONE: Record<UserStatus, string> = {
  active: 'border-prussian/25 text-prussian',
  blocked: 'border-brick/40 bg-brick-wash text-brick',
  archived: 'border-rule bg-paper text-steel',
}

export function StatusTag({ status }: { status: UserStatus }) {
  const { t } = useTranslation()
  return (
    <span className={cn('inline-block border px-2 py-0.5', STATUS_TONE[status])}>
      {t(`userStatus.${status}`)}
    </span>
  )
}

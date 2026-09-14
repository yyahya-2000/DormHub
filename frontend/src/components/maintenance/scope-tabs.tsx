import { Archive, Inbox } from 'lucide-react'
import { useTranslation } from 'react-i18next'

import { Button } from '@/components/ui/button'

/** `open` is what is still somebody's work; `archive` is what is closed or refused. */
export type MaintenanceScope = 'open' | 'archive'

/**
 * The two halves of the maintenance list. The open requests are the screen a
 * warden works from, so they are what it opens on; the archive is a second tab
 * and not a filter with five controls around it.
 */
export function ScopeTabs({
  scope,
  onChange,
}: {
  scope: MaintenanceScope
  onChange: (scope: MaintenanceScope) => void
}) {
  const { t } = useTranslation()

  return (
    <div className="flex flex-wrap gap-2">
      <Button
        type="button"
        variant={scope === 'open' ? 'default' : 'outline'}
        aria-pressed={scope === 'open'}
        onClick={() => onChange('open')}
      >
        <Inbox aria-hidden="true" />
        {t('maintenanceQueue.scopeOpen')}
      </Button>
      <Button
        type="button"
        variant={scope === 'archive' ? 'default' : 'outline'}
        aria-pressed={scope === 'archive'}
        onClick={() => onChange('archive')}
      >
        <Archive aria-hidden="true" />
        {t('maintenanceQueue.scopeArchive')}
      </Button>
    </div>
  )
}

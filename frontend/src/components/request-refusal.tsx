import { useTranslation } from 'react-i18next'

import { ApiError } from '@/api/http-client'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'

/**
 * The server's refusal, said in the reader's language.
 *
 * A 403 here is not a failure of the interface: it is the role model of FR-07
 * answering, and the page says so plainly instead of showing an empty table.
 */
export function RequestRefusal({ error }: { error: unknown }) {
  const { t } = useTranslation()

  let text = t('errors.network')
  if (error instanceof ApiError) {
    if (error.status === 403) {
      text = t('errors.forbidden')
    } else if (error.status === 404) {
      text = t('errors.notFound')
    } else {
      text = t('errors.unexpected', { status: error.status })
    }
  }

  return (
    <Alert
      variant="destructive"
      className="border-l-4 border-brick bg-brick-wash text-ink"
    >
      <AlertTitle className="text-ink">
        {error instanceof ApiError ? String(error.status) : t('common.empty')}
      </AlertTitle>
      <AlertDescription className="text-ink">{text}</AlertDescription>
    </Alert>
  )
}

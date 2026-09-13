import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

export function NotFoundPage() {
  const { t } = useTranslation()

  return (
    <div className="grid gap-4">
      <h1 className="text-2xl font-semibold text-ink">{t('errors.notFoundPage')}</h1>
      <Link className="text-prussian underline" to="/">
        {t('common.backToBuilding')}
      </Link>
    </div>
  )
}

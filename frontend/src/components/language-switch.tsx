import { useTranslation } from 'react-i18next'

import { rememberLocale, SUPPORTED_LOCALES, type SupportedLocale } from '@/i18n'
import { cn } from '@/lib/utils'

/**
 * NFR-11: the interface exists in Russian and English, and switching is one
 * click away — the same switch changes the `Intl` formatters, so dates and
 * counts follow the language.
 */
export function LanguageSwitch({ tone = 'dark' }: { tone?: 'dark' | 'light' }) {
  const { t, i18n } = useTranslation()

  function choose(locale: SupportedLocale) {
    void i18n.changeLanguage(locale)
    rememberLocale(locale)
  }

  return (
    <div
      className={cn(
        'flex border',
        tone === 'dark' ? 'border-white/35' : 'border-rule',
      )}
      role="group"
      aria-label={t('common.language')}
    >
      {SUPPORTED_LOCALES.map((locale) => {
        const active = i18n.language.slice(0, 2) === locale
        return (
          <button
            key={locale}
            type="button"
            onClick={() => choose(locale)}
            aria-pressed={active}
            className={cn(
              'px-3 py-1 font-medium transition-colors',
              tone === 'dark'
                ? active
                  ? 'bg-paper text-prussian'
                  : 'text-white/80 hover:text-white'
                : active
                  ? 'bg-prussian text-white'
                  : 'text-steel hover:text-ink',
            )}
          >
            {locale === 'ru' ? t('common.languageRu') : t('common.languageEn')}
          </button>
        )
      })}
    </div>
  )
}

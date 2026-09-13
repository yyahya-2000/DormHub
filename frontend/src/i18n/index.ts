import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'

import en from './locales/en.json'
import ru from './locales/ru.json'

/**
 * NFR-11: no interface string is written inside a component. Every caption,
 * label and message lives in `locales/*.json`, in Russian and English, and the
 * same locale drives the `Intl` formatters in `lib/format.ts` — a date in the
 * visiting journal has to read the way the reader's locale writes dates.
 *
 * Russian is the default: the residents and the security post are the people
 * who use the system daily. English exists because the dormitory admits
 * international students and the thesis is written in it.
 */

export const SUPPORTED_LOCALES = ['ru', 'en'] as const
export type SupportedLocale = (typeof SUPPORTED_LOCALES)[number]

export const LOCALE_STORAGE_KEY = 'dormitory.locale'

function isSupported(value: string | null | undefined): value is SupportedLocale {
  return value === 'ru' || value === 'en'
}

function preferredLocale(): SupportedLocale {
  try {
    const stored = window.localStorage.getItem(LOCALE_STORAGE_KEY)
    if (isSupported(stored)) {
      return stored
    }
  } catch {
    // Storage may be unavailable; the browser language still decides.
  }
  const fromBrowser = navigator.languages.find((tag) => isSupported(tag.slice(0, 2)))
  return isSupported(fromBrowser?.slice(0, 2)) ? (fromBrowser.slice(0, 2) as SupportedLocale) : 'ru'
}

void i18n.use(initReactI18next).init({
  resources: {
    ru: { translation: ru },
    en: { translation: en },
  },
  lng: preferredLocale(),
  fallbackLng: 'ru',
  interpolation: { escapeValue: false },
})

export function rememberLocale(locale: SupportedLocale): void {
  try {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, locale)
  } catch {
    // A locale that cannot be stored is still applied for this visit.
  }
  document.documentElement.lang = locale
}

document.documentElement.lang = i18n.language

export default i18n

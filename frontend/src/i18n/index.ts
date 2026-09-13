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
}

/**
 * The two things outside the React tree that the language owns: the `lang` of
 * the document, which tells the reader's assistive software which language it
 * is reading, and the title of the tab. The title is a string of the interface
 * like any other, so it lives in the locale files and not in `index.html` —
 * the markup there is only what the tab shows before the bundle runs.
 */
function applyLocaleToDocument(language: string): void {
  document.documentElement.lang = language.slice(0, 2)
  document.title = i18n.t('app.documentTitle')
}

i18n.on('languageChanged', applyLocaleToDocument)
applyLocaleToDocument(i18n.language)

export default i18n

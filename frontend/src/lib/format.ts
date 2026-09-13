import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'

/**
 * Dates, times and counts go through `Intl`, never through a hand-written
 * pattern. The system will carry a visiting journal — arrival and departure of
 * every guest, §2.1.2 of the rules of residence — and a date written as
 * `dd.MM.yyyy` in the source would be wrong for half of its readers the moment
 * the interface is switched to English. The locale that formats is the locale
 * that translates.
 */

/** Locale tags handed to Intl. The interface language maps onto a region. */
const INTL_LOCALE: Record<string, string> = {
  ru: 'ru-RU',
  en: 'en-GB',
}

export function intlLocale(language: string): string {
  return INTL_LOCALE[language.slice(0, 2)] ?? 'ru-RU'
}

export type Formatters = {
  /** Day and clock time of an ISO timestamp, as in the audit log. */
  dateTime: (iso: string | null | undefined) => string
  /** Day alone. */
  date: (iso: string | null | undefined) => string
  /** Clock time alone, from an ISO timestamp. */
  time: (iso: string | null | undefined) => string
  /** Clock time from a bare `HH:mm:ss` the API sends for a visiting window. */
  clock: (value: string | null | undefined) => string
  /** A plain count, grouped the way the locale groups digits. */
  count: (value: number | null | undefined) => string
  /** Minutes and seconds left of a sign-in block. */
  duration: (totalSeconds: number) => string
}

function buildFormatters(language: string): Formatters {
  const locale = intlLocale(language)
  const dateTimeFormat = new Intl.DateTimeFormat(locale, {
    dateStyle: 'medium',
    timeStyle: 'short',
  })
  const dateFormat = new Intl.DateTimeFormat(locale, { dateStyle: 'medium' })
  const timeFormat = new Intl.DateTimeFormat(locale, { timeStyle: 'short' })
  const numberFormat = new Intl.NumberFormat(locale)
  const twoDigit = new Intl.NumberFormat(locale, {
    minimumIntegerDigits: 2,
    useGrouping: false,
  })

  const parse = (iso: string | null | undefined): Date | null => {
    if (iso === null || iso === undefined || iso === '') {
      return null
    }
    const parsed = new Date(iso)
    return Number.isNaN(parsed.getTime()) ? null : parsed
  }

  return {
    dateTime: (iso) => {
      const parsed = parse(iso)
      return parsed === null ? '—' : dateTimeFormat.format(parsed)
    },
    date: (iso) => {
      const parsed = parse(iso)
      return parsed === null ? '—' : dateFormat.format(parsed)
    },
    time: (iso) => {
      const parsed = parse(iso)
      return parsed === null ? '—' : timeFormat.format(parsed)
    },
    clock: (value) => {
      if (value === null || value === undefined || value === '') {
        return '—'
      }
      const [hours, minutes] = value.split(':')
      const reference = new Date()
      reference.setHours(Number(hours), Number(minutes ?? '0'), 0, 0)
      return Number.isNaN(reference.getTime()) ? '—' : timeFormat.format(reference)
    },
    count: (value) =>
      value === null || value === undefined ? '—' : numberFormat.format(value),
    duration: (totalSeconds) => {
      const safe = Math.max(0, Math.floor(totalSeconds))
      const minutes = Math.floor(safe / 60)
      const seconds = safe % 60
      return `${twoDigit.format(minutes)}:${twoDigit.format(seconds)}`
    },
  }
}

export function useFormatters(): Formatters {
  const { i18n } = useTranslation()
  return useMemo(() => buildFormatters(i18n.language), [i18n.language])
}

/**
 * Today as the API writes a date: `YYYY-MM-DD`, taken from the local calendar
 * rather than from UTC. A move-in recorded at nine in the evening in Moscow
 * belongs to the day the warden is living in, not to the day in Greenwich.
 */
export function todayIso(): string {
  const now = new Date()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${now.getFullYear()}-${month}-${day}`
}

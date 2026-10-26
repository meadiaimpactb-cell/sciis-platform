/**
 * Formatting helpers — UX-02 "Arabic-safe number formatting", UX-05 Hijri dates.
 * Numbers and dates use Latin digits in both locales via `nu-latn`.
 * Intl formatters are expensive to construct (locale data lookup), so each locale + options pair is built once.
 */
const formatters = new Map<string, Intl.NumberFormat | Intl.DateTimeFormat | Intl.RelativeTimeFormat>()
function cached<T extends Intl.NumberFormat | Intl.DateTimeFormat | Intl.RelativeTimeFormat>(kind: string, locale: string, opts: object, make: () => T): T {
  const key = `${kind}|${locale}|${JSON.stringify(opts)}`
  let f = formatters.get(key) as T | undefined
  if (!f) formatters.set(key, (f = make()))
  return f
}

/**
 * UX-05 — calendar for every date in the app: Gregorian (default), Hijri (Umm al-Qura) or both. Set after hydration
 * by plugins/calendar-pref.client.ts (account preference when signed in, else this browser's choice).
 */
export type CalendarMode = 'gregorian' | 'hijri' | 'both'
export const useCalendarMode = () => useState<CalendarMode>('calendar-mode', () => 'gregorian')
/** The date-only part of formatter options (the Hijri half of "both" never repeats the weekday or the time). */
const hijriPart = (o: Intl.DateTimeFormatOptions): Intl.DateTimeFormatOptions | null => {
  const { year, month, day } = o
  return year || month || day ? { ...(year ? { year } : {}), ...(month ? { month } : {}), ...(day ? { day } : {}) } : null
}

export const useFormat = () => {
  const { locale, t } = useI18n()
  const calendar = useCalendarMode()

  const numLocale = computed(() => (locale.value === 'ar' ? 'ar-SA-u-nu-latn' : 'en-US'))

  const number = (v: number | null | undefined, opts: Intl.NumberFormatOptions = {}) => {
    if (v == null || Number.isNaN(v)) return '—'
    const o = { maximumFractionDigits: 1, ...opts }
    return cached('n', numLocale.value, o, () => new Intl.NumberFormat(numLocale.value, o)).format(v)
  }

  const compact = (v: number | null | undefined) => {
    if (v == null) return '—'
    const o = { notation: 'compact', maximumFractionDigits: 1 } as const
    return cached('n', numLocale.value, o, () => new Intl.NumberFormat(numLocale.value, o)).format(v)
  }

  const sar = (v: number | null | undefined) => {
    if (v == null) return '—'
    return `${number(v, { maximumFractionDigits: 0 })} ${t('common.sar')}`
  }

  // Latin digits and the Latin "%" in both locales (platform rule: no Arabic-Indic numerals anywhere).
  const percent = (v: number | null | undefined) => (v == null ? '—' : `${number(v, { maximumFractionDigits: 0 })}%`)

  const date = (iso: string | null | undefined, opts: Intl.DateTimeFormatOptions = { year: 'numeric', month: 'short', day: 'numeric' }) => {
    if (!iso) return '—'
    const d = new Date(iso)
    if (Number.isNaN(d.getTime())) return '—'
    // Latin digits in every calendar (`nu-latn`); Hijri = Umm al-Qura, the official Saudi calendar.
    const fmt = (ca: 'gregory' | 'islamic-umalqura', o: Intl.DateTimeFormatOptions) => {
      const loc = `${locale.value === 'ar' ? 'ar-SA' : 'en-GB'}-u-ca-${ca}-nu-latn`
      return cached('d', loc, o, () => new Intl.DateTimeFormat(loc, o)).format(d)
    }
    if (calendar.value === 'hijri') return fmt('islamic-umalqura', opts)
    const gregorian = fmt('gregory', opts)
    const h = calendar.value === 'both' ? hijriPart(opts) : null
    return h ? `${gregorian} · ${fmt('islamic-umalqura', h)}` : gregorian
  }

  const dateTime = (iso: string | null | undefined) => date(iso, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })

  const relative = (iso: string | null | undefined) => {
    if (!iso) return '—'
    const diff = (new Date(iso).getTime() - Date.now()) / 1000
    const loc = locale.value === 'ar' ? 'ar-SA-u-nu-latn' : 'en'
    const rtf = cached('r', loc, { numeric: 'auto' }, () => new Intl.RelativeTimeFormat(loc, { numeric: 'auto' }))
    const abs = Math.abs(diff)
    if (abs < 3600) return rtf.format(Math.round(diff / 60), 'minute')
    if (abs < 86400) return rtf.format(Math.round(diff / 3600), 'hour')
    if (abs < 86400 * 30) return rtf.format(Math.round(diff / 86400), 'day')
    return date(iso)
  }

  const daysUntil = (iso: string | null | undefined) => {
    if (!iso) return null
    return Math.ceil((new Date(iso).getTime() - Date.now()) / 86400000)
  }

  const fileSize = (bytes: number | null | undefined) => {
    if (bytes == null) return '—'
    if (bytes < 1024 * 1024) return `${number(bytes / 1024, { maximumFractionDigits: 0 })} KB`
    return `${number(bytes / (1024 * 1024), { maximumFractionDigits: 1 })} MB`
  }

  return { number, compact, sar, percent, date, dateTime, relative, daysUntil, fileSize, numLocale }
}

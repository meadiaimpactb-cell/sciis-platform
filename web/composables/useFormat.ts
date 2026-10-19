/**
 * Formatting helpers — UX-02 "Arabic-safe number formatting".
 * Numbers use Latin digits in both locales via `ar-SA-u-nu-latn`.
 */
export const useFormat = () => {
  const { locale, t } = useI18n()

  const numLocale = computed(() => (locale.value === 'ar' ? 'ar-SA-u-nu-latn' : 'en-US'))

  const number = (v: number | null | undefined, opts: Intl.NumberFormatOptions = {}) => {
    if (v == null || Number.isNaN(v)) return '—'
    return new Intl.NumberFormat(numLocale.value, { maximumFractionDigits: 1, ...opts }).format(v)
  }

  const compact = (v: number | null | undefined) => {
    if (v == null) return '—'
    return new Intl.NumberFormat(numLocale.value, { notation: 'compact', maximumFractionDigits: 1 }).format(v)
  }

  const sar = (v: number | null | undefined) => {
    if (v == null) return '—'
    return `${number(v, { maximumFractionDigits: 0 })} ${t('common.sar')}`
  }

  const percent = (v: number | null | undefined) => (v == null ? '—' : `${number(v, { maximumFractionDigits: 0 })}٪`.replace('٪', locale.value === 'ar' ? '٪' : '%'))

  const date = (iso: string | null | undefined, opts: Intl.DateTimeFormatOptions = { year: 'numeric', month: 'short', day: 'numeric' }) => {
    if (!iso) return '—'
    const d = new Date(iso)
    if (Number.isNaN(d.getTime())) return '—'
    return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-SA-u-nu-latn-ca-gregory' : 'en-GB', opts).format(d)
  }

  const dateTime = (iso: string | null | undefined) => date(iso, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })

  const relative = (iso: string | null | undefined) => {
    if (!iso) return '—'
    const diff = (new Date(iso).getTime() - Date.now()) / 1000
    const rtf = new Intl.RelativeTimeFormat(locale.value === 'ar' ? 'ar-SA-u-nu-latn' : 'en', { numeric: 'auto' })
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

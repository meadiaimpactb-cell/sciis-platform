/** Translate enum values through i18n `enums.*` maps; taxonomy terms by locale. */
import type { TaxonomyTerm } from '~/types/api'

export const useEnumLabel = () => {
  const { t, te, locale } = useI18n()
  const label = (group: string, value: string | null | undefined) => {
    if (!value) return '—'
    const key = `enums.${group}.${value}`
    return te(key) ? t(key) : value
  }
  const term = (term: TaxonomyTerm | null | undefined) => (term ? (locale.value === 'ar' ? term.label_ar : term.label_en || term.label_ar) : '—')
  return { label, term }
}

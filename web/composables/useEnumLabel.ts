/**
 * Translate enum values through i18n `enums.*` maps; taxonomy terms by locale.
 *
 * Accepts both API shapes: the mock sends enum keys (`'equity'`) and full `TaxonomyTerm`s (`label_ar`/`label_en`),
 * the Laravel resources send `{id, key, label, label_ar, label_en}` (`FormatsOpportunity::term()`).
 */
import type { TaxonomyTerm, TermLike } from '~/types/api'

type TermInput = TaxonomyTerm | TermLike | { key?: string; label?: string; label_ar?: string; label_en?: string } | null | undefined
export type EnumLike = string | { key?: string | null; value?: string | null } | null | undefined

/** Enum key from a string or a `{key}` / `{value}` object. */
export const enumKey = (value: EnumLike): string | null => {
  if (!value) return null
  if (typeof value === 'string') return value
  return value.key ?? value.value ?? null
}

export const useEnumLabel = () => {
  const { t, te, locale } = useI18n()

  const label = (group: string, value: EnumLike) => {
    const key = enumKey(value)
    if (!key) return '—'
    const i18nKey = `enums.${group}.${key}`
    if (te(i18nKey)) return t(i18nKey)
    // Taxonomy-backed enums (investment types…) may arrive as terms carrying their own label.
    if (value && typeof value === 'object') {
      const obj = value as { label?: string; label_ar?: string; label_en?: string }
      const own = locale.value === 'ar' ? obj.label_ar || obj.label : obj.label_en || obj.label || obj.label_ar
      if (own) return own
    }
    return key
  }

  const term = (term: TermInput) => {
    if (!term) return '—'
    const tl = term as { key?: string; label?: string; label_ar?: string; label_en?: string }
    const localized = locale.value === 'ar' ? tl.label_ar || tl.label_en : tl.label_en || tl.label_ar
    return localized || tl.label || tl.key || '—'
  }

  return { label, term, enumKey }
}

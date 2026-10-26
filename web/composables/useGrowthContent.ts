import type { ContentItem } from '~/types/api'

/**
 * Social proof + knowledge content managed from admin → content (stories / partners / resources).
 * Every fetch resolves to an array (never null) so prerendered pages stay stable, and shared keys let the layout and
 * the pages reuse one SSR payload. Sections that depend on them render only when items exist.
 */
const fetchList = (path: string) => {
  const api = useApi()
  return () => api.get<{ data: ContentItem[] }>(path).then((r) => (Array.isArray(r?.data) ? r.data : [])).catch((): ContentItem[] => [])
}

export function usePublicStories() {
  const { locale } = useI18n()
  return useAsyncData(`public-stories-${locale.value}`, fetchList('/public/stories'), { default: (): ContentItem[] => [] })
}

export function usePublicPartners() {
  const { locale } = useI18n()
  return useAsyncData(`public-partners-${locale.value}`, fetchList('/public/partners'), { default: (): ContentItem[] => [] })
}

export function usePublicResources() {
  const { locale } = useI18n()
  return useAsyncData(`public-resources-${locale.value}`, fetchList('/public/resources'), { default: (): ContentItem[] => [] })
}

/** Localized alt / caption helpers for CMS images (meta.cover / meta.logo). */
export function useContentText() {
  const { locale } = useI18n()
  const alt = (img: { alt_ar?: string | null; alt_en?: string | null } | null | undefined, fallback = '') =>
    (locale.value === 'en' && img?.alt_en ? img.alt_en : img?.alt_ar) || fallback
  const title = (c: ContentItem) => c.title ?? (locale.value === 'en' && c.title_en ? c.title_en : c.title_ar)
  const body = (c: ContentItem) => c.body ?? (locale.value === 'en' && c.body_en ? c.body_en : c.body_ar) ?? ''
  return { alt, title, body }
}

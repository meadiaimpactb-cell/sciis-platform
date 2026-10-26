/**
 * CMS-driven marketing copy for a public page (`GET /public/pages/{slug}` → meta.sections), with the i18n
 * locale texts as the fallback whenever a section is missing or empty (mock mode, API down, new keys).
 *
 *   const { text, list, items } = await usePageContent('about')
 *   text('lead', 'about.lead') · list('goals', 'about.goalsList') · items<{ title: string; text: string }>('why', 'forInvestors.why')
 */
import type { PublicPage } from '~/types/api'
import type { PageSections, SectionItem, SectionValue } from '~/utils/pageSections'

const isFilled = (v: unknown) => typeof v === 'string' ? v.trim() !== '' : Array.isArray(v) ? v.length > 0 : v != null

export async function usePageContent(slug: string) {
  const api = useApi()
  const { t, tm, rt, locale } = useI18n()

  const { data: page } = await useAsyncData(`page-content-${slug}-${locale.value}`, () =>
    api.get<{ data: PublicPage }>(`/public/pages/${slug}`).then((r): Partial<PublicPage> => r.data ?? {}).catch((): Partial<PublicPage> => ({})),
  )

  const sections = computed<PageSections>(() => {
    const s = page.value?.meta?.sections
    return s && typeof s === 'object' && !Array.isArray(s) ? (s as PageSections) : {}
  })

  /** CMS value for the current locale (null when missing or empty). */
  const cms = (key: string): SectionValue | undefined => {
    const entry = sections.value[key]
    if (!entry) return undefined
    const v = entry[locale.value === 'en' ? 'en' : 'ar']
    return isFilled(v) ? v : undefined
  }

  /** i18n raw messages as a list (objects keyed 1..n — e.g. `home.how` — become arrays). */
  const raw = (i18nKey: string): unknown[] => {
    const m = tm(i18nKey) as unknown
    if (Array.isArray(m)) return m
    return m && typeof m === 'object' ? Object.values(m as Record<string, unknown>) : []
  }
  // rt() resolves compiled messages; plain primitives (booleans, numbers) pass through untouched.
  const resolve = (v: unknown): unknown => (typeof v === 'boolean' || typeof v === 'number' || v == null ? v : rt(v as Parameters<typeof rt>[0]))

  const text = (key: string, i18nKey: string): string => {
    const v = cms(key)
    return typeof v === 'string' ? v : t(i18nKey)
  }

  const list = (key: string, i18nKey: string): string[] => {
    const v = cms(key)
    if (Array.isArray(v)) {
      const strings = v.filter((x): x is string => typeof x === 'string' && x.trim() !== '')
      if (strings.length) return strings
    }
    return raw(i18nKey).map((x) => String(resolve(x) ?? ''))
  }

  const items = <T extends object = SectionItem>(key: string, i18nKey: string): T[] => {
    const v = cms(key)
    if (Array.isArray(v)) {
      const objects = v.filter((x): x is SectionItem => !!x && typeof x === 'object' && !Array.isArray(x))
      if (objects.length) return objects as unknown as T[]
    }
    return raw(i18nKey).map((x) => {
      const obj = (x && typeof x === 'object' ? x : {}) as Record<string, unknown>
      return Object.fromEntries(Object.entries(obj).map(([k, fv]) => [k, resolve(fv)])) as T
    })
  }

  return { page, sections, text, list, items }
}

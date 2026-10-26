/**
 * Page-level SEO: title, description, canonical, Open Graph / Twitter cards and optional JSON-LD.
 * hreflang/alternate links come from `useLocaleHead` in app.vue (which also appends the brand to <title> via its
 * titleTemplate); this composable adds the per-page part.
 */
export interface SeoOptions {
  title: string
  description?: string
  /** Absolute or site-relative image URL (defaults to the 1200×630 brand share image). */
  image?: string
  imageAlt?: string
  type?: 'website' | 'article'
  noindex?: boolean
  jsonLd?: Record<string, unknown> | Record<string, unknown>[]
  publishedTime?: string
  modifiedTime?: string
}

/** Brand name used as the <title> suffix and og:site_name. */
export const brandName = (locale: string) => (locale === 'en' ? 'Saudi Council for Impact Investors (SCII)' : 'المجلس السعودي للمستثمرين الاجتماعيين')

/** Search results show ~60 characters of a title; longer ones are cut mid-word. */
const MAX_TITLE = 60

/**
 * "Page | Brand" unless the title already carries the brand (home page). When that would be too long for search
 * results, the short brand ("SCII") is used, and a title that is long on its own stays unsuffixed.
 */
export const withBrand = (title: string | undefined, locale: string) => {
  const brand = brandName(locale)
  if (!title) return brand
  if (title.includes(brand) || title.includes('SCII')) return title
  const full = `${title} | ${brand}`
  if (full.length <= MAX_TITLE) return full
  const short = `${title} | SCII`
  return short.length <= MAX_TITLE ? short : title
}

export function useSiteUrl() {
  const config = useRuntimeConfig()
  return (config.public.siteUrl as string | undefined)?.replace(/\/$/, '') || 'http://localhost:3000'
}

const DEFAULT_IMAGE = '/brand/og-image.jpg'

export function useSeo(opts: SeoOptions) {
  const route = useRoute()
  const { locale } = useI18n()
  const siteUrl = useSiteUrl()
  const abs = (u?: string) => {
    const v = u || DEFAULT_IMAGE
    return v.startsWith('http') ? v : `${siteUrl}${v.startsWith('/') ? '' : '/'}${v}`
  }
  const canonical = `${siteUrl}${route.path === '/' ? '' : route.path.replace(/\/$/, '')}` || siteUrl
  const fullTitle = withBrand(opts.title, locale.value)
  const isDefaultImage = !opts.image

  useSeoMeta({
    title: opts.title,
    description: opts.description,
    ogTitle: fullTitle,
    ogDescription: opts.description,
    ogImage: abs(opts.image),
    ogImageWidth: isDefaultImage ? 1200 : undefined,
    ogImageHeight: isDefaultImage ? 630 : undefined,
    ogImageAlt: opts.imageAlt ?? brandName(locale.value),
    ogUrl: canonical,
    ogType: opts.type ?? 'website',
    ogSiteName: brandName(locale.value),
    ogLocale: locale.value === 'ar' ? 'ar_SA' : 'en_US',
    ogLocaleAlternate: locale.value === 'ar' ? 'en_US' : 'ar_SA',
    twitterCard: 'summary_large_image',
    twitterTitle: fullTitle,
    twitterDescription: opts.description,
    twitterImage: abs(opts.image),
    robots: opts.noindex ? 'noindex, nofollow' : 'index, follow, max-image-preview:large, max-snippet:-1',
    articlePublishedTime: opts.publishedTime,
    articleModifiedTime: opts.modifiedTime,
  })

  const scripts = opts.jsonLd
    ? (Array.isArray(opts.jsonLd) ? opts.jsonLd : [opts.jsonLd]).map((ld) => ({
        type: 'application/ld+json',
        innerHTML: JSON.stringify({ '@context': 'https://schema.org', ...ld }),
      }))
    : []

  useHead({
    link: [{ rel: 'canonical', href: canonical }],
    script: scripts,
  })

  return { canonical, siteUrl }
}

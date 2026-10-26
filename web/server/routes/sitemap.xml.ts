/**
 * Dynamic sitemap: static public pages in both locales + published news/events from the API, and the
 * stories / resources list pages only once they have published items (they are noindex while empty).
 * Cached for 10 minutes. Portal/auth routes are intentionally excluded (see public/robots.txt).
 */
const STATIC = ['/', '/about', '/impact-investing', '/for-investors', '/for-entrepreneurs', '/tools', '/tools/investor-readiness', '/tools/project-readiness', '/faq', '/contact', '/news', '/events', '/legal/privacy', '/legal/terms', '/legal/disclaimer']
/** List pages that are listed only when the API returns at least one published item. */
const LISTS = [{ path: '/stories', api: 'stories' }, { path: '/resources', api: 'resources' }] as const

interface Item { slug: string; body?: string | null; published_at?: string | null; updated_at?: string | null }

export default defineCachedEventHandler(
  async (event) => {
    const config = useRuntimeConfig(event)
    const site = ((config.public.siteUrl as string) || 'http://localhost:3000').replace(/\/$/, '')
    const apiBase = (config.public.apiBase as string).replace(/\/$/, '')
    const today = new Date().toISOString().slice(0, 10)

    const dynamic: { path: string; lastmod?: string; en?: boolean }[] = []
    for (const kind of ['news', 'events'] as const) {
      try {
        const res = await $fetch<{ data: Item[] }>(`${apiBase}/public/${kind}?per_page=200`, { headers: { Accept: 'application/json' } })
        // The English URL of an item without an English body shows the Arabic text (and is noindex): list it only
        // when the English body exists.
        const english = await $fetch<{ data: Item[] }>(`${apiBase}/public/${kind}?per_page=200`, { headers: { Accept: 'application/json', 'Accept-Language': 'en' } }).catch(() => ({ data: [] as Item[] }))
        const translated = new Set((english.data ?? []).filter((it) => !/[؀-ۿ]/.test((it.body ?? '').slice(0, 400))).map((it) => it.slug))
        for (const it of res.data ?? []) dynamic.push({ path: `/${kind}/${it.slug}`, lastmod: (it.updated_at ?? it.published_at ?? '').slice(0, 10) || undefined, en: translated.has(it.slug) })
      } catch {
        /* API unavailable → static pages only */
      }
    }

    for (const list of LISTS) {
      try {
        const res = await $fetch<{ data: Item[] }>(`${apiBase}/public/${list.api}`, { headers: { Accept: 'application/json' } })
        const items = res.data ?? []
        if (items.length) dynamic.push({ path: list.path, lastmod: items.map((it) => (it.updated_at ?? it.published_at ?? '').slice(0, 10)).sort().pop() || today })
      } catch {
        /* API unavailable → skip */
      }
    }

    const urls: { path: string; lastmod?: string; en?: boolean }[] = [...STATIC.map((p) => ({ path: p, lastmod: today })), ...dynamic]
    const entry = (path: string, lastmod?: string, withEnglish = true) => {
      const ar = `${site}${path === '/' ? '' : path}` || site
      const en = `${site}/en${path === '/' ? '' : path}`
      if (!withEnglish) return `<url><loc>${ar}</loc>${lastmod ? `<lastmod>${lastmod}</lastmod>` : ''}</url>`
      return `<url><loc>${ar}</loc>${lastmod ? `<lastmod>${lastmod}</lastmod>` : ''}<xhtml:link rel="alternate" hreflang="ar-SA" href="${ar}"/><xhtml:link rel="alternate" hreflang="en" href="${en}"/><xhtml:link rel="alternate" hreflang="x-default" href="${ar}"/></url>\n<url><loc>${en}</loc>${lastmod ? `<lastmod>${lastmod}</lastmod>` : ''}<xhtml:link rel="alternate" hreflang="ar-SA" href="${ar}"/><xhtml:link rel="alternate" hreflang="en" href="${en}"/></url>`
    }
    const xml = `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">\n${urls.map((u) => entry(u.path, u.lastmod, u.en ?? true)).join('\n')}\n</urlset>`
    setHeader(event, 'Content-Type', 'application/xml; charset=utf-8')
    return xml
  },
  // The static build prerenders this once: never reuse a stored copy there (its stale-while-revalidate cache would
  // otherwise publish the previous build's sitemap and miss newly published items).
  { maxAge: 600, name: 'sitemap', shouldBypassCache: () => !!import.meta.prerender },
)

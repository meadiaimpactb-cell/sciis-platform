// https://nuxt.com/docs/api/configuration/nuxt-config
// Public pages cached with stale-while-revalidate (seconds) — applied in production only, see routeRules.
const publicCacheRules = {
  '/': { swr: 300 },
  '/en': { swr: 300 },
  '/about': { swr: 3600 },
  '/impact-investing': { swr: 3600 },
  '/for-investors': { swr: 3600 },
  '/for-entrepreneurs': { swr: 3600 },
  '/faq': { swr: 600 },
  '/contact': { swr: 3600 },
  '/legal/**': { swr: 3600 },
  '/news/**': { swr: 300 },
  '/events/**': { swr: 300 },
  '/tools': { swr: 3600 },
  '/tools/**': { swr: 3600 },
  '/stories': { swr: 300 },
  '/resources': { swr: 600 },
  '/en/tools': { swr: 3600 },
  '/en/tools/**': { swr: 3600 },
  '/en/stories': { swr: 300 },
  '/en/resources': { swr: 600 },
  '/en/about': { swr: 3600 },
  '/en/impact-investing': { swr: 3600 },
  '/en/for-investors': { swr: 3600 },
  '/en/for-entrepreneurs': { swr: 3600 },
  '/en/faq': { swr: 600 },
  '/en/contact': { swr: 3600 },
  '/en/legal/**': { swr: 3600 },
  '/en/news/**': { swr: 300 },
  '/en/events/**': { swr: 300 },
}

// Static hosting build (`SCIIS_STATIC=1 nuxi generate`, e.g. shared hosting without Node.js): public pages are
// prerendered for SEO/speed, the portal is a client-only SPA, and plugins/static-refresh.client.ts re-fetches page data
// from the live API after load so CMS edits are visible immediately.
const isStatic = process.env.SCIIS_STATIC === '1'

// SCIIS web — Nuxt 3 + Vue 3 + TypeScript + Tailwind, Arabic RTL by default (UX-01/02).
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: false },
  ssr: true,

  modules: ['@nuxtjs/tailwindcss', '@nuxtjs/i18n', '@pinia/nuxt', '@vueuse/nuxt'],

  css: [
    // Two weights only, like falak.sa (Almarai 400/700): the browser maps font-medium → 400 and font-semibold → 700,
    // which saves two Arabic + two Latin font files (~130 KB) per visit.
    '@fontsource/ibm-plex-sans-arabic/400.css',
    '@fontsource/ibm-plex-sans-arabic/700.css',
    // Public identity v3 (header, footer, home): Plex 500/600 for labels and buttons, Almarai for Arabic display
    // headings, Sora for English ones. A face downloads only when a page uses it (and only the subsets it needs).
    '@fontsource/ibm-plex-sans-arabic/500.css',
    '@fontsource/ibm-plex-sans-arabic/600.css',
    '@fontsource/almarai/700.css',
    '@fontsource/almarai/800.css',
    '@fontsource/sora/600.css',
    '@fontsource/sora/700.css',
    '~/assets/css/tokens.css',
    '~/assets/css/main.css',
    '~/assets/css/theme-site.css',
  ],

  app: {
    head: {
      htmlAttrs: { lang: 'ar', dir: 'rtl' },
      meta: [
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
        { name: 'theme-color', content: '#182455' },
        { property: 'og:site_name', content: 'SCII' },
        { name: 'twitter:card', content: 'summary_large_image' },
        { name: 'format-detection', content: 'telephone=no' },
      ],
      link: [
        { rel: 'icon', type: 'image/svg+xml', href: '/favicon.svg' },
        { rel: 'icon', type: 'image/png', sizes: '32x32', href: '/favicon-32.png' },
        { rel: 'apple-touch-icon', sizes: '180x180', href: '/apple-touch-icon.png' },
        { rel: 'manifest', href: '/site.webmanifest' },
      ],
      // Theme (design v3, site and portals): the saved choice is applied before the first paint, so a reload never
      // flashes the default dark palette. No attribute = dark (tokens.css); 'system' follows the device.
      script: [
        {
          innerHTML: "try{var t=localStorage.getItem('scii-theme');if(t==='system')t=matchMedia('(prefers-color-scheme: light)').matches?'light':'dark';if(t==='light'||t==='dark')document.documentElement.setAttribute('data-theme',t)}catch(e){}",
          tagPosition: 'head',
        },
      ],
    },
  },

  runtimeConfig: {
    // Server-only (never serialised to the client): lets the static build's renders skip the API rate limits.
    // Set by infra/hostinger/deploy.sh from the server's SCIIS_PRERENDER_TOKEN. Overridden by NUXT_PRERENDER_TOKEN.
    prerenderToken: process.env.NUXT_PRERENDER_TOKEN || '',
    public: {
      // Real API base (Laravel). Overridden by NUXT_PUBLIC_API_BASE.
      // Read at build time too: the static build (and Nitro prerender routes such as /sitemap.xml) bake this value.
      apiBase: process.env.NUXT_PUBLIC_API_BASE || 'http://localhost:8000/api/v1',
      // Mock mode: Nitro routes under /api/v1 serve synthetic data (SPEC §11). Overridden by NUXT_PUBLIC_API_MOCK.
      apiMock: 'true',
      platformShortName: 'SCII',
      // Public site URL used for canonical/OG/sitemap links. Overridden by NUXT_PUBLIC_SITE_URL.
      siteUrl: process.env.NUXT_PUBLIC_SITE_URL || 'http://localhost:3000',
      // 'true' in the static hosting build (see isStatic above). Overridden by NUXT_PUBLIC_STATIC_MODE.
      staticMode: isStatic ? 'true' : 'false',
    },
  },

  i18n: {
    // Locale files live in /locales at the project root (restructureDir disabled on purpose).
    restructureDir: false,
    langDir: 'locales',
    // Only the active locale's messages are downloaded (both together were ~170 KB of the entry chunk).
    lazy: true,
    strategy: 'prefix_except_default',
    baseUrl: process.env.NUXT_PUBLIC_SITE_URL || 'http://localhost:3000',
    defaultLocale: 'ar',
    locales: [
      { code: 'ar', language: 'ar-SA', dir: 'rtl', name: 'العربية', file: 'ar.json' },
      { code: 'en', language: 'en', dir: 'ltr', name: 'English', file: 'en.json' },
    ],
    detectBrowserLanguage: false,
    vueI18n: './i18n.config.ts',
    compilation: { strictMessage: false },
    bundle: { optimizeTranslationDirective: false },
  },

  tailwindcss: {
    cssPath: false,
    configPath: 'tailwind.config.ts',
    viewer: false,
  },

  routeRules: {
    // Public pages: SSR + stale-while-revalidate cache in production only (fast TTFB, content still refreshes from
    // the CMS/API). Disabled in dev so edits are never hidden behind a stale cached page.
    ...(process.env.NODE_ENV === 'production' && !isStatic ? publicCacheRules : {}),
    // Static assets: long-lived immutable cache.
    '/brand/**': { headers: { 'cache-control': 'public, max-age=31536000, immutable' } },
    ...(process.env.SCIIS_SSR_ALL === '1' ? {} : {
      // Portal and auth screens are client-only (SPEC §0: "client-only portal").
      '/app/**': { ssr: false },
      '/en/app/**': { ssr: false },
      '/auth/**': { ssr: false },
      '/en/auth/**': { ssr: false },
      '/prototype': { ssr: false },
      '/en/prototype': { ssr: false },
    }),
  },

  nitro: {
    // Mock API routes live in server/api/v1 — they are always built, but only used when apiMock=true.
    experimental: { openAPI: false },
    // Pre-compress built assets (br/gzip) so the server can serve them without CPU cost.
    compressPublicAssets: true,
    ...(isStatic
      ? {
          prerender: {
            crawlLinks: true,
            // `about.html` instead of `about/index.html` → clean URLs without a trailing-slash redirect on Apache/LiteSpeed.
            autoSubfolderIndex: false,
            routes: ['/', '/en', '/sitemap.xml'],
            ignore: ['/app', '/en/app', '/prototype', '/en/prototype'],
            // Keep the live API's per-IP rate limit happy while it serves the build.
            concurrency: 2,
            interval: 250,
            failOnError: false,
          },
        }
      : {}),
  },

  // Nuxt 3 default; made explicit because the public pages rely on it for a small hydration payload.
  // Static build: no _payload.json files, so client-side navigation always fetches fresh data from the API.
  experimental: { payloadExtraction: !isStatic },

  typescript: {
    strict: true,
    typeCheck: false,
  },

  vite: {
    build: { sourcemap: false },
  },
})

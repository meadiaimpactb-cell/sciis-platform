// https://nuxt.com/docs/api/configuration/nuxt-config
// SCIIS web — Nuxt 3 + Vue 3 + TypeScript + Tailwind, Arabic RTL by default (UX-01/02).
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: false },
  ssr: true,

  modules: ['@nuxtjs/tailwindcss', '@nuxtjs/i18n', '@pinia/nuxt', '@vueuse/nuxt'],

  css: [
    '@fontsource/ibm-plex-sans-arabic/400.css',
    '@fontsource/ibm-plex-sans-arabic/500.css',
    '@fontsource/ibm-plex-sans-arabic/600.css',
    '@fontsource/ibm-plex-sans-arabic/700.css',
    '~/assets/css/tokens.css',
    '~/assets/css/main.css',
  ],

  app: {
    head: {
      htmlAttrs: { lang: 'ar', dir: 'rtl' },
      titleTemplate: '%s · SCII',
      meta: [
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
        { name: 'theme-color', content: '#0A1020' },
      ],
      link: [{ rel: 'icon', type: 'image/svg+xml', href: '/favicon.svg' }],
    },
  },

  runtimeConfig: {
    public: {
      // Real API base (Laravel). Overridden by NUXT_PUBLIC_API_BASE.
      apiBase: 'http://localhost:8000/api/v1',
      // Mock mode: Nitro routes under /api/v1 serve synthetic data (SPEC §11). Overridden by NUXT_PUBLIC_API_MOCK.
      apiMock: 'true',
      platformShortName: 'SCII',
    },
  },

  i18n: {
    // Locale files live in /locales at the project root (restructureDir disabled on purpose).
    restructureDir: false,
    langDir: 'locales',
    lazy: false,
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

  routeRules: process.env.SCIIS_SSR_ALL === '1' ? {} : {
    // Portal and auth screens are client-only (SPEC §0: "client-only portal").
    '/app/**': { ssr: false },
    '/en/app/**': { ssr: false },
    '/auth/**': { ssr: false },
    '/en/auth/**': { ssr: false },
    '/prototype': { ssr: false },
    '/en/prototype': { ssr: false },
  },

  nitro: {
    // Mock API routes live in server/api/v1 — they are always built, but only used when apiMock=true.
    experimental: { openAPI: false },
  },

  typescript: {
    strict: true,
    typeCheck: false,
  },

  vite: {
    build: { sourcemap: false },
  },
})

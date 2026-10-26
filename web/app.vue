<script setup lang="ts">
// The body face and the headline face of the current script, preloaded so they are ready at first paint (no swap →
// no layout shift of the centred hero). The home headline is Almarai / Sora (design v3); the other pages' heroes use
// Plex bold. Same hashed files as the @fontsource CSS, so nothing is downloaded twice.
import arabic400 from '@fontsource/ibm-plex-sans-arabic/files/ibm-plex-sans-arabic-arabic-400-normal.woff2?url'
import arabic700 from '@fontsource/ibm-plex-sans-arabic/files/ibm-plex-sans-arabic-arabic-700-normal.woff2?url'
import latin400 from '@fontsource/ibm-plex-sans-arabic/files/ibm-plex-sans-arabic-latin-400-normal.woff2?url'
import latin700 from '@fontsource/ibm-plex-sans-arabic/files/ibm-plex-sans-arabic-latin-700-normal.woff2?url'
import almarai800 from '@fontsource/almarai/files/almarai-arabic-800-normal.woff2?url'
import sora700 from '@fontsource/sora/files/sora-latin-700-normal.woff2?url'

const { locale, t } = useI18n()
const route = useRoute()
const localePath = useLocalePath()
const fontPreloads = computed(() => {
  const ar = locale.value === 'ar'
  const headline = route.path === localePath('/') ? (ar ? almarai800 : sora700) : ar ? arabic700 : latin700
  return [ar ? arabic400 : latin400, headline].map((href) => ({ rel: 'preload' as const, as: 'font' as const, type: 'font/woff2', href, crossorigin: 'anonymous' as const }))
})
const head = useLocaleHead({ dir: true, lang: true })
const siteUrl = useSiteUrl()
// Official channels from admin → settings (shared SSR payload with the footer/contact page).
const contact = usePlatformContact()

// Site-wide structured data (Organization + WebSite). Pages add their own (FAQPage, Article, Breadcrumbs).
const organization = computed(() => ({
  '@context': 'https://schema.org',
  '@type': 'Organization',
  '@id': `${siteUrl}/#organization`,
  name: t('app.name'),
  alternateName: 'SCII',
  url: siteUrl,
  logo: { '@type': 'ImageObject', url: `${siteUrl}/brand/logo.png`, width: 900, height: 306 },
  email: contact.email.value || 'info@sciis.sa',
  areaServed: 'SA',
  description: t('app.tagline'),
  // Official social profiles (platform.social.*) — only the ones that are set.
  ...(contact.social.value.length ? { sameAs: contact.social.value.map((s) => s.url) } : {}),
}))
const website = computed(() => ({
  '@context': 'https://schema.org',
  '@type': 'WebSite',
  '@id': `${siteUrl}/#website`,
  url: siteUrl,
  name: t('app.name'),
  inLanguage: locale.value === 'ar' ? 'ar-SA' : 'en',
  publisher: { '@id': `${siteUrl}/#organization` },
}))

useHead({
  // "Page | المجلس السعودي للمستثمرين الاجتماعيين" (pages that already carry the brand, like home, are left as-is).
  titleTemplate: (title?: string) => withBrand(title, locale.value),
  htmlAttrs: { lang: () => locale.value, dir: () => (locale.value === 'ar' ? 'rtl' : 'ltr') },
  link: () => [...fontPreloads.value, ...(head.value.link ?? [])],
  meta: () => [...(head.value.meta ?? []), { name: 'description', content: t('app.tagline') }],
  script: () => [
    { type: 'application/ld+json', innerHTML: JSON.stringify(organization.value) },
    { type: 'application/ld+json', innerHTML: JSON.stringify(website.value) },
  ],
})
</script>

<template>
  <div class="min-h-screen flex flex-col">
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
  </div>
</template>

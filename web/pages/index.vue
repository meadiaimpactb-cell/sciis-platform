<script setup lang="ts">
/** Home — doc §7.1 / §10.2. Section order and type scale follow falak.sa (see components/PageHero.vue). */
import type { OpportunityCard, PublicStats, PublicStatsRaw, ContentItem, HeroImage } from '~/types/api'

definePageMeta({ layout: 'default' })
const { t, locale } = useI18n()
useSeo({
  title: t('home.seoTitle'),
  description: t('home.seoDescription'),
  jsonLd: { '@type': 'WebPage', name: t('home.seoTitle'), description: t('home.seoDescription'), isPartOf: { '@id': `${useSiteUrl()}/#website` }, about: { '@id': `${useSiteUrl()}/#organization` } },
})
const api = useApi()
const { number } = useFormat()
const join = useJoinChooser()

// CMS copy (`home` page → meta.sections) with the i18n texts as fallback.
const { page: homePage, text, list, items } = await usePageContent('home')
// BRD-03 — hero images managed from the CMS (`home` page → meta.hero_images, max 3): a cross-fading backdrop of the hero.
const heroImages = computed<HeroImage[]>(() => (homePage.value?.meta?.hero_images ?? []).filter((i): i is HeroImage => !!i?.url).slice(0, 3))
const heroAlt = (img: HeroImage) => (locale.value === 'en' && img.alt_en ? img.alt_en : img.alt_ar) || ''
const slide = ref(0)
const heroCount = computed(() => heroImages.value?.length ?? 0)
let heroTimer: ReturnType<typeof setInterval> | null = null
const stopHero = () => { if (heroTimer) { clearInterval(heroTimer); heroTimer = null } }
// UX-04 (WCAG 2.2.2) — the auto-advancing backdrop can be paused; it also stops on hover / keyboard focus.
const heroPaused = ref(false)
const startHero = () => {
  stopHero()
  if (!heroPaused.value && heroCount.value > 1 && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) heroTimer = setInterval(() => { slide.value = (slide.value + 1) % heroCount.value }, 5000)
}
const goSlide = (i: number) => { slide.value = i; startHero() }
const toggleHero = () => { heroPaused.value = !heroPaused.value; if (heroPaused.value) stopHero(); else startHero() }
onMounted(startHero)
onBeforeUnmount(stopHero)

const { data: featured } = await useAsyncData('home-featured', () => api.get<{ data: OpportunityCard[] }>('/public/featured-opportunities').then((r) => r.data).catch(() => []))
// Accept both the mock shape (PublicStats) and the Laravel shape (PublicStatsRaw).
const normalizeStats = (s: Partial<PublicStats & PublicStatsRaw>): PublicStats => ({
  opportunities: s.opportunities ?? s.opportunities_eligible ?? 0,
  members: s.members ?? s.approved_investors ?? 0,
  sectors: s.sectors ?? s.sectors_covered ?? 0,
  regions: s.regions,
  connections: s.connections ?? 0,
})
const { data: stats } = await useAsyncData('home-stats', () => api.get<{ data: Partial<PublicStats & PublicStatsRaw> }>('/public/stats').then((r) => normalizeStats(r.data)).catch(() => false as const))
// Social proof (CMS stories / partners) — each section renders only when published items exist.
const { data: stories } = await usePublicStories()
const { data: partners } = await usePublicPartners()
const { data: news } = await useAsyncData('home-news', () => api.get<{ data: ContentItem[] }>('/public/news').then((r) => r.data.slice(0, 3)).catch(() => []))
const statKeys = ['opportunities', 'members', 'sectors', 'connections'] as const
</script>

<template>
  <div class="bg-surface">
    <!-- HERO — full first screen, centred (the public header overlays it) -->
    <PageHero tall :kicker="text('hero_eyebrow', 'home.heroEyebrow')" :title="text('hero_title', 'home.heroTitle')" :lead="text('hero_text', 'home.heroText')">
      <template v-if="heroCount" #backdrop>
        <div class="absolute inset-0" role="region" aria-roledescription="carousel" :aria-label="$t('home.heroGalleryLabel')">
          <img
            v-for="(img, i) in heroImages" :key="img.url"
            :src="img.url" :alt="heroAlt(img)" :width="img.width ?? 1600" :height="img.height ?? 1200"
            :loading="i === 0 ? 'eager' : 'lazy'" :fetchpriority="i === 0 ? 'high' : undefined" decoding="async"
            class="absolute inset-0 h-full w-full object-cover transition-opacity duration-700 ease-out" :class="i === slide ? 'opacity-100' : 'opacity-0'" :aria-hidden="i === slide ? undefined : 'true'"
          >
          <div class="absolute inset-0 bg-navy-950/80" aria-hidden="true" />
        </div>
      </template>
      <AppButton variant="accent" size="lg" aria-haspopup="dialog" @click="join.open($event)">{{ $t('home.ctaExplore') }}</AppButton>
      <AppButton to="/for-entrepreneurs" size="lg" variant="line">{{ $t('home.ctaSubmit') }}</AppButton>
      <template #bottom>
        <div v-if="heroCount > 1" class="mb-5 flex items-center justify-center gap-2" role="group" :aria-label="$t('home.heroGalleryLabel')" @mouseenter="stopHero" @mouseleave="startHero" @focusin="stopHero" @focusout="startHero">
          <button type="button" class="h-6 w-6 rounded-full text-white/80 hover:text-white flex items-center justify-center kbd-focus" :aria-pressed="heroPaused" :aria-label="$t('platform.a11y.pauseSlides')" @click="toggleHero">
            <svg v-if="heroPaused" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6 4l10 6-10 6V4z" /></svg>
            <svg v-else class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6 4h3v12H6zM11 4h3v12h-3z" /></svg>
          </button>
          <button v-for="(img, i) in heroImages" :key="`dot-${img.url}`" type="button" :aria-current="i === slide ? 'true' : undefined" :aria-label="$t('home.heroSlide', { n: i + 1, total: heroCount })" class="h-2.5 rounded-full transition-all kbd-focus" :class="i === slide ? 'w-7 bg-teal' : 'w-2.5 bg-white/50 hover:bg-white'" @click="goSlide(i)" />
        </div>
        <Disclaimer variant="dark" />
      </template>
    </PageHero>

    <!-- NUMBERS (Falak «ماذا حققنا») -->
    <section v-if="stats" class="surface-dark relative overflow-hidden" aria-labelledby="home-stats">
      <NightSky :orbits="false" />
      <div class="container-x relative section-y">
        <h2 id="home-stats" class="section-label text-white">{{ $t('home.statsTitle') }}</h2>
        <dl class="mt-14 grid gap-x-10 gap-y-14 sm:grid-cols-2 lg:grid-cols-4">
          <div v-for="k in statKeys" :key="k" class="flex flex-col-reverse gap-2">
            <dt class="stat-label text-white/60">{{ $t(`home.stats.${k}`) }}</dt>
            <dd class="stat-value text-white">{{ number(stats[k]) }}</dd>
          </div>
        </dl>
      </div>
    </section>

    <!-- Everything below the first screen hydrates when it scrolls into view (server-rendered for SEO). -->
    <LazyHomeBelowFold hydrate-on-visible :content="{ text, list, items }" :featured="featured" :stories="stories" :partners="partners" :news="news" />
  </div>
</template>

<script setup lang="ts">
/**
 * Home page below the first screen (paths, featured, impact, how it works + benefits, partners, story, news, CTA).
 * Rendered by pages/index.vue as `<LazyHomeBelowFold hydrate-on-visible>`: the HTML is server-rendered (crawlable,
 * no layout shift), but its JavaScript only loads and hydrates when the visitor scrolls to it — keeping the main
 * thread free while the first screen becomes interactive.
 */
import type { ContentItem, OpportunityCard } from '~/types/api'

/** The page's `usePageContent('home')` helpers (CMS copy with i18n fallbacks), passed down unchanged. */
type Content = Pick<Awaited<ReturnType<typeof usePageContent>>, 'text' | 'list' | 'items'>
const props = defineProps<{ content: Content; featured: OpportunityCard[] | null; stories: ContentItem[]; partners: ContentItem[]; news: ContentItem[] | null }>()
const { text, list, items } = props.content
const { number, date } = useFormat()
const join = useJoinChooser()

const benefits = computed(() => list('benefits', 'home.benefits'))
const impactPoints = computed(() => list('impact_points', 'home.impactPoints'))
const investorPoints = computed(() => list('investor_path_points', 'home.investorPath.points'))
const entrepreneurPoints = computed(() => list('entrepreneur_path_points', 'home.entrepreneurPath.points'))
const steps = computed(() => items<{ title?: string; text?: string }>('how_steps', 'home.how'))

// Featured impact story (first published) — Falak "Success Stories" layout: picture + 40px title + excerpt.
const { alt: contentAlt, title: contentTitle, body: contentBody } = useContentText()
const story = computed(() => props.stories[0] ?? null)
const storyExcerpt = computed(() => (story.value ? cmsExcerpt(contentBody(story.value), 240) : ''))
const tools = [
  { key: 'investor', to: '/tools/investor-readiness' },
  { key: 'project', to: '/tools/project-readiness' },
] as const
</script>

<template>
  <div>
      <!-- PATHS (Falak «البرامج»: three columns with vertical rules) -->
      <section class="section-y" aria-labelledby="home-paths">
        <div class="container-x">
          <h2 id="home-paths" class="section-label">{{ text('paths_title', 'home.pathsTitle') }}</h2>
          <p class="section-title mt-2 max-w-4xl">{{ text('paths_sub', 'home.pathsSub') }}</p>
          <div class="mt-14 grid border-b border-border md:grid-cols-3">
            <article class="flex flex-col items-center px-4 py-10 text-center md:px-8">
              <span class="flex h-14 w-14 items-center justify-center rounded-md border border-navy/20 text-navy" aria-hidden="true"><svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 20V10M10 20V4M16 20v-6M22 20H2" /></svg></span>
              <h3 class="card-title mt-6">{{ text('investor_path_title', 'home.investorPath.title') }}</h3>
              <p class="text-desc mt-3 text-muted">{{ text('investor_path_text', 'home.investorPath.text') }}</p>
              <ul class="mt-5 space-y-2 text-sm text-text">
                <li v-for="p in investorPoints" :key="p">{{ p }}</li>
              </ul>
              <NuxtLink :to="$localePath('/auth/register?type=investor_individual')" class="mt-auto inline-block border-b-2 border-current pb-1 pt-8 text-base font-bold text-ink transition hover:text-teal-text">{{ $t('home.investorPath.cta') }}</NuxtLink>
            </article>
            <article class="flex flex-col items-center border-t border-border px-4 py-10 text-center md:border-s md:border-t-0 md:px-8">
              <span class="flex h-14 w-14 items-center justify-center rounded-md border border-teal/40 text-teal-text" aria-hidden="true"><svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 2a7 7 0 017 7c0 3-2 5-3 6v3H8v-3C7 14 5 12 5 9a7 7 0 017-7zM9 22h6" /></svg></span>
              <h3 class="card-title mt-6">{{ text('entrepreneur_path_title', 'home.entrepreneurPath.title') }}</h3>
              <p class="text-desc mt-3 text-muted">{{ text('entrepreneur_path_text', 'home.entrepreneurPath.text') }}</p>
              <ul class="mt-5 space-y-2 text-sm text-text">
                <li v-for="p in entrepreneurPoints" :key="p">{{ p }}</li>
              </ul>
              <NuxtLink :to="$localePath('/auth/register?type=entrepreneur_entity')" class="mt-auto inline-block border-b-2 border-current pb-1 pt-8 text-base font-bold text-ink transition hover:text-teal-text">{{ $t('home.entrepreneurPath.cta') }}</NuxtLink>
            </article>
            <article class="flex flex-col items-center border-t border-border px-4 py-10 text-center md:border-s md:border-t-0 md:px-8">
              <span class="flex h-14 w-14 items-center justify-center rounded-md border border-navy/20 text-navy" aria-hidden="true"><svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11" /></svg></span>
              <h3 class="card-title mt-6">{{ $t('tools.band.title') }}</h3>
              <p class="text-desc mt-3 text-muted">{{ $t('tools.band.text') }}</p>
              <ul class="mt-auto space-y-3 pt-8">
                <li v-for="x in tools" :key="x.key">
                  <NuxtLink :to="$localePath(x.to)" class="inline-block border-b-2 border-current pb-1 text-base font-bold text-ink transition hover:text-teal-text">{{ $t(`tools.${x.key}.title`) }}</NuxtLink>
                </li>
              </ul>
            </article>
          </div>
        </div>
      </section>

      <!-- FEATURED OPPORTUNITIES (Falak «استثماراتنا») -->
      <section v-if="featured?.length" class="section-y border-t border-border" aria-labelledby="home-featured">
        <div class="container-x">
          <h2 id="home-featured" class="section-label">{{ $t('home.featuredTitle') }}</h2>
          <p class="section-sub">{{ $t('home.featuredSub') }}</p>
          <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <OpportunityCard v-for="o in featured" :key="o.uuid" :opportunity="o" to="/auth/login" :show-status="false" />
          </div>
          <Disclaimer variant="box" class="mt-6" />
          <div class="mt-14 text-center">
            <NuxtLink :to="$localePath('/auth/login')" class="link-cta">{{ $t('home.featuredCta') }}</NuxtLink>
          </div>
        </div>
      </section>

      <!-- WHY / IMPACT (Falak «لماذا…؟» dark section) -->
      <section class="surface-dark relative overflow-hidden" aria-labelledby="home-impact">
        <NightSky :orbits="false" />
        <div class="container-x relative grid items-center gap-12 py-20 md:py-[7.5rem] lg:grid-cols-2 lg:gap-20">
          <div>
            <h2 id="home-impact" class="section-title text-white">{{ text('impact_title', 'home.impactTitle') }}</h2>
            <p class="text-desc mt-6 text-white/75">{{ text('impact_text', 'home.impactText') }}</p>
          </div>
          <ul class="space-y-7">
            <li v-for="p in impactPoints" :key="p" class="grid grid-cols-1 items-center gap-3 sm:grid-cols-[minmax(0,15rem)_1fr] sm:gap-8">
              <span class="text-[0.9375rem] font-bold leading-[1.4rem] text-white">{{ p }}</span>
              <span class="h-2 rounded-full bg-gradient-to-r from-teal-500 to-navy-600 rtl:bg-gradient-to-l" aria-hidden="true" />
            </li>
          </ul>
        </div>
      </section>

      <!-- HOW IT WORKS + MEMBERSHIP BENEFITS -->
      <section class="section-y" aria-labelledby="home-how">
        <div class="container-x">
          <h2 id="home-how" class="section-label">{{ text('how_title', 'home.howTitle') }}</h2>
          <p class="section-title mt-2 max-w-4xl">{{ text('how_sub', 'home.howSub') }}</p>
          <ol class="relative mt-14 grid gap-10 sm:grid-cols-2 lg:grid-cols-5 lg:gap-6">
            <li class="absolute inset-x-0 top-[1.375rem] hidden h-px bg-border lg:block" aria-hidden="true" />
            <li v-for="(s, i) in steps" :key="i" class="relative">
              <span class="num relative inline-flex h-11 w-11 items-center justify-center rounded-full border border-teal bg-surface text-lg text-teal-text" aria-hidden="true">{{ String(i + 1).padStart(2, '0') }}</span>
              <h3 class="mt-5 text-lg font-bold text-ink">{{ s.title }}</h3>
              <p class="mt-2 text-sm text-muted">{{ s.text }}</p>
            </li>
          </ol>

          <div class="mt-20 grid gap-10 border-t border-border pt-14 lg:grid-cols-[1fr_2fr]">
            <div>
              <h3 class="section-label">{{ text('benefits_title', 'home.benefitsTitle') }}</h3>
              <p class="section-sub">{{ text('benefits_sub', 'home.benefitsSub') }}</p>
            </div>
            <ul class="grid gap-x-10 gap-y-5 sm:grid-cols-2">
              <li v-for="b in benefits" :key="b" class="flex items-start gap-3">
                <svg class="mt-1.5 h-4 w-4 shrink-0 text-teal-text" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
                <span class="text-desc text-text">{{ b }}</span>
              </li>
            </ul>
          </div>
        </div>
      </section>

      <!-- PARTNERS (CMS: published + approved names only — never anonymous logos) -->
      <section v-if="partners.length" class="section-y border-t border-border" aria-labelledby="home-partners">
        <div class="container-x">
          <h2 id="home-partners" class="section-title">{{ $t('partners.title') }}</h2>
          <p class="section-sub">{{ $t('partners.sub') }}</p>
          <PartnerLogos :partners="partners" class="mt-12" />
        </div>
      </section>

      <!-- IMPACT STORY (Falak «Success Stories») -->
      <section v-if="story" class="section-y border-t border-border" aria-labelledby="home-stories">
        <div class="container-x">
          <h2 id="home-stories" class="section-label">{{ $t('stories.title') }}</h2>
          <article class="mt-12 grid items-center gap-10 lg:grid-cols-2 lg:gap-12">
            <img v-if="story.meta?.cover?.url" :src="story.meta.cover.url" :alt="contentAlt(story.meta.cover, contentTitle(story))" :width="story.meta.cover.width ?? 1200" :height="story.meta.cover.height ?? 675" loading="lazy" decoding="async" class="aspect-[4/3] w-full rounded-md object-cover">
            <div v-else class="relative aspect-[4/3] w-full overflow-hidden rounded-md surface-dark" aria-hidden="true">
              <NightSky />
              <img src="/brand/mark-white.svg" alt="" width="100" height="136" class="absolute inset-0 m-auto h-2/5 w-auto opacity-30">
            </div>
            <div>
              <p v-if="story.sector" class="text-sm text-teal-text">{{ story.sector }}</p>
              <h3 class="section-title mt-2">{{ contentTitle(story) }}</h3>
              <p v-if="storyExcerpt" class="text-desc mt-4 text-muted">{{ storyExcerpt }}</p>
              <dl v-if="story.beneficiaries != null || story.impact_metric" class="mt-6 flex flex-wrap gap-x-10 gap-y-4">
                <div v-if="story.beneficiaries != null">
                  <dt class="text-sm text-muted">{{ $t('stories.beneficiaries') }}</dt>
                  <dd class="num mt-1 text-3xl text-navy">{{ number(story.beneficiaries, { maximumFractionDigits: 0 }) }}</dd>
                </div>
                <div v-if="story.impact_metric">
                  <dt class="text-sm text-muted">{{ $t('stories.impact') }}</dt>
                  <dd class="mt-1 text-base font-bold text-ink">{{ story.impact_metric }}</dd>
                </div>
              </dl>
              <NuxtLink :to="$localePath(`/stories#${story.slug}`)" class="link-more mt-8">
                {{ $t('common.readMore') }}<span class="sr-only"> — {{ contentTitle(story) }}</span>
                <svg class="h-5 w-5 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
              </NuxtLink>
            </div>
          </article>
          <div class="mt-16 text-center">
            <NuxtLink :to="$localePath('/stories')" class="link-cta">{{ $t('stories.viewAll') }}</NuxtLink>
          </div>
        </div>
      </section>

      <!-- NEWS -->
      <section v-if="news?.length" class="section-y border-t border-border" aria-labelledby="home-news">
        <div class="container-x">
          <h2 id="home-news" class="section-label">{{ $t('home.latestTitle') }}</h2>
          <div class="mt-12 grid md:grid-cols-3">
            <article v-for="(n, i) in news" :key="n.id" class="py-8 md:px-8 md:py-0" :class="i > 0 ? 'border-t border-border md:border-s md:border-t-0' : 'md:ps-0'">
              <p class="num text-sm text-teal-text">{{ date(n.published_at) }}</p>
              <h3 class="card-title mt-3 leading-8"><NuxtLink :to="$localePath(`/news/${n.slug}`)" class="transition hover:text-teal-text">{{ n.title ?? n.title_ar }}</NuxtLink></h3>
              <p class="text-desc mt-3 line-clamp-3 text-muted">{{ cmsExcerpt(n.body ?? n.body_ar ?? '', 180) }}</p>
            </article>
          </div>
          <div class="mt-16 text-center">
            <NuxtLink :to="$localePath('/news')" class="link-cta">{{ $t('home.allNews') }}</NuxtLink>
          </div>
        </div>
      </section>

      <!-- CTA (Falak download-section) -->
      <section class="surface-dark relative overflow-hidden" aria-labelledby="home-cta">
        <NightSky />
        <div class="container-x relative py-24 text-center md:py-[7.5rem]">
          <h2 id="home-cta" class="display text-white">{{ text('cta_band_title', 'home.ctaBandTitle') }}</h2>
          <p class="lead mx-auto mt-6 max-w-3xl text-white/75">{{ text('cta_band_text', 'home.ctaBandText') }}</p>
          <div class="mt-10 flex justify-center">
            <AppButton variant="line" size="lg" class="min-w-[16rem]" aria-haspopup="dialog" @click="join.open($event)">{{ $t('nav.joinCouncil') }}</AppButton>
          </div>
        </div>
      </section>
  </div>
</template>

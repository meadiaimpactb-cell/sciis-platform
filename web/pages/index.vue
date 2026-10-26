<script setup lang="ts">
/**
 * Home — design "SCII Homepage v3": the meeting point of the two audiences (investors · the emblem · project owners),
 * the headline, the nine-stage strip; below the first screen (HomeBelowFold): what impact means, the two paths,
 * how the platform works, membership benefits and the call to join, the latest news.
 */
import type { ContentItem } from '~/types/api'

definePageMeta({ layout: 'default' })
const { t } = useI18n()
useSeo({
  title: t('home.seoTitle'),
  description: t('home.seoDescription'),
  jsonLd: { '@type': 'WebPage', name: t('home.seoTitle'), description: t('home.seoDescription'), isPartOf: { '@id': `${useSiteUrl()}/#website` }, about: { '@id': `${useSiteUrl()}/#organization` } },
})
const api = useApi()
// Copy from the CMS (home → meta.sections, editable in admin → content) with the landing.* texts as the fallback.
const { text, list, items } = await usePageContent('home')
const emblem = { dark: heroEmblemSrc('dark'), light: heroEmblemSrc('light') }
const { data: latestNews } = await useAsyncData('home-news', () => api.get<{ data: ContentItem[] }>('/public/news').then((r) => r.data[0] ?? null).catch(() => null))
</script>

<template>
  <div class="site-surface">
    <section class="hero" aria-labelledby="home-title">
      <div class="hero__stars site-stars" aria-hidden="true" />
      <div class="site-wrap hero__inner">
        <p class="hero__eyebrow"><span class="hero__eyebrow-dot" aria-hidden="true" />{{ text('hero_badge', 'landing.hero.eyebrow') }}</p>

        <div class="hero__meet">
          <div class="hero__side hero__side--investors">
            <span class="site-label">{{ text('investors_label', 'landing.hero.aLabel') }}</span>
            <h2 class="hero__side-title">{{ text('investors_title', 'landing.hero.aTitle') }}</h2>
            <p class="hero__side-text">{{ text('investors_text', 'landing.hero.aText') }}</p>
            <NuxtLink :to="$localePath('/for-investors')" class="site-btn site-btn--primary site-btn--lg site-btn--glow kbd-focus">{{ text('investors_cta', 'landing.hero.aCta') }}</NuxtLink>
          </div>

          <div class="hero__emblem" aria-hidden="true">
            <svg class="hero__orbits" viewBox="0 0 1200 1200">
              <defs>
                <radialGradient id="home-halo" cx="50%" cy="50%" r="50%">
                  <stop offset="0" stop-color="#58B0A8" stop-opacity=".5" />
                  <stop offset="1" stop-color="#58B0A8" stop-opacity="0" />
                </radialGradient>
              </defs>
              <circle cx="600" cy="600" r="300" fill="url(#home-halo)" />
              <circle cx="600" cy="600" r="250" fill="none" stroke="rgba(var(--fg-rgb), .14)" stroke-width="1" />
              <circle cx="600" cy="600" r="560" fill="none" stroke="rgba(var(--fg-rgb), .06)" stroke-width="1" />
              <path d="M504 371A250 250 0 0 1 696 371" fill="none" stroke="var(--accent-hi)" stroke-width="3" stroke-linecap="round" opacity=".85" />
              <path d="M696 829A250 250 0 0 1 504 829" fill="none" stroke="var(--fg)" stroke-width="2" stroke-linecap="round" opacity=".5" />
              <circle cx="696" cy="371" r="6" fill="var(--accent-hi)" />
              <circle cx="504" cy="829" r="5" fill="var(--fg)" />
            </svg>
            <img class="hero__mark on-dark" :src="emblem.dark" alt="" width="300" height="408" fetchpriority="high" decoding="async">
            <img class="hero__mark on-light" :src="emblem.light" alt="" width="300" height="408" loading="lazy" decoding="async">
            <span class="hero__pulse" />
          </div>

          <div class="hero__side hero__side--projects">
            <span class="site-label hero__label-muted">{{ text('projects_label', 'landing.hero.bLabel') }}</span>
            <h2 class="hero__side-title">{{ text('projects_title', 'landing.hero.bTitle') }}</h2>
            <p class="hero__side-text">{{ text('projects_text', 'landing.hero.bText') }}</p>
            <NuxtLink :to="$localePath('/for-entrepreneurs')" class="site-btn site-btn--secondary site-btn--lg kbd-focus">{{ text('projects_cta', 'landing.hero.bCta') }}</NuxtLink>
          </div>
        </div>

        <div class="hero__headline">
          <h1 id="home-title" class="hero__title">{{ text('headline', 'landing.hero.title') }}</h1>
          <p class="hero__lead">{{ text('headline_lead', 'landing.hero.lead') }}</p>
          <p class="hero__disclaimer">{{ $t('landing.hero.disclaimer') }}</p>
        </div>
      </div>
      <StageMarquee :label="text('stages_label', 'landing.stages.label')" :items="list('stages_list', 'landing.stages.items')" />
    </section>

    <!-- Everything below the first screen hydrates when it scrolls into view (server-rendered for SEO). -->
    <LazyHomeBelowFold hydrate-on-visible :content="{ text, list, items }" :news="latestNews" />
  </div>
</template>

<style scoped>
.hero {
  position: relative;
  overflow: hidden;
  background: radial-gradient(1100px 760px at 50% 48%, var(--glow) 0%, var(--bg1) 50%, var(--bg0) 100%);
}
.hero__stars {
  background-image:
    radial-gradient(1.5px 1.5px at 10% 22%, rgba(var(--fg-rgb), 0.9), transparent 60%),
    radial-gradient(1px 1px at 20% 68%, rgba(var(--fg-rgb), 0.7), transparent 60%),
    radial-gradient(1.5px 1.5px at 31% 14%, rgba(var(--fg-rgb), 0.8), transparent 60%),
    radial-gradient(1px 1px at 44% 86%, rgba(var(--fg-rgb), 0.7), transparent 60%),
    radial-gradient(1.5px 1.5px at 57% 10%, rgba(var(--fg-rgb), 0.75), transparent 60%),
    radial-gradient(1px 1px at 69% 78%, rgba(var(--fg-rgb), 0.8), transparent 60%),
    radial-gradient(2px 2px at 81% 30%, rgba(var(--accent-rgb), 0.9), transparent 60%),
    radial-gradient(1px 1px at 90% 60%, rgba(var(--fg-rgb), 0.8), transparent 60%),
    radial-gradient(1.5px 1.5px at 6% 88%, rgba(var(--fg-rgb), 0.6), transparent 60%),
    radial-gradient(2px 2px at 16% 42%, rgba(var(--accent-rgb), 0.7), transparent 60%),
    radial-gradient(1px 1px at 95% 12%, rgba(var(--fg-rgb), 0.7), transparent 60%);
}
.hero__inner {
  position: relative;
  padding-top: 72px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 56px;
}
.hero__eyebrow {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  height: 36px;
  margin: 0;
  padding-inline: 16px;
  border-radius: 999px;
  border: 1px solid rgba(var(--accent-rgb), 0.35);
  background: rgba(88, 176, 168, 0.08);
  font: 500 14px var(--fb);
  color: var(--accent-text);
}
.hero__eyebrow-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--accent-hi); }

/* The meeting point: investors · emblem · project owners (stacks in that order on narrow screens). */
.hero__meet {
  width: 100%;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 236px), 1fr));
  align-items: center;
  gap: 40px 36px;
}
.hero__side {
  display: flex;
  flex-direction: column;
  gap: 16px;
  max-width: 400px;
  position: relative;
  z-index: 1;
}
.hero__side--investors { text-align: end; align-items: flex-end; justify-self: end; }
.hero__side--projects { text-align: start; align-items: flex-start; justify-self: start; }
.hero__label-muted { color: var(--t2); }
.hero__side-title { font: 800 32px/1.4 var(--fd); }
.hero__side-text { margin: 0; font: 400 16px/1.8 var(--fb); color: var(--t2); }
.hero__side .site-btn { margin-top: 6px; }

.hero__emblem {
  position: relative;
  justify-self: center;
  width: 300px;
  height: 408px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.hero__orbits {
  position: absolute;
  left: 50%;
  top: 50%;
  width: 1200px;
  height: 1200px;
  max-width: none;
  transform: translate(-50%, -50%);
  pointer-events: none;
}
.hero__mark {
  position: relative;
  filter: drop-shadow(0 30px 60px rgba(var(--shadow-rgb), 0.6));
}
.hero__pulse {
  position: absolute;
  left: 50%;
  top: 50%;
  width: 10px;
  height: 10px;
  margin: -5px 0 0 -5px;
  border-radius: 50%;
  background: var(--accent-hi);
  animation: hero-pulse 2.6s ease-out infinite;
}
@keyframes hero-pulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(var(--accent-rgb), 0.6); }
  70% { box-shadow: 0 0 0 22px rgba(var(--accent-rgb), 0); }
}

.hero__headline {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 18px;
  padding-bottom: 72px;
}
.hero__title { max-width: 980px; font: 800 clamp(34px, 5.2vw, 54px)/1.35 var(--fd); text-wrap: balance; }
.hero__lead { max-width: 720px; margin: 0; font: 400 18px/1.8 var(--fb); color: var(--t2); }
.hero__disclaimer { margin: 6px 0 0; font: 400 13px/1.6 var(--fb); color: var(--t3); }

/* Stacked (one column): centre both texts around the emblem. */
@media (max-width: 860px) {
  .hero__side--investors,
  .hero__side--projects { text-align: center; align-items: center; justify-self: center; }
}
@media (max-width: 640px) {
  .hero__inner { padding-top: 48px; gap: 40px; }
  .hero__side-title { font-size: 26px; }
  .hero__emblem { width: 220px; height: 300px; }
  .hero__mark { width: 220px; height: 300px; }
  /* the orbits scale with the emblem (300 → 220 px) so the arcs stay clear of the texts above and below */
  .hero__orbits { width: 880px; height: 880px; }
  .hero__lead { font-size: 16px; }
}
@media (prefers-reduced-motion: reduce) {
  .hero__pulse { animation: none; }
}
</style>

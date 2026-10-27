<script setup lang="ts">
import type { PublicPage } from '~/types/api'
definePageMeta({ layout: 'default' })
const { t } = useI18n()
// CMS copy (`about` page → meta.sections) with the i18n texts as fallback.
const { text, list } = await usePageContent('about')
const goals = computed(() => list('goals', 'about.goalsList'))
useSeo({
  title: t('about.metaTitle'),
  description: text('lead', 'about.lead'),
  jsonLd: { '@type': 'AboutPage', name: text('title', 'about.title'), description: text('lead', 'about.lead'), about: { '@id': `${useSiteUrl()}/#organization` } },
})
const bodies = ['assembly', 'board', 'membership', 'evaluation', 'matching', 'impact', 'advisory', 'secretariat'] as const

// Optional CMS override: a published `governance` page whose meta.bodies lists {name_ar, name_en, level} (level 1 = top).
const api = useApi()
const { locale } = useI18n()
const { data: governance } = await useAsyncData(`governance-page-${locale.value}`, () => api.get<{ data: PublicPage }>('/public/pages/governance').then((r): Partial<PublicPage> => r.data).catch((): Partial<PublicPage> => ({})))
const cmsBodies = computed(() => {
  const list = governance.value?.meta?.bodies
  if (!Array.isArray(list) || !list.length) return null
  const named = list.filter((b) => b && typeof b.name_ar === 'string').map((b, i) => ({ name: (locale.value === 'en' && b.name_en) || b.name_ar, level: Number(b.level ?? i + 1) || i + 1 }))
  const levels = [...new Set(named.map((b) => b.level))].sort((a, b) => a - b)
  return levels.map((lv) => named.filter((b) => b.level === lv).map((b) => b.name))
})
const cmsGovernanceText = computed(() => cmsExcerpt(governance.value?.body ?? governance.value?.body_ar, 400))
</script>

<template>
  <div>
    <PageHero :title="text('title', 'about.title')" :lead="text('lead', 'about.lead')" />
    <section class="section-y">
      <div class="container-x grid gap-5 lg:grid-cols-3">
        <GlassCard as="article">
          <span class="flex h-11 w-11 items-center justify-center rounded-full border border-accent/35 bg-accent/10 text-accent-text" aria-hidden="true"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z" /><circle cx="12" cy="12" r="3" /></svg></span>
          <h2 class="card-title mt-5">{{ $t('about.vision') }}</h2>
          <p class="text-desc mt-3 text-t2">{{ text('vision_text', 'about.visionText') }}</p>
        </GlassCard>
        <GlassCard as="article">
          <span class="flex h-11 w-11 items-center justify-center rounded-full border border-accent/35 bg-accent/10 text-accent-text" aria-hidden="true"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9" /><circle cx="12" cy="12" r="5" /><circle cx="12" cy="12" r="1" /></svg></span>
          <h2 class="card-title mt-5">{{ $t('about.mission') }}</h2>
          <p class="text-desc mt-3 text-t2">{{ text('mission_text', 'about.missionText') }}</p>
        </GlassCard>
        <GlassCard as="article">
          <span class="flex h-11 w-11 items-center justify-center rounded-full border border-accent/35 bg-accent/10 text-accent-text" aria-hidden="true"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 21V4M5 4h11l-2 4 2 4H5" /></svg></span>
          <h2 class="card-title mt-5">{{ $t('about.goals') }}</h2>
          <ol class="mt-4 space-y-3">
            <li v-for="(g, i) in goals" :key="i" class="flex gap-3"><span class="num mt-1 flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-accent/35 bg-accent/10 text-xs font-bold text-accent-text">{{ i + 1 }}</span><span class="text-desc text-ink">{{ g }}</span></li>
          </ol>
        </GlassCard>
      </div>
    </section>
    <section class="section-y border-y border-border bg-surface">
      <div class="container-x">
        <h2 class="section-title">{{ $t('about.governance') }}</h2>
        <p class="section-sub max-w-3xl">{{ cmsBodies && cmsGovernanceText ? cmsGovernanceText : text('governance_text', 'about.governanceText') }}</p>
        <!-- CMS-driven diagram (published `governance` page → meta.bodies) -->
        <div v-if="cmsBodies" class="mt-10 rounded-2xl border border-border bg-bg p-6 md:p-10" role="img" :aria-label="$t('about.governance')">
          <div class="flex flex-col items-center gap-3">
            <template v-for="(tier, ti) in cmsBodies" :key="ti">
              <div v-if="ti > 0" class="h-6 w-px bg-ink/20" aria-hidden="true" />
              <div v-if="tier.length === 1" class="rounded-full px-5 py-2.5 text-sm font-semibold" :class="ti === 0 ? 'bg-accent text-on-accent' : ti === cmsBodies.length - 1 ? 'border border-border bg-navy-100 text-navy' : 'border border-accent/40 bg-accent/10 text-accent-text'">{{ tier[0] }}</div>
              <div v-else class="grid w-full max-w-4xl grid-cols-2 gap-2.5 sm:grid-cols-5">
                <div v-for="b in tier" :key="b" class="rounded-xl border border-border bg-surface px-3 py-2.5 text-center text-sm text-ink">{{ b }}</div>
              </div>
            </template>
          </div>
        </div>
        <div v-else class="mt-10 rounded-2xl border border-dashed border-ink/20 bg-bg p-6 md:p-10" role="img" :aria-label="$t('about.governancePlaceholder')">
          <p class="mb-8 text-center text-xs text-t3">{{ $t('about.governancePlaceholder') }} — {{ $t('app.pendingLegal') }}</p>
          <div class="flex flex-col items-center gap-3">
            <div class="rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-on-accent">{{ $t(`about.governanceBodies.assembly`) }}</div>
            <div class="h-6 w-px bg-ink/20" aria-hidden="true" />
            <div class="rounded-full border border-accent/40 bg-accent/10 px-5 py-2.5 text-sm font-semibold text-accent-text">{{ $t(`about.governanceBodies.board`) }}</div>
            <div class="h-6 w-px bg-ink/20" aria-hidden="true" />
            <div class="grid w-full max-w-4xl grid-cols-2 gap-2.5 sm:grid-cols-5">
              <div v-for="b in bodies.slice(2, 7)" :key="b" class="rounded-xl border border-border bg-surface px-3 py-2.5 text-center text-sm text-ink">{{ $t(`about.governanceBodies.${b}`) }}</div>
            </div>
            <div class="h-6 w-px bg-ink/20" aria-hidden="true" />
            <div class="rounded-full border border-border bg-navy-100 px-5 py-2.5 text-sm font-semibold text-navy">{{ $t(`about.governanceBodies.secretariat`) }}</div>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

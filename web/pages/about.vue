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
  <div class="bg-surface">
    <PageHero :title="text('title', 'about.title')" :lead="text('lead', 'about.lead')" />
    <section class="section-y">
      <div class="container-x grid border-b border-border lg:grid-cols-3">
        <article class="py-10 lg:pe-8 lg:pt-0"><h2 class="card-title">{{ $t('about.vision') }}</h2><p class="text-desc mt-4 text-muted">{{ text('vision_text', 'about.visionText') }}</p></article>
        <article class="border-t border-border py-10 lg:border-s lg:border-t-0 lg:px-8 lg:pt-0"><h2 class="card-title">{{ $t('about.mission') }}</h2><p class="text-desc mt-4 text-muted">{{ text('mission_text', 'about.missionText') }}</p></article>
        <article class="border-t border-border py-10 lg:border-s lg:border-t-0 lg:ps-8 lg:pt-0">
          <h2 class="card-title">{{ $t('about.goals') }}</h2>
          <ol class="mt-4 space-y-3">
            <li v-for="(g, i) in goals" :key="i" class="flex gap-3"><span class="num mt-1 h-6 w-6 rounded-md border border-teal/50 text-teal-text text-xs flex items-center justify-center shrink-0">{{ i + 1 }}</span><span class="text-desc">{{ g }}</span></li>
          </ol>
        </article>
      </div>
    </section>
    <section class="pb-20 md:pb-[6.25rem]">
      <div class="container-x">
        <h2 class="section-label">{{ $t('about.governance') }}</h2>
        <p class="section-sub max-w-3xl">{{ cmsBodies && cmsGovernanceText ? cmsGovernanceText : text('governance_text', 'about.governanceText') }}</p>
        <!-- CMS-driven diagram (published `governance` page → meta.bodies) -->
        <div v-if="cmsBodies" class="mt-6 rounded-xl border border-border bg-bg p-6" role="img" :aria-label="$t('about.governance')">
          <div class="flex flex-col items-center gap-3">
            <template v-for="(tier, ti) in cmsBodies" :key="ti">
              <div v-if="ti > 0" class="h-5 w-px bg-border" aria-hidden="true" />
              <div v-if="tier.length === 1" class="rounded-lg px-4 py-2 text-sm" :class="ti === 0 ? 'bg-ink text-white' : ti === cmsBodies.length - 1 ? 'bg-blue-soft text-blue' : 'bg-navy text-white'">{{ tier[0] }}</div>
              <div v-else class="grid grid-cols-2 sm:grid-cols-5 gap-2 w-full max-w-3xl">
                <div v-for="b in tier" :key="b" class="rounded-lg bg-surface border border-border px-3 py-2 text-xs text-center">{{ b }}</div>
              </div>
            </template>
          </div>
        </div>
        <div v-else class="mt-6 rounded-xl border border-dashed border-border bg-bg p-6" role="img" :aria-label="$t('about.governancePlaceholder')">
          <p class="text-xs text-muted text-center mb-5">{{ $t('about.governancePlaceholder') }} — {{ $t('app.pendingLegal') }}</p>
          <div class="flex flex-col items-center gap-3">
            <div class="rounded-lg bg-ink text-white px-4 py-2 text-sm">{{ $t(`about.governanceBodies.assembly`) }}</div>
            <div class="h-5 w-px bg-border" aria-hidden="true" />
            <div class="rounded-lg bg-navy text-white px-4 py-2 text-sm">{{ $t(`about.governanceBodies.board`) }}</div>
            <div class="h-5 w-px bg-border" aria-hidden="true" />
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 w-full max-w-3xl">
              <div v-for="b in bodies.slice(2, 7)" :key="b" class="rounded-lg bg-surface border border-border px-3 py-2 text-xs text-center">{{ $t(`about.governanceBodies.${b}`) }}</div>
            </div>
            <div class="h-5 w-px bg-border" aria-hidden="true" />
            <div class="rounded-lg bg-blue-soft text-blue px-4 py-2 text-sm">{{ $t(`about.governanceBodies.secretariat`) }}</div>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

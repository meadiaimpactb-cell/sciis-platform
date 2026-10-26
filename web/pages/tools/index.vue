<script setup lang="ts">
/** Tools hub — the two self-assessment tools (+ knowledge resources when published). */
import { investorReadiness, projectReadiness } from '~/utils/assessments'

definePageMeta({ layout: 'default' })
const { t } = useI18n()
const localePath = useLocalePath()
const siteUrl = useSiteUrl()
const { data: resources } = await usePublicResources()

const tools = [
  { key: 'investor', to: '/tools/investor-readiness', n: investorReadiness.questions.length, min: 3, audience: 'forInvestors', accent: 'bg-navy', tint: 'bg-navy-100 text-navy', icon: 'M4 20V10M10 20V4M16 20v-6M22 20H2' },
  { key: 'project', to: '/tools/project-readiness', n: projectReadiness.questions.length, min: 4, audience: 'forEntrepreneurs', accent: 'bg-teal', tint: 'bg-teal-soft text-teal-text', icon: 'M12 2a7 7 0 017 7c0 3-2 5-3 6v3H8v-3C7 14 5 12 5 9a7 7 0 017-7zM9 22h6' },
] as const

useSeo({
  title: t('tools.metaTitle'),
  description: t('tools.lead'),
  jsonLd: {
    '@type': 'CollectionPage',
    name: t('tools.title'),
    description: t('tools.lead'),
    isPartOf: { '@id': `${siteUrl}/#website` },
    mainEntity: {
      '@type': 'ItemList',
      itemListElement: tools.map((x, i) => ({ '@type': 'ListItem', position: i + 1, name: t(`tools.${x.key}.title`), url: `${siteUrl}${localePath(x.to)}` })),
    },
  },
})
</script>

<template>
  <div>
    <PageHero :kicker="$t('tools.eyebrow')" :title="$t('tools.title')" :lead="$t('tools.lead')" />

    <section class="container-x section-y">
      <ul class="grid md:grid-cols-2 gap-6" role="list">
        <li v-for="x in tools" :key="x.key" class="card card-hover card-pad relative overflow-hidden flex flex-col">
          <span class="absolute inset-x-0 top-0 h-1" :class="x.accent" aria-hidden="true" />
          <span class="h-12 w-12 rounded-xl flex items-center justify-center" :class="x.tint" aria-hidden="true"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="x.icon" /></svg></span>
          <p class="mt-5 text-xs font-medium text-teal-text">{{ $t(`nav.${x.audience}`) }}</p>
          <h2 class="card-title mt-2">{{ $t(`tools.${x.key}.title`) }}</h2>
          <p class="text-desc mt-3 text-muted flex-1">{{ $t(`tools.${x.key}.lead`) }}</p>
          <p class="mt-4 text-xs text-muted num">{{ $t(x.n <= 10 ? 'tools.questionsFew' : 'tools.questionsCount', { n: x.n }) }} · {{ $t('tools.minutes', { n: x.min }) }} · {{ $t('tools.noSignup') }} · {{ $t('tools.private') }}</p>
          <AppButton :to="x.to" class="mt-6 w-fit">{{ $t('tools.start') }}</AppButton>
        </li>
      </ul>

      <div v-if="resources.length" class="mt-10 card card-pad flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h2 class="card-title">{{ $t('resources.title') }}</h2>
          <p class="mt-1 text-sm text-muted">{{ $t('resources.lead') }}</p>
        </div>
        <AppButton to="/resources" variant="outline">{{ $t('common.viewAll') }}</AppButton>
      </div>
      <p class="mt-8 text-xs text-muted leading-6 max-w-3xl">{{ $t('tools.result.disclaimer') }}</p>
    </section>
  </div>
</template>

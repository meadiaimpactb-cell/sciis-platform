<script setup lang="ts">
definePageMeta({ layout: 'default' })
const { t } = useI18n()
// CMS copy (`for-investors` page → meta.sections; membership benefits come from the `home` page) with i18n fallback.
const { text, list, items } = await usePageContent('for-investors')
const home = await usePageContent('home')
const lead = text('lead', 'forInvestors.lead')
useSeo({ title: t('forInvestors.metaTitle'), description: lead, jsonLd: { '@type': 'WebPage', name: text('title', 'forInvestors.title'), description: lead, isPartOf: { '@id': `${useSiteUrl()}/#website` } } })
const why = computed(() => items<{ title?: string; text?: string }>('why', 'forInvestors.why'))
const journey = computed(() => items<{ title?: string; text?: string }>('journey', 'forInvestors.journey'))
const requirements = computed(() => list('requirements', 'forInvestors.requirements'))
const benefits = computed(() => home.list('benefits', 'home.benefits'))
</script>

<template>
  <div>
    <PageHero :title="text('title', 'forInvestors.title')" :lead="text('lead', 'forInvestors.lead')">
      <AppButton to="/auth/register?type=investor_individual" variant="accent" size="lg">{{ $t('forInvestors.cta') }}</AppButton>
      <template #bottom><Disclaimer variant="dark" /></template>
    </PageHero>
    <section class="section-y">
      <div class="container-x">
        <h2 class="section-title">{{ $t('forInvestors.whyTitle') }}</h2>
        <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
          <GlassCard v-for="(w, i) in why" :key="i" as="article">
            <span class="num font-display text-sm font-extrabold text-accent-text" aria-hidden="true">{{ String(i + 1).padStart(2, '0') }}</span>
            <h3 class="card-title mt-3">{{ w.title }}</h3><p class="text-desc mt-3 text-t2">{{ w.text }}</p>
          </GlassCard>
        </div>
      </div>
    </section>
    <ToolsBand focus="investor" class="pb-20 md:pb-[6.25rem]" />
    <section class="surface-hero">
      <OrbitBackdrop :orbits="false" />
      <div class="container-x relative section-y grid gap-14 lg:grid-cols-2 lg:gap-20">
        <div>
          <h2 class="section-title">{{ $t('forInvestors.journeyTitle') }}</h2>
          <ol class="mt-10 space-y-7">
            <li v-for="(j, i) in journey" :key="i" class="flex gap-5"><span class="num flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-accent-hi bg-accent/10 font-display text-lg font-bold text-accent-text">{{ i + 1 }}</span><div><h3 class="text-lg font-bold text-ink">{{ j.title }}</h3><p class="mt-1 text-sm leading-6 text-t2">{{ j.text }}</p></div></li>
          </ol>
        </div>
        <div>
          <h2 class="section-title">{{ $t('forInvestors.requirementsTitle') }}</h2>
          <ul class="mt-10 divide-y divide-ink/10 border-y border-ink/10"><li v-for="(r, i) in requirements" :key="i" class="flex gap-3 py-4 text-base text-ink"><svg class="mt-1 h-5 w-5 shrink-0" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke="var(--accent-hi)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>{{ r }}</li></ul>
        </div>
      </div>
    </section>
    <section class="section-y">
      <div class="container-x">
        <h2 class="section-title">{{ home.text('benefits_title', 'home.benefitsTitle') }}</h2>
        <ul class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-4"><li v-for="(b, i) in benefits" :key="i" class="flex items-start gap-3.5 rounded-[14px] border border-ink/10 bg-ink/5 px-5 py-5 text-sm font-medium leading-7 text-ink"><svg class="mt-1 shrink-0" width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke="var(--accent-hi)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>{{ b }}</li></ul>
        <div class="mt-14 text-center"><NuxtLink :to="$localePath('/auth/register?type=investor_individual')" class="link-cta">{{ $t('forInvestors.cta') }}</NuxtLink></div>
      </div>
    </section>
  </div>
</template>

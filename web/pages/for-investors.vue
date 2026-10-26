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
  <div class="bg-surface">
    <PageHero :title="text('title', 'forInvestors.title')" :lead="text('lead', 'forInvestors.lead')">
      <AppButton to="/auth/register?type=investor_individual" variant="accent" size="lg">{{ $t('forInvestors.cta') }}</AppButton>
      <template #bottom><Disclaimer variant="dark" /></template>
    </PageHero>
    <section class="section-y">
      <div class="container-x">
        <h2 class="section-title">{{ $t('forInvestors.whyTitle') }}</h2>
        <div class="mt-12 grid border-b border-border sm:grid-cols-2 lg:grid-cols-4">
          <article v-for="(w, i) in why" :key="i" class="border-t border-border py-8 sm:px-6 lg:border-t-0 lg:py-2" :class="i % 4 === 0 ? 'lg:ps-0' : 'lg:border-s'"><h3 class="card-title">{{ w.title }}</h3><p class="text-desc mt-3 text-muted">{{ w.text }}</p></article>
        </div>
      </div>
    </section>
    <ToolsBand focus="investor" class="pb-20 md:pb-[6.25rem]" />
    <section class="surface-dark relative overflow-hidden">
      <NightSky :orbits="false" />
      <div class="container-x relative section-y grid gap-14 lg:grid-cols-2 lg:gap-20">
        <div>
          <h2 class="section-title text-white">{{ $t('forInvestors.journeyTitle') }}</h2>
          <ol class="mt-10 space-y-7">
            <li v-for="(j, i) in journey" :key="i" class="flex gap-5"><span class="num h-11 w-11 rounded-full border border-teal-400 text-teal-400 text-lg flex items-center justify-center shrink-0">{{ i + 1 }}</span><div><h3 class="text-lg font-bold text-white">{{ j.title }}</h3><p class="mt-1 text-sm text-white/65">{{ j.text }}</p></div></li>
          </ol>
        </div>
        <div>
          <h2 class="section-title text-white">{{ $t('forInvestors.requirementsTitle') }}</h2>
          <ul class="mt-10 divide-y divide-white/10 border-y border-white/10"><li v-for="(r, i) in requirements" :key="i" class="flex gap-3 py-4 text-base text-white/85"><svg class="mt-1 h-5 w-5 text-teal-400 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>{{ r }}</li></ul>
        </div>
      </div>
    </section>
    <section class="section-y">
      <div class="container-x">
        <h2 class="section-title">{{ home.text('benefits_title', 'home.benefitsTitle') }}</h2>
        <ul class="mt-12 grid gap-x-10 gap-y-5 sm:grid-cols-2 lg:grid-cols-4"><li v-for="(b, i) in benefits" :key="i" class="text-desc border-t-2 border-teal pt-4">{{ b }}</li></ul>
        <div class="mt-14 text-center"><NuxtLink :to="$localePath('/auth/register?type=investor_individual')" class="link-cta">{{ $t('forInvestors.cta') }}</NuxtLink></div>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
import type { PublicFaq } from '~/types/api'
definePageMeta({ layout: 'default' })
const { t } = useI18n()
// CMS copy (`for-entrepreneurs` page → meta.sections) with the i18n texts as fallback.
const { text, list, items } = await usePageContent('for-entrepreneurs')
const lead = text('lead', 'forEntrepreneurs.lead')
useSeo({ title: t('forEntrepreneurs.metaTitle'), description: lead, jsonLd: { '@type': 'WebPage', name: text('title', 'forEntrepreneurs.title'), description: lead, isPartOf: { '@id': `${useSiteUrl()}/#website` } } })
const api = useApi()
const criteria = computed(() => list('criteria', 'forEntrepreneurs.criteria'))
const stages = computed(() => items<{ title?: string; text?: string; sla?: string | null }>('stages', 'forEntrepreneurs.stages').map((x) => ({ title: x.title, text: x.text, sla: x.sla || null })))
const documents = computed(() => items<{ name?: string; required?: boolean }>('documents', 'forEntrepreneurs.documents').map((x) => ({ name: x.name, required: !!x.required })))
const { data: faqs } = await useAsyncData('ent-faqs', () => api.get<{ data: PublicFaq[] }>('/public/faqs').then((r) => r.data.filter((f) => f.category === 'entrepreneur')).catch(() => []))
const openFaq = ref<number | null>(null)
</script>

<template>
  <div class="bg-surface">
    <PageHero :title="text('title', 'forEntrepreneurs.title')" :lead="text('lead', 'forEntrepreneurs.lead')">
      <AppButton to="/auth/register?type=entrepreneur_entity" variant="accent" size="lg">{{ $t('forEntrepreneurs.cta') }}</AppButton>
    </PageHero>
    <section class="section-y">
      <div class="container-x grid gap-12 lg:grid-cols-2 lg:gap-16">
        <article>
          <h2 class="section-label">{{ $t('forEntrepreneurs.criteriaTitle') }}</h2>
          <ul class="mt-6 space-y-4"><li v-for="(c, i) in criteria" :key="i" class="text-desc flex gap-3"><svg class="mt-1.5 h-5 w-5 text-teal-text shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>{{ c }}</li></ul>
        </article>
        <article>
          <h2 class="section-label">{{ $t('forEntrepreneurs.documentsTitle') }}</h2>
          <ul class="mt-6 divide-y divide-border border-y border-border"><li v-for="(d, i) in documents" :key="i" class="flex items-center justify-between gap-3 py-3.5 text-base"><span>{{ d.name }}</span><span class="text-xs rounded-full px-2 py-0.5" :class="d.required ? 'bg-danger/10 text-danger' : 'bg-bg text-muted'">{{ d.required ? $t('common.required') : $t('common.optional') }}</span></li></ul>
        </article>
      </div>
    </section>
    <ToolsBand focus="entrepreneur" class="pb-20 md:pb-[6.25rem]" />
    <section class="surface-dark relative overflow-hidden">
      <NightSky :orbits="false" />
      <div class="container-x relative section-y">
        <h2 class="section-title text-white">{{ $t('forEntrepreneurs.stagesTitle') }}</h2>
        <ol class="mt-12 grid gap-x-8 gap-y-12 sm:grid-cols-2 lg:grid-cols-4">
          <li v-for="(s, i) in stages" :key="i" class="relative">
            <div class="flex items-center justify-between gap-3"><span class="num text-5xl text-white">0{{ i + 1 }}</span><span v-if="s.sla" class="num rounded-full border border-teal-400/60 px-2.5 py-0.5 text-xs text-teal-400">{{ s.sla }}</span></div>
            <h3 class="mt-4 text-lg font-bold text-white">{{ s.title }}</h3><p class="mt-2 text-sm text-white/65">{{ s.text }}</p>
          </li>
        </ol>
      </div>
    </section>
    <section class="section-y">
      <div class="container-x">
        <div class="mb-14"><StatusStrip status="under_review" /></div>
        <div class="mx-auto max-w-3xl">
          <h2 class="section-title">{{ $t('forEntrepreneurs.faqTitle') }}</h2>
          <div class="mt-8 divide-y divide-border border-y border-border">
            <div v-for="f in faqs" :key="f.id">
              <button type="button" class="w-full flex items-center justify-between gap-4 py-5 text-start text-base font-bold text-ink kbd-focus" :aria-expanded="openFaq === f.id" @click="openFaq = openFaq === f.id ? null : f.id">{{ f.question }}<svg class="h-4 w-4 text-muted transition shrink-0" :class="openFaq === f.id && 'rotate-180'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg></button>
              <p v-show="openFaq === f.id" class="text-desc pb-5 text-muted">{{ f.answer }}</p>
            </div>
          </div>
          <div class="mt-14 text-center"><NuxtLink :to="$localePath('/auth/register?type=entrepreneur_entity')" class="link-cta">{{ $t('forEntrepreneurs.cta') }}</NuxtLink></div>
        </div>
      </div>
    </section>
  </div>
</template>

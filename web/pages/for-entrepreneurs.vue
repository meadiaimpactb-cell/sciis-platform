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
  <div>
    <PageHero :title="text('title', 'forEntrepreneurs.title')" :lead="text('lead', 'forEntrepreneurs.lead')">
      <AppButton to="/auth/register?type=entrepreneur_entity" variant="accent" size="lg">{{ $t('forEntrepreneurs.cta') }}</AppButton>
    </PageHero>
    <section class="section-y">
      <div class="container-x grid gap-5 lg:grid-cols-2">
        <GlassCard as="article" pad="lg">
          <h2 class="section-label">{{ $t('forEntrepreneurs.criteriaTitle') }}</h2>
          <ul class="site-checks mt-6"><li v-for="(c, i) in criteria" :key="i"><svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke="var(--accent-hi)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>{{ c }}</li></ul>
        </GlassCard>
        <GlassCard as="article" pad="lg">
          <h2 class="section-label">{{ $t('forEntrepreneurs.documentsTitle') }}</h2>
          <ul class="mt-6 divide-y divide-ink/10 border-y border-ink/10"><li v-for="(d, i) in documents" :key="i" class="flex items-center justify-between gap-3 py-3.5 text-base text-ink"><span>{{ d.name }}</span><span class="shrink-0 rounded-full border px-2.5 py-0.5 text-xs font-medium" :class="d.required ? 'border-danger/30 bg-danger/10 text-danger' : 'border-ink/15 bg-ink/5 text-t3'">{{ d.required ? $t('common.required') : $t('common.optional') }}</span></li></ul>
        </GlassCard>
      </div>
    </section>
    <ToolsBand focus="entrepreneur" class="pb-20 md:pb-[6.25rem]" />
    <section class="surface-hero">
      <OrbitBackdrop :orbits="false" />
      <div class="container-x relative section-y">
        <h2 class="section-title">{{ $t('forEntrepreneurs.stagesTitle') }}</h2>
        <ol class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
          <GlassCard v-for="(s, i) in stages" :key="i" as="li">
            <div class="flex items-center justify-between gap-3"><span class="num font-display text-3xl font-extrabold text-accent-text">0{{ i + 1 }}</span><span v-if="s.sla" class="num rounded-full border border-accent/40 bg-accent/10 px-2.5 py-0.5 text-xs text-accent-text">{{ s.sla }}</span></div>
            <h3 class="mt-5 text-lg font-bold text-ink">{{ s.title }}</h3><p class="mt-2 text-sm leading-6 text-t2">{{ s.text }}</p>
          </GlassCard>
        </ol>
      </div>
    </section>
    <section class="section-y">
      <div class="container-x">
        <div class="card card-pad mb-14"><StatusStrip status="under_review" /></div>
        <div class="mx-auto max-w-3xl">
          <h2 class="section-title">{{ $t('forEntrepreneurs.faqTitle') }}</h2>
          <div v-if="faqs?.length" class="card mt-8 divide-y divide-border">
            <div v-for="f in faqs" :key="f.id">
              <button type="button" class="w-full flex items-center justify-between gap-4 px-5 py-5 text-start text-base font-bold text-ink kbd-focus" :aria-expanded="openFaq === f.id" @click="openFaq = openFaq === f.id ? null : f.id">{{ f.question }}<svg class="h-4 w-4 text-muted transition shrink-0" :class="openFaq === f.id && 'rotate-180'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg></button>
              <p v-show="openFaq === f.id" class="text-desc px-5 pb-5 text-muted">{{ f.answer }}</p>
            </div>
          </div>
          <div class="mt-14 text-center"><NuxtLink :to="$localePath('/auth/register?type=entrepreneur_entity')" class="link-cta">{{ $t('forEntrepreneurs.cta') }}</NuxtLink></div>
        </div>
      </div>
    </section>
  </div>
</template>

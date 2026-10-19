<script setup lang="ts">
import type { PublicFaq } from '~/types/api'
definePageMeta({ layout: 'default' })
const { t, tm, rt } = useI18n()
useHead({ title: t('forEntrepreneurs.metaTitle') })
const api = useApi()
const criteria = computed(() => (tm('forEntrepreneurs.criteria') as string[]).map((x) => rt(x)))
const stages = computed(() => (tm('forEntrepreneurs.stages') as { title: string; text: string; sla?: string }[]).map((x) => ({ title: rt(x.title), text: rt(x.text), sla: x.sla ? rt(x.sla) : null })))
const documents = computed(() => (tm('forEntrepreneurs.documents') as { name: string; required: boolean }[]).map((x) => ({ name: rt(x.name), required: x.required })))
const { data: faqs } = await useAsyncData('ent-faqs', () => api.get<{ data: PublicFaq[] }>('/public/faqs').then((r) => r.data.filter((f) => f.category === 'entrepreneur')).catch(() => []))
const openFaq = ref<number | null>(null)
</script>

<template>
  <div>
    <section class="bg-ink text-white"><div class="container-x py-14 lg:py-20 grid lg:grid-cols-2 gap-10 items-center">
      <div><h1 class="text-3xl sm:text-4xl font-semibold text-white">{{ $t('forEntrepreneurs.title') }}</h1><p class="mt-4 text-white/75 text-lg leading-8">{{ $t('forEntrepreneurs.lead') }}</p><AppButton to="/auth/register?type=entrepreneur_entity" size="lg" class="mt-6">{{ $t('forEntrepreneurs.cta') }}</AppButton></div>
      <PlaceholderImage ratio="aspect-[4/3]" :intent="$t('home.heroImages.1')" :variant="1" />
    </div></section>
    <section class="container-x py-14 grid lg:grid-cols-2 gap-8">
      <article class="card card-pad">
        <h2 class="text-xl font-semibold">{{ $t('forEntrepreneurs.criteriaTitle') }}</h2>
        <ul class="mt-4 space-y-3"><li v-for="c in criteria" :key="c" class="flex gap-3 text-sm leading-6"><svg class="h-5 w-5 text-blue shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>{{ c }}</li></ul>
      </article>
      <article class="card card-pad">
        <h2 class="text-xl font-semibold">{{ $t('forEntrepreneurs.documentsTitle') }}</h2>
        <ul class="mt-4 divide-y divide-border"><li v-for="d in documents" :key="d.name" class="flex items-center justify-between gap-3 py-2.5 text-sm"><span>{{ d.name }}</span><span class="text-xs rounded-full px-2 py-0.5" :class="d.required ? 'bg-danger/10 text-danger' : 'bg-bg text-muted'">{{ d.required ? $t('common.required') : $t('common.optional') }}</span></li></ul>
      </article>
    </section>
    <section class="bg-surface border-y border-border"><div class="container-x py-14">
      <h2 class="section-title">{{ $t('forEntrepreneurs.stagesTitle') }}</h2>
      <ol class="mt-8 grid sm:grid-cols-2 lg:grid-cols-4 gap-5">
        <li v-for="(s, i) in stages" :key="s.title" class="rounded-xl border border-border p-5 bg-bg/40 relative">
          <div class="flex items-center justify-between"><span class="num text-2xl font-semibold text-blue">0{{ i + 1 }}</span><span v-if="s.sla" class="text-xs rounded-full bg-blue-soft text-blue px-2 py-0.5 num">{{ s.sla }}</span></div>
          <h3 class="mt-2 font-semibold">{{ s.title }}</h3><p class="mt-1 text-sm text-muted leading-6">{{ s.text }}</p>
        </li>
      </ol>
      <div class="mt-8"><StatusStrip status="under_review" /></div>
    </div></section>
    <section class="container-x py-14 max-w-3xl">
      <h2 class="section-title">{{ $t('forEntrepreneurs.faqTitle') }}</h2>
      <div class="mt-6 divide-y divide-border card">
        <div v-for="f in faqs" :key="f.id">
          <button type="button" class="w-full flex items-center justify-between gap-4 p-4 text-start font-medium kbd-focus" :aria-expanded="openFaq === f.id" @click="openFaq = openFaq === f.id ? null : f.id">{{ f.question }}<svg class="h-4 w-4 text-muted transition shrink-0" :class="openFaq === f.id && 'rotate-180'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg></button>
          <p v-show="openFaq === f.id" class="px-4 pb-4 text-sm text-muted leading-7">{{ f.answer }}</p>
        </div>
      </div>
      <AppButton to="/auth/register?type=entrepreneur_entity" size="lg" class="mt-8">{{ $t('forEntrepreneurs.cta') }}</AppButton>
    </section>
  </div>
</template>

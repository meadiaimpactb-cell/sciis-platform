<script setup lang="ts">
import type { PublicFaq } from '~/types/api'
definePageMeta({ layout: 'default' })
const { t } = useI18n()
const api = useApi()
const { data: faqs } = await useAsyncData('faqs', () => api.get<{ data: PublicFaq[] }>('/public/faqs').then((r) => r.data).catch(() => []))
useSeo({
  title: t('faq.metaTitle'),
  description: t('faq.lead'),
  jsonLd: { '@type': 'FAQPage', mainEntity: (faqs.value ?? []).map((f) => ({ '@type': 'Question', name: f.question, acceptedAnswer: { '@type': 'Answer', text: f.answer } })) },
})
const cat = ref<string>('all')
const q = ref('')
const open = ref<number | null>(null)
const cats = ['all', 'general', 'investor', 'entrepreneur', 'membership']
const list = computed(() => (faqs.value ?? []).filter((f) => (cat.value === 'all' || f.category === cat.value) && (!q.value || f.question.includes(q.value) || f.answer.includes(q.value))))
const tabs = computed(() => cats.map((c) => ({ key: c, label: c === 'all' ? t('common.all') : t(`faq.categories.${c}`), count: c === 'all' ? faqs.value?.length : faqs.value?.filter((f) => f.category === c).length })))
</script>

<template>
  <div>
    <PageHero :title="$t('faq.title')" :lead="$t('faq.lead')" />
    <section class="container-x section-y max-w-3xl">
      <label class="sr-only" for="faq-q">{{ $t('common.search') }}</label>
      <input id="faq-q" v-model="q" type="search" class="input mb-4" :placeholder="$t('faq.searchPlaceholder')" />
      <Tabs v-model="cat" :tabs="tabs" variant="pills" />
      <div class="mt-6 card divide-y divide-border">
        <div v-for="f in list" :key="f.id">
          <button type="button" class="w-full flex items-center justify-between gap-4 px-5 py-5 text-start text-base font-bold text-ink kbd-focus" :aria-expanded="open === f.id" @click="open = open === f.id ? null : f.id">{{ f.question }}<svg class="h-4 w-4 text-muted transition shrink-0" :class="open === f.id && 'rotate-180'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg></button>
          <p v-show="open === f.id" class="text-desc px-5 pb-5 text-muted">{{ f.answer }}</p>
        </div>
        <EmptyState v-if="!list.length" compact :title="$t('common.noResults')" class="border-0" />
      </div>
      <div class="mt-8 card card-pad flex flex-col sm:flex-row items-center justify-between gap-4"><p class="text-base font-bold text-ink">{{ $t('faq.notFound') }}</p><AppButton to="/contact" variant="outline">{{ $t('faq.contactUs') }}</AppButton></div>
    </section>
  </div>
</template>

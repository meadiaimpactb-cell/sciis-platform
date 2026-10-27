<script setup lang="ts">
/** «أدلة ومراجع» — curated guides (CMS `resources`) with an audience filter; noindex while empty. */
import type { ContentItem } from '~/types/api'

definePageMeta({ layout: 'default' })
const { t } = useI18n()
// CMS — the page header (label, title, lead) is editable in admin → content (utils/pageSections.ts).
const header = await usePageContent('resources')
const siteUrl = useSiteUrl()
const { data: resources } = await usePublicResources()
const { title, body } = useContentText()

type Audience = 'all' | 'investor' | 'entrepreneur'
const audience = ref<Audience>('all')
const tabs = computed(() => (['all', 'investor', 'entrepreneur'] as const).map((k) => ({
  key: k,
  label: k === 'all' ? t('common.all') : t(`resources.audience.${k}`),
  count: k === 'all' ? resources.value.length : resources.value.filter((r) => r.audience === k || r.audience === 'all').length,
})))
const list = computed(() => resources.value.filter((r) => audience.value === 'all' || r.audience === audience.value || r.audience === 'all'))
const href = (r: ContentItem) => r.url ?? r.link_url ?? r.meta?.file?.url ?? null
const fileType = (r: ContentItem) => (r.file?.name?.split('.').pop() ?? '').toUpperCase()
const size = (n?: number | null) => (n ? (n >= 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`) : '')

useSeo({
  title: t('resources.metaTitle'),
  description: header.text('lead', 'resources.lead'),
  noindex: !resources.value.length,
  jsonLd: resources.value.length
    ? {
        '@type': 'ItemList',
        name: t('resources.title'),
        numberOfItems: resources.value.length,
        itemListElement: resources.value.map((r, i) => ({ '@type': 'ListItem', position: i + 1, name: title(r), url: href(r) ?? `${siteUrl}/resources` })),
      }
    : undefined,
})
</script>

<template>
  <div>
    <PageHero :kicker="header.text('kicker', 'tools.eyebrow')" :title="header.text('title', 'resources.title')" :lead="header.text('lead', 'resources.lead')" />

    <section class="container-x section-y">
      <template v-if="resources.length">
        <Tabs v-model="audience" :tabs="tabs" variant="pills" class="w-fit" />
        <ul class="mt-6 grid md:grid-cols-2 lg:grid-cols-3 gap-5" role="list">
          <li v-for="r in list" :key="r.id" class="card card-hover card-pad flex flex-col">
            <div class="flex items-center justify-between gap-2">
              <span class="rounded-full border border-accent/35 bg-accent/10 px-2.5 py-0.5 text-xs font-medium text-accent-text">{{ $t(`resources.audience.${r.audience ?? 'all'}`) }}</span>
              <span v-if="r.file?.name" class="text-xs font-semibold text-muted num ltr">{{ fileType(r) }}<template v-if="r.file?.size"> · {{ size(r.file.size) }}</template></span>
            </div>
            <h2 class="card-title mt-3 leading-8">{{ title(r) }}</h2>
            <p v-if="body(r)" class="mt-2 text-sm text-muted leading-6 line-clamp-4 flex-1">{{ cmsExcerpt(body(r), 240) }}</p>
            <a v-if="href(r)" :href="href(r)!" target="_blank" rel="noopener" class="mt-4 w-fit inline-flex items-center gap-1.5 text-sm font-medium link">
              {{ r.file?.name && !r.link_url ? $t('common.download') : $t('resources.open') }}<span class="sr-only"> — {{ title(r) }} {{ $t('stories.newTab') }}</span>
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path v-if="r.file?.name && !r.link_url" d="M12 4v12M6 10l6 6 6-6M4 20h16" /><path v-else d="M14 4h6v6M20 4l-9 9M19 14v5a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1h5" /></svg>
            </a>
          </li>
        </ul>
        <EmptyState v-if="!list.length" compact :title="$t('common.noResults')" class="mt-6" />
      </template>
      <EmptyState v-else :title="$t('resources.empty')" :text="$t('resources.emptyText')" class="max-w-xl mx-auto">
        <AppButton to="/tools" variant="outline">{{ $t('tools.title') }}</AppButton>
      </EmptyState>
    </section>
  </div>
</template>

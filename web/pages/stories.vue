<script setup lang="ts">
/** «قصص أثر» — published impact stories (CMS `stories`), ItemList JSON-LD; noindex while empty. */
definePageMeta({ layout: 'default' })
const { t } = useI18n()
// CMS — the page header (label, title, lead) is editable in admin → content (utils/pageSections.ts).
const header = await usePageContent('stories')
const route = useRoute()
const siteUrl = useSiteUrl()
const { data: stories } = await usePublicStories()
const { title } = useContentText()

const pageUrl = `${siteUrl}${route.path.replace(/\/$/, '')}`
useSeo({
  title: t('stories.metaTitle'),
  description: header.text('lead', 'stories.lead'),
  image: stories.value.find((s) => s.meta?.cover?.url)?.meta?.cover?.url ?? undefined,
  noindex: !stories.value.length,
  jsonLd: stories.value.length
    ? {
        '@type': 'ItemList',
        name: t('stories.title'),
        numberOfItems: stories.value.length,
        itemListElement: stories.value.map((s, i) => ({ '@type': 'ListItem', position: i + 1, name: title(s), url: `${pageUrl}#${s.slug}` })),
      }
    : undefined,
})
</script>

<template>
  <div>
    <PageHero :kicker="header.text('kicker', 'stories.eyebrow')" :title="header.text('title', 'stories.title')" :lead="header.text('lead', 'stories.lead')" />

    <section class="container-x section-y">
      <div v-if="stories.length" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
        <StoryCard v-for="s in stories" :key="s.id" :story="s" heading-level="h2" />
      </div>
      <EmptyState v-else :title="$t('stories.empty')" :text="$t('stories.emptyText')" class="max-w-xl mx-auto">
        <AppButton to="/" variant="outline">{{ $t('nav.home') }}</AppButton>
      </EmptyState>
      <div class="mt-10"><Disclaimer variant="box" /></div>
    </section>

    <ToolsBand class="pb-4" />
  </div>
</template>

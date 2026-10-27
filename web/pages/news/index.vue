<script setup lang="ts">
/** Public news list (CMS-02) — GET /public/news (paginated {data, meta}); the page hides its grid when empty. */
import type { ContentItem, Paginated } from '~/types/api'

definePageMeta({ layout: 'default' })
const { t, locale } = useI18n()
// CMS — the page header (label, title, lead) is editable in admin → content (utils/pageSections.ts).
const header = await usePageContent('news')
const api = useApi()
const route = useRoute()
const router = useRouter()
const localePath = useLocalePath()
const { date } = useFormat()

const page = computed(() => Math.max(1, Number(route.query.page ?? 1) || 1))
type NewsResponse = { data: ContentItem[]; meta?: Paginated<ContentItem>['meta'] }
const { data, pending } = await useAsyncData<NewsResponse>(
  () => `news-list-${locale.value}-${page.value}`,
  () => api.get<NewsResponse>('/public/news', { page: page.value }).catch((): NewsResponse => ({ data: [] })),
  { watch: [page] },
)
const items = computed(() => data.value?.data ?? [])
const meta = computed(() => data.value?.meta)
const alt = (c: ContentItem) => (locale.value === 'en' && c.meta?.cover?.alt_en ? c.meta.cover.alt_en : c.meta?.cover?.alt_ar) || c.title || c.title_ar
const goPage = (p: number) => router.push({ query: { ...route.query, page: p > 1 ? String(p) : undefined } })

useSeo({ title: t('news.metaTitle'), description: header.text('lead', 'news.lead'), image: items.value[0]?.meta?.cover?.url ?? undefined })
</script>

<template>
  <div>
    <PageHero :kicker="header.text('kicker', 'nav.news')" :title="header.text('title', 'news.title')" :lead="header.text('lead', 'news.lead')" />

    <section class="container-x section-y">
      <div v-if="pending && !items.length" class="text-muted">{{ $t('common.loading') }}</div>

      <template v-else-if="items.length">
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
          <article v-for="n in items" :key="n.id" class="card card-hover flex flex-col overflow-hidden">
            <NuxtLink :to="localePath(`/news/${n.slug}`)" class="block">
              <img v-if="n.meta?.cover?.url" :src="n.meta.cover.url" :alt="alt(n)" :width="n.meta.cover.width ?? 1200" :height="n.meta.cover.height ?? 675" loading="lazy" decoding="async" class="aspect-video w-full object-cover bg-surface">
              <PlaceholderImage v-else class="aspect-video w-full" />
            </NuxtLink>
            <div class="card-pad flex flex-col flex-1">
              <p class="text-xs font-medium text-teal-text num">{{ date(n.published_at) }}<template v-if="n.author?.name"> · {{ n.author.name }}</template></p>
              <h2 class="card-title mt-3 leading-8"><NuxtLink :to="localePath(`/news/${n.slug}`)" class="hover:text-navy transition">{{ n.title ?? n.title_ar }}</NuxtLink></h2>
              <p class="mt-2 text-sm text-muted leading-6 line-clamp-3 flex-1">{{ cmsExcerpt(n.body ?? n.body_ar, 180) }}</p>
              <AppButton :to="`/news/${n.slug}`" variant="ghost" size="sm" class="mt-4 w-fit -ms-3">{{ $t('common.readMore') }}</AppButton>
            </div>
          </article>
        </div>
        <Pagination v-if="meta" class="mt-8" :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @update:page="goPage" />
      </template>

      <EmptyState v-else :title="$t('news.empty')" :text="$t('news.emptyText')" class="max-w-xl mx-auto">
        <AppButton to="/" variant="outline">{{ $t('nav.home') }}</AppButton>
      </EmptyState>
    </section>
  </div>
</template>

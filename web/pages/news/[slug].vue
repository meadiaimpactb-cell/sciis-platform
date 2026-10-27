<script setup lang="ts">
/** News detail (CMS-02) — GET /public/news/{slug}; author box (E-E-A-T), Article + BreadcrumbList JSON-LD. */
import type { ContentItem } from '~/types/api'

definePageMeta({ layout: 'default' })
const { t, locale } = useI18n()
const api = useApi()
const route = useRoute()
const localePath = useLocalePath()
const { date } = useFormat()
const siteUrl = useSiteUrl()
const slug = String(route.params.slug ?? '')

// Only a real 404 from the API means "not found"; a throttled/failed request is retried once, then surfaces as 503
// (during the static build such a route is simply skipped and served by the SPA fallback instead of a false 404).
const fetchItem = () => api.get<{ data: ContentItem }>(`/public/news/${encodeURIComponent(slug)}`).then((r) => r.data)
const { data: post, error: fetchError } = await useAsyncData(`news-${slug}-${locale.value}`, () =>
  fetchItem().catch(async (e: { status?: number }) => {
    if (e?.status === 404) return null
    await new Promise((r) => setTimeout(r, 1500))
    return fetchItem()
  }),
)
if (!post.value) throw createError(fetchError.value ? { statusCode: 503, statusMessage: 'Service unavailable', fatal: true } : { statusCode: 404, statusMessage: 'Not found', fatal: true })

const title = computed(() => post.value?.title ?? post.value?.title_ar ?? '')
const body = computed(() => post.value?.body ?? post.value?.body_ar ?? '')
const html = computed(() => renderCmsBody(body.value))
// English page of an item without an English body: it shows the Arabic text, so it stays out of the index (the
// Arabic page is the original) and gets its own description instead of duplicating the Arabic one.
const untranslated = computed(() => locale.value === 'en' && /[؀-ۿ]/.test(body.value.slice(0, 400)))
const description = computed(() => (untranslated.value ? `${title.value} — ${t('news.lead')}` : cmsExcerpt(body.value, 160)))
const cover = computed(() => post.value?.meta?.cover ?? null)
const coverAlt = computed(() => (locale.value === 'en' && cover.value?.alt_en ? cover.value.alt_en : cover.value?.alt_ar) || title.value)
const image = computed(() => cover.value?.url ?? firstBodyImage(body.value) ?? '/brand/og-image.jpg')
const author = computed(() => post.value?.author ?? null)
const abs = (u: string) => (u.startsWith('http') ? u : `${siteUrl}${u.startsWith('/') ? '' : '/'}${u}`)
const canonical = `${siteUrl}${localePath(`/news/${slug}`)}`
const orgName = 'المجلس السعودي للمستثمرين الاجتماعيين'

useSeo({
  title: title.value,
  description: description.value,
  noindex: untranslated.value,
  image: image.value,
  type: 'article',
  publishedTime: post.value?.published_at ?? undefined,
  modifiedTime: post.value?.updated_at ?? undefined,
  jsonLd: [
    {
      '@type': 'Article',
      mainEntityOfPage: canonical,
      headline: title.value,
      description: description.value,
      image: [abs(image.value)],
      datePublished: post.value?.published_at ?? undefined,
      dateModified: post.value?.updated_at ?? post.value?.published_at ?? undefined,
      inLanguage: locale.value === 'ar' ? 'ar-SA' : 'en',
      author: author.value
        ? { '@type': 'Person', name: author.value.name, jobTitle: author.value.title ?? undefined, description: author.value.bio ?? undefined, image: author.value.avatar_url ?? undefined, sameAs: author.value.linkedin_url ? [author.value.linkedin_url] : undefined }
        : { '@type': 'Organization', name: orgName, url: siteUrl },
      publisher: { '@type': 'Organization', name: orgName, url: siteUrl, logo: { '@type': 'ImageObject', url: `${siteUrl}/brand/logo.png`, width: 900, height: 306 } },
    },
    {
      '@type': 'BreadcrumbList',
      itemListElement: [
        { '@type': 'ListItem', position: 1, name: t('nav.home'), item: `${siteUrl}${localePath('/')}` },
        { '@type': 'ListItem', position: 2, name: t('news.title'), item: `${siteUrl}${localePath('/news')}` },
        { '@type': 'ListItem', position: 3, name: title.value, item: canonical },
      ],
    },
  ],
})
</script>

<template>
  <div v-if="post">
    <!-- hero: the PageHero surface; the breadcrumb sits in the pill label -->
    <section class="surface-hero">
      <OrbitBackdrop />
      <div class="container-x relative flex flex-col items-center text-center py-20 md:pb-[8.5rem] md:pt-24">
        <SectionLabel as="nav" pill :aria-label="$t('news.breadcrumb')">
          <NuxtLink :to="localePath('/')" class="hover:text-ink transition">{{ $t('nav.home') }}</NuxtLink><span class="text-t3" aria-hidden="true">/</span>
          <NuxtLink :to="localePath('/news')" class="hover:text-ink transition">{{ $t('news.title') }}</NuxtLink>
        </SectionLabel>
        <h1 class="display mt-6 max-w-4xl text-ink text-balance">{{ title }}</h1>
        <p class="mt-6 max-w-3xl text-base text-t2 num">
          <time :datetime="post.published_at ?? undefined">{{ $t('news.publishedOn', { date: date(post.published_at) }) }}</time>
          <template v-if="post.updated_at && post.updated_at !== post.published_at"> · {{ $t('news.updatedOn', { date: date(post.updated_at) }) }}</template>
          <template v-if="author?.name"> · {{ $t('news.by', { name: author.name }) }}</template>
        </p>
      </div>
    </section>

    <section class="container-x max-w-3xl pb-20 pt-12 md:pb-[6.25rem]">
      <figure v-if="cover?.url" class="mb-10 -mt-24 relative">
        <img :src="cover.url" :alt="coverAlt" :width="cover.width ?? 1200" :height="cover.height ?? 675" loading="eager" fetchpriority="high" decoding="async" class="w-full rounded-2xl border border-border shadow-pop object-cover aspect-video bg-surface">
      </figure>
      <!-- eslint-disable-next-line vue/no-v-html -- body is sanitized/escaped by renderCmsBody -->
      <article class="prose-basic text-base" v-html="html" />

      <aside v-if="author" class="card card-pad mt-6 flex flex-col sm:flex-row gap-5" :aria-label="$t('news.aboutAuthor')">
        <img v-if="author.avatar_url" :src="author.avatar_url" :alt="author.name" width="96" height="96" loading="lazy" decoding="async" class="h-24 w-24 rounded-2xl object-cover ring-1 ring-border shrink-0">
        <span v-else class="h-24 w-24 rounded-2xl bg-gradient-brand text-white flex items-center justify-center text-3xl font-semibold shrink-0" aria-hidden="true">{{ author.name.trim().charAt(0) }}</span>
        <div class="min-w-0">
          <p class="text-sm text-muted">{{ $t('news.aboutAuthor') }}</p>
          <h2 class="card-title mt-2">{{ author.name }}</h2>
          <p v-if="author.title" class="text-sm text-teal-text font-medium">{{ author.title }}</p>
          <p v-if="author.bio" class="mt-2 text-sm text-muted leading-7">{{ author.bio }}</p>
          <a v-if="author.linkedin_url" :href="author.linkedin_url" target="_blank" rel="noopener noreferrer me" class="mt-3 inline-flex items-center gap-1.5 text-sm font-medium text-navy hover:text-teal-text transition kbd-focus">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.45 20.45h-3.56v-5.57c0-1.33-.03-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.67H9.34V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28zM5.34 7.43a2.07 2.07 0 110-4.13 2.07 2.07 0 010 4.13zM7.12 20.45H3.56V9h3.56v11.45zM22.22 0H1.77C.79 0 0 .77 0 1.73v20.54C0 23.23.79 24 1.77 24h20.45c.98 0 1.78-.77 1.78-1.73V1.73C24 .77 23.2 0 22.22 0z" /></svg>
            {{ $t('news.linkedin') }}
          </a>
        </div>
      </aside>

      <div class="mt-8"><AppButton to="/news" variant="outline">{{ $t('news.backToList') }}</AppButton></div>
    </section>
  </div>
</template>

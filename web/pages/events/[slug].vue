<script setup lang="ts">
/** Event detail (CMS-02) — GET /public/events/{slug}; Event + BreadcrumbList JSON-LD. */
import type { ContentItem } from '~/types/api'

definePageMeta({ layout: 'default' })
const { t, locale } = useI18n()
const api = useApi()
const route = useRoute()
const localePath = useLocalePath()
const { dateTime } = useFormat()
const siteUrl = useSiteUrl()
const slug = String(route.params.slug ?? '')

// Only a real 404 from the API means "not found"; a throttled/failed request is retried once, then surfaces as 503
// (during the static build such a route is simply skipped and served by the SPA fallback instead of a false 404).
const fetchItem = () => api.get<{ data: ContentItem }>(`/public/events/${encodeURIComponent(slug)}`).then((r) => r.data)
const { data: event, error: fetchError } = await useAsyncData(`event-${slug}-${locale.value}`, () =>
  fetchItem().catch(async (e: { status?: number }) => {
    if (e?.status === 404) return null
    await new Promise((r) => setTimeout(r, 1500))
    return fetchItem()
  }),
)
if (!event.value) throw createError(fetchError.value ? { statusCode: 503, statusMessage: 'Service unavailable', fatal: true } : { statusCode: 404, statusMessage: 'Not found', fatal: true })

const title = computed(() => event.value?.title ?? event.value?.title_ar ?? '')
const body = computed(() => event.value?.body ?? event.value?.body_ar ?? '')
const html = computed(() => renderCmsBody(body.value))
// English page of an item without an English body: it shows the Arabic text, so it stays out of the index (the
// Arabic page is the original) and gets its own description instead of duplicating the Arabic one.
const untranslated = computed(() => locale.value === 'en' && /[؀-ۿ]/.test(body.value.slice(0, 400)))
const description = computed(() => (untranslated.value ? `${title.value} — ${t('events.lead')}` : cmsExcerpt(body.value, 160)))
const cover = computed(() => event.value?.meta?.cover ?? null)
const coverAlt = computed(() => (locale.value === 'en' && cover.value?.alt_en ? cover.value.alt_en : cover.value?.alt_ar) || title.value)
const image = computed(() => cover.value?.url ?? firstBodyImage(body.value) ?? '/brand/og-image.jpg')
const isPast = computed(() => {
  const end = event.value?.ends_at ?? event.value?.starts_at
  return !!end && new Date(end).getTime() < Date.now()
})
// MTG-04 — on-platform registration (lazy form, status fetched client-side); MTG-05 — add to Google / Outlook / .ics.
const registrationEnabled = computed(() => !!(event.value as { registration_enabled?: boolean } | null)?.registration_enabled)
const calendarItem = computed(() => (event.value?.starts_at ? { title: title.value, starts_at: event.value.starts_at, ends_at: event.value.ends_at, location: event.value.location } : null))
const icsHref = `${useApiBase().baseURL}/public/events/${encodeURIComponent(slug)}/calendar.ics`
const abs = (u: string) => (u.startsWith('http') ? u : `${siteUrl}${u.startsWith('/') ? '' : '/'}${u}`)
const canonical = `${siteUrl}${localePath(`/events/${slug}`)}`
const orgName = 'المجلس السعودي للمستثمرين الاجتماعيين'

useSeo({
  title: title.value,
  description: description.value,
  noindex: untranslated.value,
  image: image.value,
  jsonLd: [
    {
      '@type': 'Event',
      name: title.value,
      description: description.value,
      image: [abs(image.value)],
      startDate: event.value?.starts_at ?? undefined,
      endDate: event.value?.ends_at ?? undefined,
      eventStatus: 'https://schema.org/EventScheduled',
      eventAttendanceMode: event.value?.location ? 'https://schema.org/OfflineEventAttendanceMode' : 'https://schema.org/OnlineEventAttendanceMode',
      location: event.value?.location ? { '@type': 'Place', name: event.value.location, address: event.value.location } : { '@type': 'VirtualLocation', url: event.value?.registration_url ?? canonical },
      organizer: { '@type': 'Organization', name: orgName, url: siteUrl },
      ...(event.value?.registration_url ? { offers: { '@type': 'Offer', url: event.value.registration_url, availability: 'https://schema.org/InStock' } } : {}),
    },
    {
      '@type': 'BreadcrumbList',
      itemListElement: [
        { '@type': 'ListItem', position: 1, name: t('nav.home'), item: `${siteUrl}${localePath('/')}` },
        { '@type': 'ListItem', position: 2, name: t('events.title'), item: `${siteUrl}${localePath('/events')}` },
        { '@type': 'ListItem', position: 3, name: title.value, item: canonical },
      ],
    },
  ],
})
</script>

<template>
  <div v-if="event">
    <section class="surface-dark relative overflow-hidden">
      <NightSky />
      <div class="container-x relative max-w-4xl py-20 text-center md:py-[6.25rem]">
        <nav class="text-sm text-white/60 flex flex-wrap items-center justify-center gap-1.5" :aria-label="$t('news.breadcrumb')">
          <NuxtLink :to="localePath('/')" class="hover:text-white transition">{{ $t('nav.home') }}</NuxtLink><span aria-hidden="true">/</span>
          <NuxtLink :to="localePath('/events')" class="hover:text-white transition">{{ $t('events.title') }}</NuxtLink>
        </nav>
        <p class="mt-5 text-xl text-white/60">{{ isPast ? $t('events.past') : $t('events.upcoming') }}</p>
        <h1 class="section-title mt-2 text-white text-balance">{{ title }}</h1>
      </div>
    </section>

    <section class="container-x section-y grid lg:grid-cols-[1fr_20rem] gap-10 items-start">
      <div class="min-w-0">
        <figure v-if="cover?.url" class="mb-10">
          <img :src="cover.url" :alt="coverAlt" :width="cover.width ?? 1200" :height="cover.height ?? 675" loading="eager" fetchpriority="high" decoding="async" class="w-full rounded-md object-cover aspect-video bg-surface">
        </figure>
        <!-- eslint-disable-next-line vue/no-v-html -- body is sanitized/escaped by renderCmsBody -->
        <article v-if="html" class="prose-basic text-base" v-html="html" />
        <div class="mt-8"><AppButton to="/events" variant="outline">{{ $t('events.backToList') }}</AppButton></div>
      </div>

      <aside class="card card-pad lg:sticky lg:top-24">
        <dl class="space-y-5 text-base">
          <div><dt class="text-sm text-muted">{{ $t('events.when') }}</dt><dd class="mt-1 font-bold num"><time :datetime="event.starts_at ?? undefined">{{ dateTime(event.starts_at) }}</time><template v-if="event.ends_at"> — <time :datetime="event.ends_at">{{ dateTime(event.ends_at) }}</time></template></dd></div>
          <div v-if="event.location"><dt class="text-sm text-muted">{{ $t('events.where') }}</dt><dd class="mt-1 font-bold">{{ event.location }}</dd></div>
        </dl>
        <AppButton v-if="event.registration_url && !isPast" :href="event.registration_url" target="_blank" rel="noopener noreferrer" variant="accent" block class="mt-6">{{ $t('events.register') }}</AppButton>
        <p v-else-if="isPast" class="mt-6 text-xs text-muted">{{ $t('events.ended') }}</p>
        <LazyEventRegistrationForm v-if="registrationEnabled && !isPast" :slug="slug" />
        <LazyAddToCalendar v-if="calendarItem && !isPast" :item="calendarItem" :ics-href="icsHref" class="mt-6 pt-6 border-t border-border" />
      </aside>
    </section>
  </div>
</template>

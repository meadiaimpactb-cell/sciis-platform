<script setup lang="ts">
/** Public events list (CMS-02) — GET /public/events; split into upcoming / past, hidden when empty. */
import type { ContentItem } from '~/types/api'

definePageMeta({ layout: 'default' })
const { t, locale } = useI18n()
const api = useApi()
const localePath = useLocalePath()
const { date, dateTime } = useFormat()

const { data, pending } = await useAsyncData(`events-list-${locale.value}`, () =>
  api.get<{ data: ContentItem[] }>('/public/events').then((r) => r.data).catch(() => [] as ContentItem[]),
)
const items = computed(() => data.value ?? [])
const now = Date.now()
const isPast = (e: ContentItem) => {
  const end = e.ends_at ?? e.starts_at
  return !!end && new Date(end).getTime() < now
}
const upcoming = computed(() => items.value.filter((e) => !isPast(e)))
const past = computed(() => items.value.filter(isPast).sort((a, b) => new Date(b.starts_at ?? 0).getTime() - new Date(a.starts_at ?? 0).getTime()))
const alt = (c: ContentItem) => (locale.value === 'en' && c.meta?.cover?.alt_en ? c.meta.cover.alt_en : c.meta?.cover?.alt_ar) || c.title || c.title_ar

useSeo({ title: t('events.metaTitle'), description: t('events.lead'), image: upcoming.value[0]?.meta?.cover?.url ?? undefined })
</script>

<template>
  <div>
    <PageHero :kicker="$t('nav.events')" :title="$t('events.title')" :lead="$t('events.lead')" />

    <section class="container-x section-y">
      <div v-if="pending && !items.length" class="text-muted">{{ $t('common.loading') }}</div>

      <template v-else-if="items.length">
        <template v-for="group in [{ key: 'upcoming', list: upcoming }, { key: 'past', list: past }]" :key="group.key">
          <div v-if="group.list.length" :class="group.key === 'past' && upcoming.length ? 'mt-14' : ''">
            <h2 class="section-label">{{ $t(`events.${group.key}`) }}</h2>
            <div class="mt-8 grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
              <article v-for="e in group.list" :key="e.id" class="card card-hover flex flex-col overflow-hidden" :class="group.key === 'past' && 'opacity-80'">
                <NuxtLink :to="localePath(`/events/${e.slug}`)" class="block">
                  <img v-if="e.meta?.cover?.url" :src="e.meta.cover.url" :alt="alt(e)" :width="e.meta.cover.width ?? 1200" :height="e.meta.cover.height ?? 675" loading="lazy" decoding="async" class="aspect-video w-full object-cover bg-surface">
                  <PlaceholderImage v-else class="aspect-video w-full" :variant="2" />
                </NuxtLink>
                <div class="card-pad flex flex-col flex-1">
                  <div class="flex items-start gap-3">
                    <time v-if="e.starts_at" :datetime="e.starts_at" class="shrink-0 rounded-xl border border-accent/35 bg-accent/10 text-accent-text text-center px-3 py-2 num leading-none"><span class="block text-xl font-semibold">{{ new Date(e.starts_at).getDate() }}</span><span class="block text-xs mt-1 font-medium">{{ date(e.starts_at, { month: 'short' }) }}</span></time>
                    <div class="min-w-0">
                      <h3 class="card-title leading-8"><NuxtLink :to="localePath(`/events/${e.slug}`)" class="hover:text-accent-text transition">{{ e.title ?? e.title_ar }}</NuxtLink></h3>
                      <p class="mt-1 text-xs text-muted num">{{ dateTime(e.starts_at) }}<template v-if="e.location"> · {{ e.location }}</template></p>
                    </div>
                  </div>
                  <p class="mt-3 text-sm text-muted leading-6 line-clamp-3 flex-1">{{ cmsExcerpt(e.body ?? e.body_ar, 160) }}</p>
                  <div class="mt-4 flex flex-wrap gap-2">
                    <AppButton :to="`/events/${e.slug}`" variant="outline" size="sm">{{ $t('events.details') }}</AppButton>
                    <AppButton v-if="e.registration_url && group.key === 'upcoming'" :href="e.registration_url" target="_blank" rel="noopener noreferrer" variant="accent" size="sm">{{ $t('events.register') }}</AppButton>
                  </div>
                </div>
              </article>
            </div>
          </div>
        </template>
      </template>

      <EmptyState v-else :title="$t('events.empty')" :text="$t('events.emptyText')" class="max-w-xl mx-auto">
        <AppButton to="/" variant="outline">{{ $t('nav.home') }}</AppButton>
      </EmptyState>
    </section>
  </div>
</template>

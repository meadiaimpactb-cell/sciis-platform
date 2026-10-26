<script setup lang="ts">
/** Impact story card (CMS `stories`): cover (or brand placeholder), sector, title, summary, beneficiaries + impact metric. */
import type { ContentItem } from '~/types/api'

const props = withDefaults(defineProps<{ story: ContentItem; headingLevel?: 'h2' | 'h3' }>(), { headingLevel: 'h3' })
const { number } = useFormat()
const { alt, title, body } = useContentText()
const cover = computed(() => props.story.meta?.cover ?? null)
const logo = computed(() => props.story.meta?.logo ?? null)
const summary = computed(() => cmsExcerpt(body(props.story), 220))
</script>

<template>
  <article :id="story.slug" class="card card-hover flex flex-col overflow-hidden scroll-mt-24">
    <div class="relative">
      <img v-if="cover?.url" :src="cover.url" :alt="alt(cover, title(story))" :width="cover.width ?? 1200" :height="cover.height ?? 675" loading="lazy" decoding="async" class="aspect-video w-full object-cover bg-surface">
      <div v-else class="aspect-video w-full bg-gradient-brand relative overflow-hidden" aria-hidden="true">
        <div class="absolute inset-0 brand-grid opacity-60" />
        <img src="/brand/mark-white.svg" alt="" width="120" height="120" class="absolute inset-0 m-auto h-1/2 w-auto opacity-25">
      </div>
      <span v-if="logo?.url" class="absolute bottom-3 start-3 h-12 w-12 rounded-xl bg-white shadow-card p-1.5 flex items-center justify-center">
        <img :src="logo.url" :alt="alt(logo, title(story))" :width="logo.width ?? 96" :height="logo.height ?? 96" loading="lazy" decoding="async" class="max-h-full max-w-full object-contain">
      </span>
    </div>
    <div class="card-pad flex flex-col flex-1">
      <p v-if="story.sector" class="text-xs font-medium text-teal-text">{{ story.sector }}</p>
      <component :is="headingLevel" class="mt-1.5 font-semibold leading-7 text-ink">{{ title(story) }}</component>
      <p v-if="summary" class="mt-2 text-sm text-muted leading-6 line-clamp-4 flex-1">{{ summary }}</p>
      <dl v-if="story.beneficiaries != null || story.impact_metric" class="mt-4 grid grid-cols-2 gap-3 border-t border-border pt-4 text-sm">
        <div v-if="story.beneficiaries != null">
          <dt class="text-xs text-muted">{{ $t('stories.beneficiaries') }}</dt>
          <dd class="mt-0.5 text-lg font-semibold num text-navy">{{ number(story.beneficiaries, { maximumFractionDigits: 0 }) }}</dd>
        </div>
        <div v-if="story.impact_metric" :class="story.beneficiaries == null && 'col-span-2'">
          <dt class="text-xs text-muted">{{ $t('stories.impact') }}</dt>
          <dd class="mt-0.5 font-medium leading-6 text-ink">{{ story.impact_metric }}</dd>
        </div>
      </dl>
      <a v-if="story.link_url" :href="story.link_url" target="_blank" rel="noopener" class="mt-4 w-fit text-sm font-medium link">{{ $t('common.readMore') }}<span class="sr-only"> — {{ title(story) }} {{ $t('stories.newTab') }}</span></a>
    </div>
  </article>
</template>

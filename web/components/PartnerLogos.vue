<script setup lang="ts">
/**
 * «شركاؤنا» logo grid (CMS `partners`). The API returns only published partners approved for display, and every logo
 * carries the partner name as alt text plus a visible caption — never an anonymous logo.
 */
import type { ContentItem } from '~/types/api'

defineProps<{ partners: ContentItem[] }>()
const { alt, title } = useContentText()
const name = (p: ContentItem) => p.name ?? title(p)
</script>

<template>
  <ul class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4" role="list">
    <li v-for="p in partners" :key="p.id">
      <component
        :is="p.link_url ? 'a' : 'div'"
        v-bind="p.link_url ? { href: p.link_url, target: '_blank', rel: 'noopener' } : {}"
        class="h-full rounded-md p-4 flex flex-col items-center justify-center gap-3 text-center transition"
        :class="p.link_url && 'hover:bg-navy-50 kbd-focus'"
      >
        <span class="h-20 w-full flex items-center justify-center">
          <img v-if="p.meta?.logo?.url" :src="p.meta.logo.url" :alt="alt(p.meta.logo, name(p))" :width="p.meta.logo.width ?? 240" :height="p.meta.logo.height ?? 96" loading="lazy" decoding="async" class="max-h-20 w-auto max-w-full object-contain">
          <span v-else class="h-14 w-14 rounded-xl bg-navy-50 text-navy font-semibold text-xl flex items-center justify-center" aria-hidden="true">{{ name(p).trim().charAt(0) }}</span>
        </span>
        <span class="text-sm font-medium text-ink leading-6">{{ name(p) }}<span v-if="p.link_url" class="sr-only"> {{ $t('stories.newTab') }}</span></span>
      </component>
    </li>
  </ul>
</template>

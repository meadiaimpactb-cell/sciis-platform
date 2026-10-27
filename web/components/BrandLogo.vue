<script setup lang="ts">
/**
 * Official SCII logo. `variant="full"` renders the bilingual wordmark (WebP, 900×306); `variant="mark"` renders the
 * emblem only (SVG, 100×136). By default it follows the theme — the white logo on the dark palette, the navy one on
 * the light palette (both images are rendered; CSS shows one, the hidden lazy one is never fetched). `tone` forces one.
 * Explicit width/height attributes reserve space before the image loads (no layout shift).
 */
const props = withDefaults(defineProps<{ variant?: 'full' | 'mark'; tone?: 'auto' | 'white' | 'navy'; size?: 'sm' | 'md' | 'lg'; eager?: boolean }>(), { variant: 'full', tone: 'auto', size: 'md', eager: false })
const heights = { full: { sm: 36, md: 44, lg: 64 }, mark: { sm: 32, md: 40, lg: 56 } }
const ratio = { full: 900 / 306, mark: 100 / 136 }
const h = computed(() => heights[props.variant][props.size])
const w = computed(() => Math.round(h.value * ratio[props.variant]))
const src = computed(() => (props.variant === 'full' ? { white: '/brand/logo-white.webp', navy: '/brand/logo.webp' } : { white: '/brand/mark-white.svg', navy: '/brand/mark.svg' }))
const shown = computed(() => (props.tone === 'auto' ? (['white', 'navy'] as const) : [props.tone]))
</script>

<template>
  <img
    v-for="t in shown"
    :key="t"
    :src="src[t]"
    :alt="$t('app.name')"
    :width="w"
    :height="h"
    :style="{ height: h + 'px', width: w + 'px' }"
    class="select-none"
    :class="tone === 'auto' && (t === 'white' ? 'on-dark' : 'on-light')"
    :loading="eager ? 'eager' : 'lazy'"
    :fetchpriority="eager ? 'high' : 'auto'"
    decoding="async"
  >
</template>

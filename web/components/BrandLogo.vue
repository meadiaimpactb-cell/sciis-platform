<script setup lang="ts">
/**
 * Official SCII logo. `variant="full"` renders the bilingual wordmark (WebP, 900×306); `variant="mark"` renders the
 * emblem only (SVG, 100×136). `dark` switches to the white versions for navy surfaces.
 * Explicit width/height attributes reserve space before the image loads (no layout shift).
 */
const props = withDefaults(defineProps<{ variant?: 'full' | 'mark'; dark?: boolean; size?: 'sm' | 'md' | 'lg'; eager?: boolean }>(), { variant: 'full', dark: false, size: 'md', eager: false })
const heights = { full: { sm: 36, md: 44, lg: 64 }, mark: { sm: 32, md: 40, lg: 56 } }
const ratio = { full: 900 / 306, mark: 100 / 136 }
const h = computed(() => heights[props.variant][props.size])
const w = computed(() => Math.round(h.value * ratio[props.variant]))
</script>

<template>
  <img
    v-if="variant === 'full'"
    :src="dark ? '/brand/logo-white.webp' : '/brand/logo.webp'"
    :alt="$t('app.name')"
    :width="w"
    :height="h"
    :style="{ height: h + 'px', width: w + 'px' }"
    class="select-none"
    :loading="eager ? 'eager' : 'lazy'"
    :fetchpriority="eager ? 'high' : 'auto'"
    decoding="async"
  >
  <img
    v-else
    :src="dark ? '/brand/mark-white.svg' : '/brand/mark.svg'"
    :alt="$t('app.name')"
    :width="w"
    :height="h"
    :style="{ height: h + 'px', width: w + 'px' }"
    class="select-none"
    :loading="eager ? 'eager' : 'lazy'"
    decoding="async"
  >
</template>

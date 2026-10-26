<script setup lang="ts">
/** Clearly-labelled "صورة توضيحية" brand-gradient block with the described photo intent (no stock photos). */
withDefaults(defineProps<{ intent?: string; ratio?: string; compact?: boolean; variant?: 1 | 2 | 3; flush?: boolean }>(), { ratio: 'aspect-[4/3]', compact: false, variant: 1, flush: false })
const gradients = {
  1: 'linear-gradient(135deg, #58B4A8 0%, #3C6E8E 45%, #24306C 100%)',
  2: 'linear-gradient(160deg, #0E1330 0%, #1B2555 50%, #3F9A8F 100%)',
  3: 'linear-gradient(120deg, #24306C 0%, #33428A 50%, #7FCBC0 100%)',
}
</script>

<template>
  <div class="placeholder-img flex flex-col items-start justify-end" :class="[ratio, compact ? 'p-3' : 'p-5', flush && 'rounded-none border-0']" :style="{ background: gradients[variant] }" role="img" :aria-label="`${$t('app.placeholderImage')}${intent ? ': ' + intent : ''}`">
    <img src="/brand/mark-white.svg" width="100" height="136" alt="" aria-hidden="true" class="w-auto absolute -end-6 -bottom-8 h-[120%] opacity-[0.12] pointer-events-none select-none">
    <div class="absolute inset-0 opacity-20" style="background-image: radial-gradient(circle at 20% 20%, white 0, transparent 35%)" aria-hidden="true" />
    <span class="relative inline-flex items-center gap-1.5 rounded-full bg-white/15 backdrop-blur px-2.5 py-1 text-xs font-medium">
      <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 15l5-5 4 4 3-3 6 6" /><circle cx="16" cy="9" r="1.5" /></svg>
      {{ $t('app.placeholderImage') }}
    </span>
    <p v-if="intent && !compact" class="relative mt-2 text-xs text-white/80 max-w-xs leading-5">{{ intent }}</p>
  </div>
</template>

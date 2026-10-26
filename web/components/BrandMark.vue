<script setup lang="ts">
/**
 * The SCII emblem drawn inline (design v3) so it follows the theme: the top arc is the teal gradient, the bottom
 * arc and the frame use --mark-2 (white in dark, navy in light). `framed` adds the rectangle of the full mark;
 * `filled` gives the frame the translucent surface used in the hero.
 */
withDefaults(defineProps<{ width?: number; framed?: boolean; filled?: boolean; frameWidth?: number; glow?: boolean }>(), {
  width: 44,
  framed: true,
  filled: false,
  frameWidth: 7,
  glow: false,
})
const id = `scii-mark-${useId()}`
</script>

<template>
  <svg viewBox="0 0 100 136" :width="width" :height="Math.round(width * 1.36)" aria-hidden="true">
    <defs>
      <linearGradient :id="id" x1="0" y1="0" x2="1" y2="1">
        <stop offset="0" stop-color="#7FD3C9" />
        <stop offset="1" stop-color="#58B4A8" />
      </linearGradient>
    </defs>
    <rect v-if="framed" x="4.5" y="4.5" width="91" height="127" :stroke-width="frameWidth" stroke="var(--mark-2)" :fill="filled ? 'rgba(var(--bg1-rgb), .55)' : 'none'" />
    <path d="M76 20A50 50 0 0 0 26 70H42A34 34 0 0 1 76 36Z" :fill="`url(#${id})`" :style="glow ? 'filter: drop-shadow(0 0 18px rgba(var(--accent-rgb), .55))' : undefined" />
    <path d="M24 116A50 50 0 0 0 74 66H58A34 34 0 0 1 24 100Z" fill="var(--mark-2)" />
  </svg>
</template>

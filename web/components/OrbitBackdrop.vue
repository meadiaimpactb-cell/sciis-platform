<script setup lang="ts">
/**
 * Decorative backdrop of the hero surfaces (design v3): a star field and, optionally, the orbit rings with the accent
 * arc. Theme colours only, static markup (identical on server and client, no layout shift).
 * The parent must be `relative overflow-hidden`.
 */
withDefaults(defineProps<{ orbits?: boolean }>(), { orbits: true })
// [x %, y %, size px, accent?] — fixed positions so SSR and hydration match
const stars: [number, number, number, boolean?][] = [
  [8, 26, 3], [11, 31, 2], [14, 44, 2], [20, 18, 4, true], [27, 63, 2], [34, 12, 2], [42, 80, 3],
  [57, 9, 2], [63, 71, 2, true], [71, 22, 3], [79, 58, 2], [86, 14, 2], [92, 38, 3, true], [96, 66, 2], [52, 92, 2], [5, 76, 2],
]
</script>

<template>
  <div class="orbit-backdrop" aria-hidden="true">
    <svg v-if="orbits" class="orbit-backdrop__rings" viewBox="0 0 1440 900" preserveAspectRatio="xMidYMid slice" fill="none">
      <circle cx="720" cy="450" r="250" stroke="rgba(var(--fg-rgb), .12)" vector-effect="non-scaling-stroke" />
      <circle cx="720" cy="450" r="560" stroke="rgba(var(--fg-rgb), .06)" vector-effect="non-scaling-stroke" />
      <path d="M624 221A250 250 0 0 1 816 221" stroke="var(--accent-hi)" stroke-width="2.5" stroke-linecap="round" opacity=".7" />
      <circle cx="816" cy="221" r="5" fill="var(--accent-hi)" />
    </svg>
    <span
      v-for="([x, y, s, accent], i) in stars" :key="i"
      class="orbit-backdrop__star"
      :class="accent && 'orbit-backdrop__star--accent'"
      :style="{ insetInlineStart: `${x}%`, top: `${y}%`, width: `${s}px`, height: `${s}px` }"
    />
  </div>
</template>

<style scoped>
.orbit-backdrop { position: absolute; inset: 0; pointer-events: none; user-select: none; opacity: var(--stars); }
.orbit-backdrop__rings { position: absolute; inset: 0; width: 100%; height: 100%; }
.orbit-backdrop__star { position: absolute; border-radius: 50%; background: rgba(var(--fg-rgb), 0.6); }
.orbit-backdrop__star--accent { background: rgba(var(--accent-rgb), 0.85); }
</style>

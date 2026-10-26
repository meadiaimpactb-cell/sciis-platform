<script setup lang="ts">
/**
 * Decorative backdrop for the dark sections (hero, numbers, CTA, footer): thin orbit arcs and a few small stars,
 * in the brand's white/teal at low opacity. Static markup — no JS, identical on server and client, no layout shift.
 * The parent must be `relative overflow-hidden`.
 */
withDefaults(defineProps<{ orbits?: boolean }>(), { orbits: true })
// [x %, y %, size px, teal?] — fixed positions so SSR and hydration match
const stars: [number, number, number, boolean?][] = [
  [8, 26, 3], [11, 31, 2], [14, 44, 2], [20, 18, 4, true], [27, 63, 2], [34, 12, 2], [42, 80, 3],
  [57, 9, 2], [63, 71, 2, true], [71, 22, 3], [79, 58, 2], [86, 14, 2], [92, 38, 3, true], [96, 66, 2], [52, 92, 2], [5, 76, 2],
]
</script>

<template>
  <div class="pointer-events-none absolute inset-0 select-none" aria-hidden="true">
    <svg v-if="orbits" class="absolute inset-0 h-full w-full" viewBox="0 0 1440 900" preserveAspectRatio="xMidYMid slice" fill="none">
      <ellipse cx="720" cy="1180" rx="1150" ry="900" stroke="rgba(255,255,255,.09)" vector-effect="non-scaling-stroke" />
      <circle cx="240" cy="450" r="760" stroke="rgba(255,255,255,.07)" vector-effect="non-scaling-stroke" />
      <circle cx="1200" cy="450" r="760" stroke="rgba(88,180,168,.14)" vector-effect="non-scaling-stroke" />
    </svg>
    <span
      v-for="([x, y, s, teal], i) in stars" :key="i"
      class="absolute rounded-full"
      :class="teal ? 'bg-teal-400/80' : 'bg-white/60'"
      :style="{ insetInlineStart: `${x}%`, top: `${y}%`, width: `${s}px`, height: `${s}px` }"
    />
  </div>
</template>

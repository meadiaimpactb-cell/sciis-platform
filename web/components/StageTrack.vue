<script setup lang="ts">
/**
 * Stage track (design v3): the stages of a journey on one line — done stages filled, the current one ringed.
 * Scrolls inside itself on narrow screens (never the page). `current` is the 0-based index of the active stage.
 */
withDefaults(defineProps<{ stages: string[]; current?: number; label?: string }>(), { current: 0 })
const pad = (n: number) => String(n).padStart(2, '0')
</script>

<template>
  <div class="overflow-x-auto scrollbar-thin -mx-1 px-1 pb-1">
    <ol class="flex min-w-max items-start" :aria-label="label">
      <li v-for="(s, i) in stages" :key="i" class="relative flex w-28 shrink-0 flex-col items-center gap-2 text-center" :aria-current="i === current ? 'step' : undefined">
        <span v-if="i > 0" class="absolute top-4 end-1/2 h-0.5 w-full -translate-y-1/2" :class="i <= current ? 'bg-accent' : 'bg-ink/15'" aria-hidden="true" />
        <span
          class="relative z-[1] flex h-8 w-8 items-center justify-center rounded-full border text-xs font-bold num"
          :class="i < current ? 'border-accent bg-accent text-on-accent' : i === current ? 'border-accent-hi bg-bg1 text-accent-text ring-4 ring-accent/25' : 'border-ink/20 bg-bg1 text-t3'"
        >
          <svg v-if="i < current" class="h-4 w-4" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4.5 10.5l3.5 3.5 7.5-8" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
          <template v-else>{{ pad(i + 1) }}</template>
        </span>
        <span class="text-xs leading-5" :class="i === current ? 'font-semibold text-ink' : 'text-t3'">{{ s }}</span>
      </li>
    </ol>
  </div>
</template>

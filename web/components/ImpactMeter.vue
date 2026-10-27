<script setup lang="ts">
/**
 * Impact meter (design v3): a reading on a bar from 0 to the target, with an optional baseline marker.
 * Values are in the indicator's unit; `percent` formats them as percentages (Latin digits).
 */
const props = withDefaults(defineProps<{ label: string; value: number; target?: number; baseline?: number; percent?: boolean; hint?: string }>(), { target: 100, percent: false })
const { number, percent: pct } = useFormat()
const fmt = (v: number) => (props.percent ? pct(v) : number(v))
const at = (v: number) => `${Math.min(100, Math.max(0, Math.round((v / (props.target || 1)) * 100)))}%`
</script>

<template>
  <div class="space-y-2">
    <div class="flex items-baseline justify-between gap-3 text-sm">
      <span class="text-t2">{{ label }}</span>
      <span class="font-display text-lg font-extrabold text-accent-text num">{{ fmt(value) }}</span>
    </div>
    <div class="relative h-2.5 rounded-full bg-ink/10" role="meter" :aria-label="label" :aria-valuenow="value" aria-valuemin="0" :aria-valuemax="target" :aria-valuetext="fmt(value)">
      <div class="absolute inset-y-0 start-0 rounded-full bg-accent" :style="{ width: at(value) }" />
      <div v-if="baseline != null" class="absolute -inset-y-1 w-0.5 rounded-full bg-ink/60" :style="{ insetInlineStart: at(baseline) }" />
    </div>
    <div class="flex justify-between gap-3 text-xs text-t3">
      <span v-if="baseline != null">{{ $t('landing.impact.card.baseline') }} <span class="num">{{ fmt(baseline) }}</span></span>
      <span v-else-if="hint">{{ hint }}</span>
      <span>{{ $t('landing.impact.card.target') }} <span class="num">{{ fmt(target) }}</span></span>
    </div>
  </div>
</template>

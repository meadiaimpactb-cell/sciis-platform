<script setup lang="ts">
/** RPT-03 — small SVG donut (no chart lib) with a legend. `clickable`: segments and legend rows emit `select`
 *  (drill-down). Brand palette (navy / teal first), Latin digits via useFormat. */
const props = withDefaults(defineProps<{ series: { label: string; value: number; color?: string; key?: string }[]; clickable?: boolean; selectedKey?: string | null; unit?: string }>(), { clickable: false, selectedKey: null })
const emit = defineEmits<{ (e: 'select', item: { label: string; value: number; key?: string }, index: number): void }>()
const { number, percent } = useFormat()
// theme-aware series colours (tokens.css)
const PALETTE = ['var(--hue-navy)', 'var(--scii-teal)', 'var(--hue-indigo)', 'var(--hue-teal-deep)', 'var(--accent-hi)', 'var(--hue-amber)', 'var(--hue-gray)', 'var(--hue-violet)']
const R = 15.9155 // circumference 100
const total = computed(() => props.series.reduce((a, s) => a + Math.max(0, s.value), 0))
const segments = computed(() => {
  let offset = 0
  return props.series.map((s, i) => {
    const share = total.value ? (Math.max(0, s.value) / total.value) * 100 : 0
    const seg = { ...s, i, share, color: s.color ?? PALETTE[i % PALETTE.length], dash: `${share} ${100 - share}`, offset: 25 - offset }
    offset += share
    return seg
  })
})
const keyOf = (s: { label: string; key?: string }) => s.key ?? s.label
const pick = (i: number) => { if (props.clickable) emit('select', props.series[i], i) }
</script>

<template>
  <div v-if="series.length && total > 0" class="flex flex-col sm:flex-row items-center gap-4">
    <svg viewBox="0 0 42 42" class="h-36 w-36 shrink-0" role="img" :aria-label="series.map((s) => `${s.label}: ${number(s.value)}`).join('، ')">
      <circle cx="21" cy="21" :r="R" fill="none" stroke="var(--border)" stroke-width="5" />
      <circle
        v-for="s in segments"
        :key="keyOf(s)"
        cx="21" cy="21" :r="R" fill="none"
        :stroke="s.color" :stroke-width="selectedKey === keyOf(s) ? 6.5 : 5"
        :stroke-dasharray="s.dash" :stroke-dashoffset="s.offset"
        :class="clickable && 'cursor-pointer transition-[stroke-width] hover:opacity-90'"
        @click="pick(s.i)"
      ><title>{{ s.label }}: {{ number(s.value) }}{{ unit ?? '' }} ({{ percent(s.share) }})</title></circle>
      <text x="21" y="22.5" text-anchor="middle" class="num" style="font-size: 6px; font-weight: 600; fill: var(--ink); unicode-bidi: isolate;">{{ number(total) }}</text>
    </svg>
    <ul class="flex-1 min-w-0 space-y-1 text-sm w-full">
      <li v-for="s in segments" :key="keyOf(s)">
        <component
          :is="clickable ? 'button' : 'div'"
          :type="clickable ? 'button' : undefined"
          class="flex w-full items-center gap-2 rounded-md px-1.5 py-0.5 text-start"
          :class="clickable && ['kbd-focus hover:bg-navy-50', selectedKey === keyOf(s) && 'bg-navy-50']"
          :aria-label="clickable ? `${s.label}: ${number(s.value)} — ${$t('components.chart.drill')}` : undefined"
          @click="pick(s.i)"
        >
          <span class="h-2.5 w-2.5 rounded-full shrink-0" :style="{ backgroundColor: s.color }" aria-hidden="true" />
          <span class="truncate text-ink/90 flex-1">{{ s.label }}</span>
          <span class="num text-muted shrink-0">{{ number(s.value) }} · {{ percent(s.share) }}</span>
        </component>
      </li>
    </ul>
  </div>
  <p v-else class="text-sm text-muted">{{ $t('components.chart.noData') }}</p>
</template>

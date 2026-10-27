<script setup lang="ts">
/** Simple CSS/SVG bar chart (no chart lib). Horizontal bars with labels and values.
 *  RPT-03 — `clickable`: every bar is a button that emits `select` (drill-down to the records behind it). */
const props = withDefaults(defineProps<{ series: { label: string; value: number; color?: string; key?: string }[]; max?: number; funnel?: boolean; unit?: string; clickable?: boolean; selectedKey?: string | null }>(), { funnel: false, clickable: false, selectedKey: null })
const emit = defineEmits<{ (e: 'select', item: { label: string; value: number; key?: string }, index: number): void }>()
const { number } = useFormat()
const maxV = computed(() => props.max ?? Math.max(1, ...props.series.map((s) => s.value)))
const keyOf = (s: { label: string; key?: string }) => s.key ?? s.label
</script>

<template>
  <div v-if="series.length" class="space-y-2.5" :role="clickable ? 'group' : 'img'">
    <component
      :is="clickable ? 'button' : 'div'"
      v-for="(s, i) in series"
      :key="keyOf(s)"
      :type="clickable ? 'button' : undefined"
      class="grid w-full grid-cols-[minmax(0,1fr)_auto] items-center gap-3 text-sm text-start"
      :class="clickable && ['rounded-xl -mx-2 px-2 py-1.5 transition kbd-focus hover:bg-ink/5 cursor-pointer', selectedKey === keyOf(s) && 'bg-accent/10 ring-1 ring-accent/40']"
      :aria-label="clickable ? `${s.label}: ${number(s.value)}${unit ?? ''} — ${$t('components.chart.drill')}` : undefined"
      @click="clickable && emit('select', s, i)"
    >
      <div class="min-w-0">
        <div class="flex items-center justify-between gap-2 mb-1">
          <span class="truncate text-text">{{ s.label }}</span>
          <span class="num font-semibold text-ink shrink-0">{{ number(s.value) }}<span v-if="unit" class="ms-0.5">{{ unit }}</span></span>
        </div>
        <div class="h-2 rounded-full bg-ink/10 overflow-hidden" :class="funnel && 'mx-auto'" :style="funnel ? { width: `${100 - i * (40 / Math.max(1, series.length - 1))}%` } : undefined">
          <div class="h-full rounded-full transition-all duration-500" :style="{ width: `${Math.max(2, (s.value / maxV) * 100)}%`, backgroundColor: s.color ?? 'var(--accent-hi)' }" />
        </div>
      </div>
    </component>
  </div>
  <p v-else class="text-sm text-muted">{{ $t('components.chart.noData') }}</p>
</template>

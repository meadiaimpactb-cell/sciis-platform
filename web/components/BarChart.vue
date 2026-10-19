<script setup lang="ts">
/** Simple CSS/SVG bar chart (no chart lib). Horizontal bars with labels and values. */
const props = withDefaults(defineProps<{ series: { label: string; value: number; color?: string }[]; max?: number; funnel?: boolean; unit?: string }>(), { funnel: false })
const { number } = useFormat()
const maxV = computed(() => props.max ?? Math.max(1, ...props.series.map((s) => s.value)))
</script>

<template>
  <div v-if="series.length" class="space-y-2.5" role="img">
    <div v-for="(s, i) in series" :key="s.label" class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 text-sm">
      <div class="min-w-0">
        <div class="flex items-center justify-between gap-2 mb-1">
          <span class="truncate text-ink/90">{{ s.label }}</span>
          <span class="num text-muted shrink-0">{{ number(s.value) }}<span v-if="unit" class="ms-0.5">{{ unit }}</span></span>
        </div>
        <div class="h-2.5 rounded-full bg-bg overflow-hidden" :class="funnel && 'mx-auto'" :style="funnel ? { width: `${100 - i * (40 / Math.max(1, series.length - 1))}%` } : undefined">
          <div class="h-full rounded-full transition-all duration-500" :style="{ width: `${Math.max(2, (s.value / maxV) * 100)}%`, backgroundColor: s.color ?? 'var(--blue)' }" />
        </div>
      </div>
    </div>
  </div>
  <p v-else class="text-sm text-muted">{{ $t('components.chart.noData') }}</p>
</template>

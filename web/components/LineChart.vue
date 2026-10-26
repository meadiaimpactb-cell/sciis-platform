<script setup lang="ts">
/** RPT-03 — small SVG line chart for a trend (e.g. a report grouped by month). Points sit in order of `series`;
 *  `clickable` points emit `select`. Always drawn left→right (time axis), Latin digits via useFormat. */
const props = withDefaults(defineProps<{ series: { label: string; value: number; key?: string }[]; clickable?: boolean; unit?: string }>(), { clickable: false })
const emit = defineEmits<{ (e: 'select', item: { label: string; value: number; key?: string }, index: number): void }>()
const { number } = useFormat()
const W = 320
const H = 140
const PAD = { l: 8, r: 8, t: 14, b: 24 }
const maxV = computed(() => Math.max(1, ...props.series.map((s) => s.value)))
const pts = computed(() => props.series.map((s, i) => {
  const x = PAD.l + (props.series.length > 1 ? (i / (props.series.length - 1)) * (W - PAD.l - PAD.r) : (W - PAD.l - PAD.r) / 2)
  const y = PAD.t + (1 - s.value / maxV.value) * (H - PAD.t - PAD.b)
  return { ...s, i, x, y }
}))
const path = computed(() => pts.value.map((p, i) => `${i ? 'L' : 'M'}${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' '))
const area = computed(() => (pts.value.length ? `${path.value} L${pts.value[pts.value.length - 1].x.toFixed(1)},${H - PAD.b} L${pts.value[0].x.toFixed(1)},${H - PAD.b} Z` : ''))
// Label every n-th point so the axis never overlaps.
const step = computed(() => Math.max(1, Math.ceil(props.series.length / 6)))
</script>

<template>
  <div v-if="series.length" dir="ltr">
    <svg :viewBox="`0 0 ${W} ${H}`" class="w-full h-auto" role="img" :aria-label="series.map((s) => `${s.label}: ${number(s.value)}`).join(', ')">
      <line :x1="PAD.l" :x2="W - PAD.r" :y1="H - PAD.b" :y2="H - PAD.b" stroke="#E5E7EB" />
      <path :d="area" fill="#58B4A8" fill-opacity="0.12" />
      <path :d="path" fill="none" stroke="#24306C" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
      <g v-for="p in pts" :key="p.key ?? p.label">
        <circle :cx="p.x" :cy="p.y" r="3.5" fill="#fff" stroke="#24306C" stroke-width="2" :class="clickable && 'cursor-pointer'" :tabindex="clickable ? 0 : undefined" @click="clickable && emit('select', p, p.i)" @keydown.enter="clickable && emit('select', p, p.i)"><title>{{ p.label }}: {{ number(p.value) }}{{ unit ?? '' }}</title></circle>
        <text v-if="p.i % step === 0 || p.i === pts.length - 1" :x="p.x" :y="H - 8" text-anchor="middle" style="font-size: 9px; fill: #6B7280;">{{ p.label }}</text>
        <text v-if="series.length <= 12" :x="p.x" :y="p.y - 7" text-anchor="middle" style="font-size: 8.5px; fill: #121A3F; font-weight: 600;">{{ number(p.value) }}</text>
      </g>
    </svg>
  </div>
  <p v-else class="text-sm text-muted">{{ $t('components.chart.noData') }}</p>
</template>

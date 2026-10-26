<script setup lang="ts">
/**
 * IMP-01 — indicator values over time against the baseline and the target (plain SVG, no chart library).
 * Verified values form the line; values still awaiting verification (staff / owner views) are hollow points.
 * Every number goes through useFormat (Latin digits).
 */
import type { FollowUpIndicatorValue } from '~/types/api'

const props = defineProps<{ name: string; values: FollowUpIndicatorValue[]; baseline: number | null; target: number | null; unit?: string | null }>()
const { t } = useI18n()
const { number, date } = useFormat()

const W = 640
const H = 220
const PAD = { top: 16, end: 16, bottom: 34, start: 52 }
const points = computed(() => [...props.values].filter((v) => v.status !== 'needs_clarification').sort((a, b) => a.period_end.localeCompare(b.period_end)))
const domain = computed(() => {
  const all = [...points.value.map((p) => p.value), props.baseline, props.target].filter((v): v is number => v != null && Number.isFinite(v))
  let lo = Math.min(0, ...all)
  let hi = Math.max(...all, 1)
  if (hi === lo) hi = lo + 1
  const pad = (hi - lo) * 0.08
  return { lo: lo < 0 ? lo - pad : lo, hi: hi + pad }
})
const x = (i: number) => {
  const n = points.value.length
  const inner = W - PAD.start - PAD.end
  return n <= 1 ? PAD.start + inner / 2 : PAD.start + (inner * i) / (n - 1)
}
const y = (v: number) => PAD.top + (H - PAD.top - PAD.bottom) * (1 - (v - domain.value.lo) / (domain.value.hi - domain.value.lo))
const verifiedPath = computed(() => points.value.map((p, i) => ({ p, i })).filter(({ p }) => p.status === 'verified').map(({ p, i }) => `${x(i)},${y(p.value)}`).join(' '))
const ticks = computed(() => [0, 0.5, 1].map((f) => domain.value.lo + (domain.value.hi - domain.value.lo) * f))
const shortDate = (iso: string) => date(iso, { year: '2-digit', month: 'short' })
const summary = computed(() => t('followUp.impact.chartLabel', { name: props.name }))
</script>

<template>
  <figure v-if="points.length" class="w-full">
    <svg :viewBox="`0 0 ${W} ${H}`" class="w-full h-auto" direction="ltr" style="direction: ltr" role="img" :aria-label="summary">
      <!-- grid + y ticks -->
      <g v-for="tk in ticks" :key="tk">
        <line :x1="PAD.start" :x2="W - PAD.end" :y1="y(tk)" :y2="y(tk)" stroke="var(--border, #E5E7EB)" stroke-width="1" />
        <text :x="PAD.start - 8" :y="y(tk) + 4" text-anchor="end" class="fill-current text-muted" font-size="11">{{ number(tk, { maximumFractionDigits: 0 }) }}</text>
      </g>
      <!-- baseline / target -->
      <g v-if="baseline != null">
        <line :x1="PAD.start" :x2="W - PAD.end" :y1="y(baseline)" :y2="y(baseline)" stroke="#94A3B8" stroke-width="1.5" stroke-dasharray="4 4" />
        <text :x="W - PAD.end" :y="y(baseline) - 5" text-anchor="end" fill="#64748B" font-size="11">{{ $t('followUp.impact.baseline') }} {{ number(baseline) }}</text>
      </g>
      <g v-if="target != null">
        <line :x1="PAD.start" :x2="W - PAD.end" :y1="y(target)" :y2="y(target)" stroke="#24306C" stroke-width="1.5" stroke-dasharray="6 4" />
        <text :x="W - PAD.end" :y="y(target) - 5" text-anchor="end" fill="#24306C" font-size="11" font-weight="600">{{ $t('followUp.impact.target') }} {{ number(target) }}</text>
      </g>
      <!-- verified line -->
      <polyline v-if="verifiedPath" :points="verifiedPath" fill="none" stroke="#58B4A8" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />
      <g v-for="(p, i) in points" :key="p.id">
        <circle :cx="x(i)" :cy="y(p.value)" r="4.5" :fill="p.status === 'verified' ? '#58B4A8' : '#fff'" :stroke="p.status === 'verified' ? '#58B4A8' : '#B45309'" stroke-width="2">
          <title>{{ shortDate(p.period_end) }}: {{ number(p.value) }}{{ unit ? ` ${unit}` : '' }}</title>
        </circle>
        <text :x="x(i)" :y="H - 12" text-anchor="middle" class="fill-current text-muted" font-size="11">{{ shortDate(p.period_end) }}</text>
      </g>
    </svg>
    <figcaption class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
      <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-teal" aria-hidden="true" />{{ $t('followUp.enums.value.verified') }}</span>
      <span v-if="points.some((p) => p.status !== 'verified')" class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full border-2 border-warning" aria-hidden="true" />{{ $t('followUp.enums.value.entered') }}</span>
    </figcaption>
  </figure>
  <p v-else class="text-sm text-muted">{{ $t('followUp.impact.noValues') }}</p>
</template>

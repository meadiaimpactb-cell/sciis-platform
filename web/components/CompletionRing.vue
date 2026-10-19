<script setup lang="ts">
/** SVG completion ring (profile / project completion). */
const props = withDefaults(defineProps<{ percent: number; size?: number; stroke?: number; label?: string }>(), { size: 96, stroke: 8 })
const r = computed(() => (props.size - props.stroke) / 2)
const c = computed(() => 2 * Math.PI * r.value)
const offset = computed(() => c.value * (1 - Math.min(100, Math.max(0, props.percent)) / 100))
const color = computed(() => (props.percent >= 80 ? 'var(--success)' : props.percent >= 50 ? 'var(--blue)' : 'var(--warning)'))
const { number } = useFormat()
</script>

<template>
  <div class="relative inline-flex items-center justify-center" :style="{ width: `${size}px`, height: `${size}px` }" role="img" :aria-label="`${label ?? $t('components.ring.label')}: ${number(percent)}%`">
    <svg :width="size" :height="size" :viewBox="`0 0 ${size} ${size}`" class="-rotate-90" aria-hidden="true">
      <circle :cx="size / 2" :cy="size / 2" :r="r" fill="none" stroke="var(--border)" :stroke-width="stroke" />
      <circle :cx="size / 2" :cy="size / 2" :r="r" fill="none" :stroke="color" :stroke-width="stroke" stroke-linecap="round" :stroke-dasharray="c" :stroke-dashoffset="offset" class="transition-all duration-700" />
    </svg>
    <span class="absolute text-lg font-semibold num text-ink">{{ number(percent, { maximumFractionDigits: 0 }) }}%</span>
  </div>
</template>

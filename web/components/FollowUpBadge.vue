<script setup lang="ts">
/** PRJ / IMP / ENT-10 status badge — same look as <AppBadge>, with the follow-up status groups and their colours. */
const props = withDefaults(
  defineProps<{
    group: 'execution' | 'plan' | 'milestone' | 'report' | 'period' | 'value' | 'update'
    value: string | null | undefined
    size?: 'sm' | 'md'
    soft?: boolean
  }>(),
  { size: 'sm', soft: true },
)
const { t, te } = useI18n()
// Status hues from the theme tokens (tokens.css --hue-*), so both palettes keep AA contrast.
const GREEN = 'var(--hue-green)'
const AMBER = 'var(--hue-amber)'
const ORANGE = 'var(--hue-orange)'
const RED = 'var(--hue-red)'
const BLUE = 'var(--hue-blue)'
const GREY = 'var(--hue-gray)'
const SLATE = 'var(--hue-slate)'
const COLORS: Record<string, Record<string, string>> = {
  execution: { on_track: GREEN, delayed: AMBER, stalled: RED },
  plan: { draft: SLATE, submitted: AMBER, approved: GREEN, changes_requested: ORANGE },
  milestone: { planned: SLATE, in_progress: BLUE, done: GREEN, delayed: RED },
  report: { submitted: AMBER, accepted: GREEN, changes_requested: ORANGE },
  period: { current: BLUE, due: AMBER, overdue: RED, submitted: AMBER, accepted: GREEN, changes_requested: ORANGE },
  value: { entered: AMBER, verified: GREEN, needs_clarification: ORANGE },
  update: { pending_review: AMBER, published: GREEN, rejected: GREY },
}
const color = computed(() => (props.value ? COLORS[props.group]?.[props.value] : undefined) ?? GREY)
// Same mixes as <AppBadge> (theme-aware text on a tint; a darkened fill for the solid badge).
const textColor = computed(() => `color-mix(in srgb, ${color.value} var(--badge-text-pct), var(--badge-ink))`)
const fillColor = computed(() => `color-mix(in srgb, ${color.value} 70%, var(--badge-shade))`)
const text = computed(() => {
  if (!props.value) return t('followUp.status.notStarted')
  const key = props.group === 'execution' ? `enums.executionStatus.${props.value}` : `followUp.enums.${props.group}.${props.value}`
  return te(key) ? t(key) : props.value
})
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 rounded-full font-medium whitespace-nowrap border"
    :class="[size === 'sm' ? 'px-2.5 py-0.5 text-xs' : 'px-3 py-1 text-sm']"
    :style="soft ? { color: textColor, backgroundColor: `color-mix(in srgb, ${color} 12%, var(--badge-tint))`, borderColor: `color-mix(in srgb, ${color} 35%, var(--badge-tint))` } : { backgroundColor: fillColor, color: 'var(--badge-on-solid)', borderColor: fillColor }"
  >
    <span class="h-1.5 w-1.5 rounded-full" :style="{ backgroundColor: soft ? textColor : 'var(--badge-on-solid)' }" aria-hidden="true" />
    {{ text }}
  </span>
</template>

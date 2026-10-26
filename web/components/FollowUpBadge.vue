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
const GREEN = '#047857'
const AMBER = '#B45309'
const ORANGE = '#D97706'
const RED = '#B91C1C'
const BLUE = '#1F5BFF'
const GREY = '#6B7280'
const SLATE = '#94A3B8'
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
    :style="soft ? { color, backgroundColor: `color-mix(in srgb, ${color} 10%, white)`, borderColor: `color-mix(in srgb, ${color} 30%, white)` } : { backgroundColor: color, color: '#fff', borderColor: color }"
  >
    <span class="h-1.5 w-1.5 rounded-full" :style="{ backgroundColor: soft ? color : '#fff' }" aria-hidden="true" />
    {{ text }}
  </span>
</template>

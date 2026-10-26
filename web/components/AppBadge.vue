<script setup lang="ts">
/** Status badge: maps enum value → color + localized label. */
import { statusColor } from '~/composables/useStatusColor'

const props = withDefaults(
  defineProps<{
    kind?: 'opportunity' | 'membership' | 'interest' | 'document' | 'info' | 'meeting' | 'eval' | 'agreement' | 'generic'
    value: string
    label?: string
    size?: 'sm' | 'md'
    dot?: boolean
    soft?: boolean
  }>(),
  { kind: 'generic', size: 'sm', dot: true, soft: true },
)
const { label: enumLabel } = useEnumLabel()
const group = computed(() => ({ opportunity: 'opportunityStatus', membership: 'membershipStatus', interest: 'interestStatus', document: 'documentStatus', info: 'infoRequestStatus', meeting: 'meetingStatus', eval: 'evaluationResult', agreement: 'agreementStatus', generic: '' })[props.kind])
const text = computed(() => props.label ?? (group.value ? enumLabel(group.value, props.value) : props.value))
const color = computed(() => statusColor(props.kind, props.value))
// UX-04 — text contrast ≥4.5:1 for every status colour (light ones such as draft #94A3B8 were 2.5:1): the soft badge's
// text and the solid badge's fill are the status colour darkened; the dot keeps the exact colour.
// The portal dark theme (BRD-04) swaps the mix bases: lighter text on a dark tint (--badge-ink / --badge-tint).
const textColor = computed(() => `color-mix(in srgb, ${color.value} var(--badge-text-pct, 60%), var(--badge-ink, #000))`)
const fillColor = computed(() => `color-mix(in srgb, ${color.value} 70%, #000)`)
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 rounded-full font-medium whitespace-nowrap border"
    :class="[size === 'sm' ? 'px-2.5 py-0.5 text-xs' : 'px-3 py-1 text-sm']"
    :style="soft ? { color: textColor, backgroundColor: `color-mix(in srgb, ${color} 10%, var(--badge-tint, white))`, borderColor: `color-mix(in srgb, ${color} 30%, var(--badge-tint, white))` } : { backgroundColor: fillColor, color: '#fff', borderColor: fillColor }"
  >
    <span v-if="dot" class="h-1.5 w-1.5 rounded-full" :style="{ backgroundColor: soft ? color : '#fff' }" aria-hidden="true" />
    {{ text }}
  </span>
</template>

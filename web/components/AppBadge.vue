<script setup lang="ts">
/** Status badge: maps enum value → color + localized label. */
import { statusColor } from '~/composables/useStatusColor'

const props = withDefaults(
  defineProps<{
    kind?: 'opportunity' | 'membership' | 'interest' | 'document' | 'info' | 'meeting' | 'eval' | 'generic'
    value: string
    label?: string
    size?: 'sm' | 'md'
    dot?: boolean
    soft?: boolean
  }>(),
  { kind: 'generic', size: 'sm', dot: true, soft: true },
)
const { label: enumLabel } = useEnumLabel()
const group = computed(() => ({ opportunity: 'opportunityStatus', membership: 'membershipStatus', interest: 'interestStatus', document: 'documentStatus', info: 'infoRequestStatus', meeting: 'meetingStatus', eval: 'evaluationResult', generic: '' })[props.kind])
const text = computed(() => props.label ?? (group.value ? enumLabel(group.value, props.value) : props.value))
const color = computed(() => statusColor(props.kind, props.value))
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 rounded-full font-medium whitespace-nowrap border"
    :class="[size === 'sm' ? 'px-2.5 py-0.5 text-xs' : 'px-3 py-1 text-sm']"
    :style="soft ? { color, backgroundColor: `color-mix(in srgb, ${color} 10%, white)`, borderColor: `color-mix(in srgb, ${color} 30%, white)` } : { backgroundColor: color, color: '#fff', borderColor: color }"
  >
    <span v-if="dot" class="h-1.5 w-1.5 rounded-full" :style="{ backgroundColor: soft ? color : '#fff' }" aria-hidden="true" />
    {{ text }}
  </span>
</template>

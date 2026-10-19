<script setup lang="ts">
/** 9-state progress strip (doc §7.6) with the current position. Terminal states (not_eligible, withdrawn, closed) shown as a side note. */
import { OPPORTUNITY_STRIP_STATUSES, type OpportunityStatus } from '~/types/enums'
import { opportunityStatusColor } from '~/composables/useStatusColor'

const props = defineProps<{ status: OpportunityStatus }>()
const { label } = useEnumLabel()
const steps = OPPORTUNITY_STRIP_STATUSES
const terminal = computed(() => ['not_eligible', 'withdrawn', 'closed'].includes(props.status))
const currentIdx = computed(() => {
  const i = steps.indexOf(props.status as never)
  if (i >= 0) return i
  if (props.status === 'not_eligible') return 2
  return -1
})
</script>

<template>
  <div>
    <ol class="grid grid-cols-9 gap-1" :aria-label="$t('components.statusStrip.title')">
      <li v-for="(s, i) in steps" :key="s" class="flex flex-col items-center gap-1.5 min-w-0">
        <div class="h-2 w-full rounded-full" :style="{ backgroundColor: i <= currentIdx ? opportunityStatusColor(s) : 'var(--border)' }" aria-hidden="true" />
        <span class="text-[11px] sm:text-xs text-center leading-tight truncate w-full" :class="i === currentIdx ? 'font-semibold text-ink' : i < currentIdx ? 'text-ink/70' : 'text-muted'" :aria-current="i === currentIdx ? 'step' : undefined" :title="label('opportunityStatus', s)">{{ label('opportunityStatus', s) }}</span>
        <span v-if="i === currentIdx" class="hidden sm:inline-flex rounded-full bg-blue-soft text-blue text-[10px] px-1.5 py-0.5 whitespace-nowrap">{{ $t('components.statusStrip.youAreHere') }}</span>
      </li>
    </ol>
    <p v-if="terminal" class="mt-3 text-sm flex items-center gap-2">
      <AppBadge kind="opportunity" :value="status" />
    </p>
  </div>
</template>

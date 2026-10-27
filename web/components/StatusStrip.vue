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
    <!-- phones: the strip scrolls inside itself so the nine labels stay readable (never the page) -->
    <div class="overflow-x-auto scrollbar-thin -mx-1 px-1 pb-1">
      <ol class="grid min-w-[42rem] grid-cols-9 gap-1 md:min-w-0" :aria-label="$t('components.statusStrip.title')">
        <li v-for="(s, i) in steps" :key="s" class="flex flex-col items-center gap-1.5 min-w-0">
          <div class="h-2 w-full rounded-full" :style="{ backgroundColor: i <= currentIdx ? opportunityStatusColor(s) : 'var(--border)' }" aria-hidden="true" />
          <span class="text-xs text-center leading-tight truncate w-full" :class="i === currentIdx ? 'font-semibold text-ink' : i < currentIdx ? 'text-ink/70' : 'text-muted'" :aria-current="i === currentIdx ? 'step' : undefined" :title="label('opportunityStatus', s)">{{ label('opportunityStatus', s) }}</span>
          <span v-if="i === currentIdx" class="inline-flex rounded-full border border-accent/35 bg-accent/10 text-accent-text text-xs px-2 py-0.5 whitespace-nowrap">{{ $t('components.statusStrip.youAreHere') }}</span>
        </li>
      </ol>
    </div>
    <p v-if="terminal" class="mt-3 text-sm flex items-center gap-2">
      <AppBadge kind="opportunity" :value="status" />
    </p>
  </div>
</template>

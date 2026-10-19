<script setup lang="ts">
import type { StatusTransition } from '~/types/api'
import { opportunityStatusColor } from '~/composables/useStatusColor'
defineProps<{ items: StatusTransition[] }>()
const { label } = useEnumLabel()
const { dateTime } = useFormat()
</script>

<template>
  <ol class="relative border-s border-border ms-2 space-y-5">
    <li v-for="t in [...items].reverse()" :key="t.id" class="ms-5">
      <span class="absolute -start-[7px] mt-1.5 h-3.5 w-3.5 rounded-full border-2 border-surface" :style="{ backgroundColor: opportunityStatusColor(t.to_status) }" aria-hidden="true" />
      <div class="flex flex-wrap items-center gap-2 text-sm">
        <AppBadge v-if="t.from_status" kind="opportunity" :value="t.from_status" />
        <span v-if="t.from_status" class="text-muted">→</span>
        <AppBadge kind="opportunity" :value="t.to_status" />
      </div>
      <p class="text-xs text-muted mt-1">{{ dateTime(t.created_at) }} · {{ t.actor?.name ?? '—' }}</p>
      <p v-if="t.reason" class="text-sm text-text/85 mt-1">{{ t.reason }}</p>
    </li>
    <li v-if="!items.length" class="ms-5 text-sm text-muted">{{ $t('common.noData') }}</li>
  </ol>
</template>

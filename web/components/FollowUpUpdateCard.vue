<script setup lang="ts">
/** ENT-10 — owner update to the connected investors (text, attachment, verified-indicator snapshot). */
import type { FollowUpUpdate } from '~/types/api'

defineProps<{ update: FollowUpUpdate; downloadBase?: string | null; showStatus?: boolean }>()
defineSlots<{ actions?(): unknown }>()
const { date, number } = useFormat()
</script>

<template>
  <article class="rounded-xl border border-border p-4">
    <header class="flex flex-col sm:flex-row sm:items-start gap-3">
      <div class="flex-1 min-w-0">
        <div class="flex flex-wrap items-center gap-2">
          <FollowUpBadge v-if="showStatus" group="update" :value="update.status" />
          <span class="text-xs text-muted num">{{ update.sent_at ? $t('followUp.updates.sentAt', { date: date(update.sent_at) }) : date(update.created_at) }}</span>
        </div>
        <h3 class="mt-1.5 font-semibold">{{ update.title || $t('followUp.updates.untitled') }}</h3>
      </div>
      <div v-if="$slots.actions" class="flex flex-wrap gap-2 shrink-0"><slot name="actions" /></div>
    </header>
    <p class="mt-2 text-sm whitespace-pre-line">{{ update.body }}</p>
    <div v-if="update.metrics.length" class="mt-3">
      <p class="text-xs text-muted mb-1.5">{{ $t('followUp.updates.metrics') }}</p>
      <ul class="flex flex-wrap gap-2">
        <li v-for="m in update.metrics" :key="m.indicator_id" class="rounded-lg bg-bg px-3 py-1.5 text-xs"><span class="font-medium">{{ m.name }}</span>: <span class="num">{{ number(m.value) }}{{ m.unit ? ` ${m.unit}` : '' }}</span><span v-if="m.target != null" class="text-muted num"> / {{ number(m.target) }}</span></li>
      </ul>
    </div>
    <InlineAlert v-if="update.review_note" class="mt-3" :tone="update.status === 'rejected' ? 'warning' : 'info'" :title="$t('followUp.reports.reviewNote')">{{ update.review_note }}</InlineAlert>
    <FollowUpAttachments v-if="downloadBase && update.attachments.length" class="mt-3" :documents="update.attachments" :download-base="downloadBase" />
  </article>
</template>

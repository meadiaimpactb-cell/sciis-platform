<script setup lang="ts">
/** ADM-06 — staff file timeline: typed events (status, documents, notes, completion, meetings, nominations),
 *  newest first, with a kind filter. Fed by `timeline_events` of GET /admin/opportunities/{uuid}. */
import type { TimelineEvent, TimelineEventType } from '~/types/api'
import type { OpportunityStatus } from '~/types/enums'
import { opportunityStatusColor, statusColor } from '~/composables/useStatusColor'
const props = defineProps<{ items: TimelineEvent[] }>()
const { label } = useEnumLabel()
const { dateTime, date } = useFormat()

const GROUPS: Record<string, TimelineEventType[]> = {
  status: ['status'],
  documents: ['document_uploaded', 'document_reviewed'],
  notes: ['note'],
  completion: ['completion_requested', 'completion_resolved'],
  meetings: ['meeting_scheduled'],
  nominations: ['nomination_sent', 'nomination_responded'],
}
const group = ref('all')
const groups = computed(() => [
  { key: 'all', n: props.items.length },
  ...Object.entries(GROUPS).map(([key, types]) => ({ key, n: props.items.filter((e) => types.includes(e.type)).length })).filter((g) => g.n > 0),
])
const shown = computed(() => props.items.filter((e) => group.value === 'all' || GROUPS[group.value]?.includes(e.type)).slice().reverse())

const DOT: Record<TimelineEventType, string> = {
  status: '#24306C',
  document_uploaded: '#58B4A8',
  document_reviewed: '#1F7A5C',
  note: '#5F6888',
  completion_requested: '#B45309',
  completion_resolved: '#1F7A5C',
  meeting_scheduled: '#6D28D9',
  nomination_sent: '#0F766E',
  nomination_responded: '#0F766E',
}
const dot = (e: TimelineEvent) => (e.type === 'status' && e.data.to ? opportunityStatusColor(e.data.to as OpportunityStatus) : e.type === 'document_reviewed' && e.data.status ? statusColor('document', e.data.status) : DOT[e.type])
const itemLabel = (i: { field?: string | null; document_kind?: string | null }) => (i.document_kind ? label('documentKind', i.document_kind) : i.field ?? '')
</script>

<template>
  <div>
    <div v-if="items.length" class="mb-4 flex flex-wrap gap-1.5" role="group" :aria-label="$t('admin.timeline.filter')">
      <button v-for="g in groups" :key="g.key" type="button" class="chip text-xs" :class="group === g.key && 'chip-active'" :aria-pressed="group === g.key" @click="group = g.key">
        {{ $t(`admin.timeline.groups.${g.key}`) }} <span class="num text-muted">{{ g.n }}</span>
      </button>
    </div>
    <ol class="relative border-s border-border ms-2 space-y-5">
      <li v-for="e in shown" :key="e.id" class="ms-5">
        <span class="absolute -start-[7px] mt-1.5 h-3.5 w-3.5 rounded-full border-2 border-surface" :style="{ backgroundColor: dot(e) }" aria-hidden="true" />
        <div class="flex flex-wrap items-center gap-2 text-sm">
          <span class="text-xs font-medium text-muted">{{ $t(`admin.timeline.types.${e.type}`) }}</span>
          <template v-if="e.type === 'status'">
            <AppBadge v-if="e.data.from" kind="opportunity" :value="e.data.from" />
            <span v-if="e.data.from" class="text-muted" aria-hidden="true">→</span>
            <AppBadge v-if="e.data.to" kind="opportunity" :value="e.data.to" />
          </template>
          <template v-else-if="e.type === 'document_uploaded' || e.type === 'document_reviewed'">
            <span class="font-medium">{{ e.data.title || label('documentKind', e.data.kind) }}</span>
            <span v-if="e.data.version" class="text-xs text-muted num">v{{ e.data.version }}</span>
            <AppBadge v-if="e.type === 'document_reviewed' && e.data.status" kind="document" :value="e.data.status" />
          </template>
          <template v-else-if="e.type === 'meeting_scheduled'">
            <span class="font-medium">{{ e.data.title }}</span>
            <AppBadge v-if="e.data.status" kind="meeting" :value="e.data.status" />
          </template>
          <template v-else-if="e.type === 'nomination_sent' || e.type === 'nomination_responded'">
            <span class="font-medium">{{ e.data.investor?.name ?? '—' }}</span>
            <span v-if="e.type === 'nomination_sent' && e.data.match_score != null" class="text-xs text-muted">{{ $t('investor.detail.matchScore', { n: e.data.match_score }) }}</span>
            <AppBadge v-if="e.type === 'nomination_responded' && e.data.response" kind="generic" :value="e.data.response === 'interested' ? 'approved' : 'rejected'" :label="label('nominationResponse', e.data.response)" />
          </template>
        </div>
        <p class="text-xs text-muted mt-1"><span class="num">{{ dateTime(e.at) }}</span> · {{ e.actor?.name ?? (e.type === 'completion_resolved' ? $t('admin.audit.system') : '—') }}</p>
        <p v-if="e.type === 'status' && e.data.reason" class="text-sm text-text/85 mt-1">{{ e.data.reason }}</p>
        <p v-else-if="e.type === 'document_reviewed' && e.data.note" class="text-sm text-text/85 mt-1">{{ e.data.note }}</p>
        <p v-else-if="e.type === 'note'" class="text-sm text-text/85 mt-1 rounded-lg bg-bg p-2 leading-6">{{ e.data.body }}</p>
        <div v-else-if="e.type === 'completion_requested'" class="text-sm mt-1">
          <ul class="space-y-0.5"><li v-for="(i, n) in e.data.items ?? []" :key="n"><strong>{{ itemLabel(i) }}</strong><template v-if="i.note"> — {{ i.note }}</template></li></ul>
          <p v-if="e.data.due_at" class="text-xs text-muted mt-0.5">{{ $t('common.dueAt') }}: <span class="num">{{ date(e.data.due_at) }}</span></p>
        </div>
        <p v-else-if="e.type === 'meeting_scheduled' && e.data.starts_at" class="text-xs text-muted mt-0.5">{{ $t('admin.timeline.meetingAt') }}: <span class="num">{{ dateTime(e.data.starts_at) }}</span></p>
        <p v-else-if="e.type === 'nomination_sent' && e.data.rationale" class="text-sm text-text/85 mt-1">{{ e.data.rationale }}</p>
      </li>
      <li v-if="!shown.length" class="ms-5 text-sm text-muted">{{ $t('common.noData') }}</li>
    </ol>
  </div>
</template>

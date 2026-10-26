<script setup lang="ts">
/**
 * ENT-04 — the owner's project timeline: status changes (with reason and who acted — staff appear as "the council"),
 * completion requests (asked / completed, due date, items) and the evaluation decision, newest first.
 */
import type { CompletionRequest, EvaluationDecisionOwner, StatusTransition } from '~/types/api'
import { opportunityStatusColor } from '~/composables/useStatusColor'

const props = defineProps<{
  timeline: StatusTransition[]
  completionRequests?: CompletionRequest[]
  decision?: EvaluationDecisionOwner | null
  /** field key → localized label (from the checklist) for completion items */
  fieldLabels?: Record<string, string>
}>()
const { label } = useEnumLabel()
const { date, dateTime } = useFormat()

type JourneyEvent =
  | { kind: 'status'; key: string; at: string; transition: StatusTransition }
  | { kind: 'completion' | 'completionDone'; key: string; at: string; request: CompletionRequest }
  | { kind: 'decision'; key: string; at: string; decision: EvaluationDecisionOwner }

const events = computed<JourneyEvent[]>(() => {
  const list: JourneyEvent[] = []
  for (const t of props.timeline) {
    const at = t.created_at ?? t.at ?? ''
    if (at && t.to_status) list.push({ kind: 'status', key: `s-${t.id}`, at, transition: t })
  }
  for (const c of props.completionRequests ?? []) {
    if (c.created_at) list.push({ kind: 'completion', key: `c-${c.id}`, at: c.created_at, request: c })
    if (c.resolved_at) list.push({ kind: 'completionDone', key: `cd-${c.id}`, at: c.resolved_at, request: c })
  }
  if (props.decision?.decided_at) list.push({ kind: 'decision', key: 'decision', at: props.decision.decided_at, decision: props.decision })
  return list.sort((a, b) => b.at.localeCompare(a.at))
})
const itemLabel = (it: CompletionRequest['items'][number]) =>
  it.document_kind ? label('documentKind', it.document_kind) : (it.field ? props.fieldLabels?.[it.field] ?? it.field : '—')
const dotClass: Record<Exclude<JourneyEvent['kind'], 'status'>, string> = { completion: 'bg-warning', completionDone: 'bg-success', decision: 'bg-blue' }
</script>

<template>
  <ol class="relative border-s border-border ms-2 space-y-6">
    <li v-for="e in events" :key="e.key" class="ms-5">
      <span
        class="absolute -start-[7px] mt-1.5 h-3.5 w-3.5 rounded-full border-2 border-surface"
        :class="e.kind !== 'status' && dotClass[e.kind]"
        :style="e.kind === 'status' ? { backgroundColor: opportunityStatusColor(e.transition.to_status) } : undefined"
        aria-hidden="true"
      />
      <!-- status change -->
      <template v-if="e.kind === 'status'">
        <div class="flex flex-wrap items-center gap-2 text-sm">
          <AppBadge v-if="e.transition.from_status" kind="opportunity" :value="e.transition.from_status" />
          <svg v-if="e.transition.from_status" class="h-3.5 w-3.5 text-muted rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
          <AppBadge kind="opportunity" :value="e.transition.to_status" />
        </div>
        <p class="text-xs text-muted mt-1"><span class="num">{{ dateTime(e.at) }}</span> · {{ e.transition.actor_name ?? e.transition.actor?.name ?? $t('entrepreneur.journey.council') }}</p>
        <p v-if="e.transition.reason" class="text-sm text-text/85 mt-1">{{ e.transition.reason }}</p>
      </template>
      <!-- completion requested / completed -->
      <template v-else-if="e.kind === 'completion' || e.kind === 'completionDone'">
        <p class="text-sm font-medium">{{ $t(e.kind === 'completion' ? 'entrepreneur.journey.completionRequested' : 'entrepreneur.journey.completionDone') }}</p>
        <p class="text-xs text-muted mt-1">
          <span class="num">{{ dateTime(e.at) }}</span>
          <template v-if="e.kind === 'completion' && e.request.due_at"> · {{ $t('entrepreneur.journey.dueAt') }}: <span class="num">{{ date(e.request.due_at) }}</span></template>
        </p>
        <ul v-if="e.kind === 'completion' && e.request.items.length" class="mt-2 space-y-1 text-sm">
          <li v-for="(it, i) in e.request.items" :key="i" class="flex flex-wrap gap-x-2"><span class="font-medium">{{ itemLabel(it) }}</span><span v-if="it.note" class="text-muted">— {{ it.note }}</span></li>
        </ul>
      </template>
      <!-- evaluation decision -->
      <template v-else-if="e.kind === 'decision'">
        <div class="flex flex-wrap items-center gap-2 text-sm"><span class="font-medium">{{ $t('entrepreneur.journey.decision') }}</span><AppBadge kind="eval" :value="e.decision.result" /></div>
        <p class="text-xs text-muted mt-1 num">{{ dateTime(e.at) }}</p>
      </template>
    </li>
    <li v-if="!events.length" class="ms-5 text-sm text-muted">{{ $t('entrepreneur.journey.empty') }}</li>
  </ol>
</template>

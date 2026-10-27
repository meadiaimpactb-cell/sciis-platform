<script setup lang="ts">
/** PRJ-01 — milestone plan as a vertical timeline (deliverable, due date, responsibility, budget share, evidence). */
import type { FollowUpMilestone } from '~/types/api'

defineProps<{ milestones: FollowUpMilestone[]; downloadBase?: string | null }>()
defineSlots<{ actions?(p: { milestone: FollowUpMilestone }): unknown }>()
const { date, number } = useFormat()
const dot: Record<string, string> = { done: 'bg-success', delayed: 'bg-danger', in_progress: 'bg-blue', planned: 'bg-border' }
</script>

<template>
  <ol v-if="milestones.length" class="relative space-y-4 ps-6 before:absolute before:inset-y-2 before:start-2 before:w-px before:bg-border">
    <li v-for="m in milestones" :key="m.id" class="relative">
      <span class="absolute -start-[1.3rem] top-1.5 h-3 w-3 rounded-full ring-4 ring-surface" :class="dot[m.status] ?? 'bg-border'" aria-hidden="true" />
      <div class="rounded-2xl border p-4" :class="m.status === 'delayed' ? 'border-danger/40 bg-danger/5' : 'border-border bg-glass'">
        <div class="flex flex-col sm:flex-row sm:items-start gap-3">
          <div class="flex-1 min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <FollowUpBadge group="milestone" :value="m.status" />
              <span v-if="m.due_at" class="text-xs num" :class="m.is_overdue ? 'text-danger font-medium' : 'text-muted'">{{ m.is_overdue ? $t('followUp.milestone.overdue', { date: date(m.due_at) }) : $t('followUp.milestone.due', { date: date(m.due_at) }) }}</span>
              <span v-if="m.budget_share != null" class="text-xs text-muted num">· {{ $t('followUp.milestone.budgetShareShort', { n: number(m.budget_share) }) }}</span>
            </div>
            <h3 class="mt-1.5 text-base text-ink">{{ m.title }}</h3>
            <p v-if="m.deliverable" class="text-sm"><span class="text-muted">{{ $t('followUp.milestone.deliverable') }}:</span> {{ m.deliverable }}</p>
            <p v-if="m.responsible" class="text-sm"><span class="text-muted">{{ $t('followUp.milestone.responsible') }}:</span> {{ m.responsible }}</p>
            <p v-if="m.description" class="mt-1 text-sm text-text whitespace-pre-line">{{ m.description }}</p>
            <div v-if="m.completed_at" class="mt-2 rounded-xl border border-success/30 bg-success/10 p-2.5 text-sm">
              <p class="text-xs text-muted num">{{ $t('followUp.milestone.completedAt', { date: date(m.completed_at) }) }}</p>
              <p v-if="m.evidence_note" class="mt-0.5 whitespace-pre-line">{{ m.evidence_note }}</p>
              <FollowUpAttachments v-if="downloadBase && m.evidence.length" class="mt-2" :documents="m.evidence" :download-base="downloadBase" />
            </div>
          </div>
          <div v-if="$slots.actions" class="flex sm:flex-col gap-2 shrink-0"><slot name="actions" :milestone="m" /></div>
        </div>
      </div>
    </li>
  </ol>
  <p v-else class="text-sm text-muted">{{ $t('followUp.plan.empty') }}</p>
</template>

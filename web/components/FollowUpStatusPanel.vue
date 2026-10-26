<script setup lang="ts">
/**
 * PRJ-03 — execution status (effective = staff override or computed) with the definitions and the key follow-up
 * figures. `audience` hides the internal parts from investors (override reason, delay, pending items).
 */
import type { FollowUpDetail } from '~/types/api'

const props = defineProps<{ detail: FollowUpDetail; audience: 'staff' | 'owner' | 'investor' }>()
defineSlots<{ actions?(): unknown }>()
const { t } = useI18n()
const { date, number, percent } = useFormat()
const { label } = useEnumLabel()
const fu = computed(() => props.detail.follow_up)
const tiles = computed(() => {
  const d = props.detail
  const out: { key: string; label: string; value: string; tone?: string }[] = []
  if (d.milestones) out.push({ key: 'ms', label: t('followUp.status.milestonesDone'), value: t('followUp.admin.milestonesCount', { done: number(d.milestones.done), total: number(d.milestones.total) }), tone: d.milestones.overdue ? 'text-danger' : undefined })
  if (props.audience !== 'investor') {
    out.push({ key: 'ro', label: t('followUp.status.reportsOverdue'), value: number(d.reports_overdue ?? 0), tone: d.reports_overdue ? 'text-danger' : undefined })
    out.push({ key: 'nr', label: t('followUp.status.nextReport'), value: d.next_report_due_on ? date(d.next_report_due_on) : '—' })
  }
  if (d.impact?.primary) out.push({ key: 'ip', label: t('followUp.status.impactProgress'), value: `${percent(d.impact.primary.progress_percent)}`, tone: 'text-teal-text' })
  if (props.audience !== 'investor' && d.pending) out.push({ key: 'pd', label: t('followUp.status.pending'), value: number(d.pending.total), tone: d.pending.total ? 'text-warning' : undefined })
  if (props.audience !== 'investor' && d.connected_investors != null) out.push({ key: 'ci', label: t('followUp.status.investors'), value: number(d.connected_investors) })
  return out
})
</script>

<template>
  <SectionCard :title="$t('followUp.status.title')">
    <template v-if="$slots.actions" #actions><slot name="actions" /></template>
    <div class="flex flex-col lg:flex-row lg:items-start gap-5">
      <div class="lg:w-80 shrink-0 space-y-2">
        <div class="flex flex-wrap items-center gap-2">
          <FollowUpBadge group="execution" :value="detail.execution_status" size="md" :soft="false" />
          <AppBadge kind="opportunity" :value="detail.status" />
        </div>
        <template v-if="audience !== 'investor' && fu">
          <p v-if="fu.status_override" class="text-sm">
            <span class="font-medium text-warning">{{ $t('followUp.status.overridden') }}</span>
            <span v-if="fu.computed_status" class="text-muted"> · {{ $t('followUp.status.computed') }}: {{ label('executionStatus', fu.computed_status) }}</span>
          </p>
          <p v-if="fu.override_reason" class="text-sm"><span class="text-muted">{{ $t('followUp.status.overrideReason') }}:</span> {{ fu.override_reason }}</p>
          <p v-if="fu.override_by && fu.override_at" class="text-xs text-muted num">{{ $t('followUp.override.by', { name: fu.override_by, date: date(fu.override_at) }) }}</p>
          <p v-if="fu.delay_days" class="text-sm num" :class="fu.delay_days > (fu.stalled_after_days ?? 0) ? 'text-danger' : 'text-warning'">{{ $t('followUp.status.delay', { n: number(fu.delay_days) }) }}</p>
          <p v-if="fu.escalated_at" class="text-xs text-danger num">{{ $t('followUp.status.escalated', { date: date(fu.escalated_at) }) }}</p>
        </template>
        <p v-if="fu?.execution_started_at" class="text-xs text-muted num">{{ $t('followUp.status.startedAt') }}: {{ date(fu.execution_started_at) }}<template v-if="fu.report_frequency"> · {{ $t('followUp.status.frequency') }}: {{ label('frequency', fu.report_frequency) }}</template></p>
      </div>
      <dl v-if="tiles.length" class="flex-1 grid grid-cols-2 sm:grid-cols-3 gap-3">
        <div v-for="x in tiles" :key="x.key" class="rounded-lg bg-bg p-3"><dt class="text-xs text-muted">{{ x.label }}</dt><dd class="mt-0.5 text-lg font-semibold num" :class="x.tone">{{ x.value }}</dd></div>
      </dl>
    </div>
    <p class="mt-4 text-xs text-muted leading-6">{{ $t('followUp.status.definitions', { n: number(fu?.stalled_after_days ?? 45) }) }}</p>
  </SectionCard>
</template>

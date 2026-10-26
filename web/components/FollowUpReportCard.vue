<script setup lang="ts">
/** PRJ-02 — one progress report in the unified template (achieved, delayed and why, risks, support, spend, evidence). */
import type { FollowUpReport } from '~/types/api'

const props = defineProps<{ report: FollowUpReport; downloadBase?: string | null }>()
defineSlots<{ actions?(): unknown }>()
const { t } = useI18n()
const { date, sar } = useFormat()
const sections = computed(() => [
  { key: 'achieved', label: t('followUp.reports.achieved'), text: props.report.achieved },
  { key: 'delayed', label: t('followUp.reports.delayed'), text: props.report.delayed },
  { key: 'risks', label: t('followUp.reports.risks'), text: props.report.risks },
  { key: 'support', label: t('followUp.reports.supportNeeded'), text: props.report.support_needed },
].filter((s) => s.text))
</script>

<template>
  <article class="rounded-xl border border-border p-4">
    <header class="flex flex-col sm:flex-row sm:items-start gap-3">
      <div class="flex-1 min-w-0">
        <div class="flex flex-wrap items-center gap-2">
          <FollowUpBadge group="report" :value="report.status" />
          <span v-if="report.is_late" class="text-xs text-danger">{{ $t('followUp.reports.late') }}</span>
          <span v-if="report.submitted_at" class="text-xs text-muted num">{{ $t('followUp.reports.submittedAt', { date: date(report.submitted_at) }) }}</span>
        </div>
        <h3 class="mt-1.5 font-semibold num">{{ $t('followUp.reports.periodLabel', { start: date(report.period_start), end: date(report.period_end) }) }}</h3>
      </div>
      <div v-if="$slots.actions" class="flex flex-wrap gap-2 shrink-0"><slot name="actions" /></div>
    </header>
    <p v-if="report.narrative" class="mt-3 text-sm whitespace-pre-line">{{ report.narrative }}</p>
    <dl v-if="sections.length" class="mt-3 grid gap-3 sm:grid-cols-2 text-sm">
      <div v-for="s in sections" :key="s.key" class="rounded-lg bg-bg p-3"><dt class="text-xs text-muted mb-0.5">{{ s.label }}</dt><dd class="whitespace-pre-line">{{ s.text }}</dd></div>
    </dl>
    <div class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted">
      <span v-if="report.spend_amount != null">{{ $t('followUp.reports.spendLabel') }}: <span class="num text-ink font-medium">{{ sar(report.spend_amount) }}</span></span>
      <span v-if="report.execution_status" class="inline-flex items-center gap-1.5">{{ $t('followUp.reports.selfStatusLabel') }}: <FollowUpBadge group="execution" :value="report.execution_status" /></span>
    </div>
    <ul v-if="report.milestone_updates.length" class="mt-3 space-y-1 text-sm">
      <li v-for="u in report.milestone_updates" :key="u.milestone_id" class="flex flex-wrap items-center gap-2"><FollowUpBadge group="milestone" :value="u.status" /><span class="font-medium">{{ u.title ?? '—' }}</span><span v-if="u.note" class="text-muted">— {{ u.note }}</span></li>
    </ul>
    <InlineAlert v-if="report.review_note" class="mt-3" :tone="report.status === 'changes_requested' ? 'warning' : 'info'" :title="$t('followUp.reports.reviewNote')">{{ report.review_note }}</InlineAlert>
    <FollowUpAttachments v-if="downloadBase && report.attachments.length" class="mt-3" :documents="report.attachments" :download-base="downloadBase" />
  </article>
</template>

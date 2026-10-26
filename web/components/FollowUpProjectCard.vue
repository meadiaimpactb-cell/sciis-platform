<script setup lang="ts">
/** PRJ / INV-06 — one followed-up project in the owner's and the investor's lists. */
import type { FollowUpSummary } from '~/types/api'

const props = defineProps<{ project: FollowUpSummary; to: string; audience: 'owner' | 'investor' }>()
const { t } = useI18n()
const { term } = useEnumLabel()
const { date, number, percent } = useFormat()
const ms = computed(() => props.project.milestones)
const msPct = computed(() => (ms.value?.total ? Math.round((ms.value.done / ms.value.total) * 100) : 0))
const primary = computed(() => props.project.impact?.primary ?? null)
const impactPct = computed(() => Math.max(0, Math.min(100, primary.value?.progress_percent ?? 0)))
const sector = computed(() => (props.project.sector ? term(props.project.sector as never) : null))
const ownerActions = computed(() => {
  const p = props.project
  const out: string[] = []
  if (p.plan_status === 'draft' || p.plan_status === 'changes_requested') out.push(t('followUp.owner.todo.plan'))
  if (p.reports_overdue) out.push(t('followUp.owner.todo.reportsOverdue', { n: number(p.reports_overdue) }))
  else if (p.next_report_due_on) out.push(t('followUp.owner.todo.nextReport', { date: date(p.next_report_due_on) }))
  return out
})
</script>

<template>
  <article class="card card-pad">
    <div class="flex flex-col lg:flex-row lg:items-start gap-5">
      <div class="flex-1 min-w-0">
        <div class="flex flex-wrap items-center gap-2">
          <FollowUpBadge v-if="project.in_follow_up" group="execution" :value="project.execution_status" size="md" />
          <AppBadge kind="opportunity" :value="project.status" />
          <FollowUpBadge v-if="audience === 'owner' && project.plan_status" group="plan" :value="project.plan_status" />
        </div>
        <h2 class="mt-2 text-lg font-semibold"><NuxtLink :to="$localePath(to)" class="hover:text-blue">{{ project.name }}</NuxtLink></h2>
        <p class="text-sm text-muted">{{ [sector, project.reference_no].filter(Boolean).join(' · ') }}</p>
        <p v-if="!project.in_follow_up" class="mt-3 text-sm text-muted">{{ $t('followUp.investor.notStarted') }}</p>
        <ul v-if="audience === 'owner' && ownerActions.length" class="mt-3 space-y-1 text-sm">
          <li v-for="a in ownerActions" :key="a" class="flex items-center gap-2"><span class="h-1.5 w-1.5 rounded-full bg-warning" aria-hidden="true" />{{ a }}</li>
        </ul>
        <p v-if="project.latest_report" class="mt-3 text-sm"><span class="text-muted">{{ $t('followUp.investor.latestReport') }} ({{ date(project.latest_report.period_end) }}):</span> {{ project.latest_report.excerpt }}</p>
        <p v-if="project.latest_update" class="mt-1.5 text-sm"><span class="text-muted">{{ $t('followUp.investor.latestUpdate') }}:</span> {{ project.latest_update.title || project.latest_update.excerpt }}</p>
      </div>
      <dl class="lg:w-72 shrink-0 grid grid-cols-2 lg:grid-cols-1 gap-3 text-sm">
        <div v-if="ms" class="rounded-lg bg-bg p-3">
          <dt class="text-xs text-muted">{{ $t('followUp.status.milestonesDone') }}</dt>
          <dd class="mt-0.5 font-semibold num">{{ $t('followUp.admin.milestonesCount', { done: number(ms.done), total: number(ms.total) }) }}<span v-if="ms.overdue" class="ms-2 text-xs text-danger font-medium">{{ $t('followUp.admin.overdueCount', { n: number(ms.overdue) }) }}</span></dd>
          <div class="mt-2 h-1.5 rounded-full bg-surface overflow-hidden"><div class="h-full rounded-full bg-navy" :style="{ width: `${msPct}%` }" /></div>
        </div>
        <div v-if="primary" class="rounded-lg bg-bg p-3">
          <dt class="text-xs text-muted truncate" :title="primary.name">{{ primary.name }}</dt>
          <dd class="mt-0.5 font-semibold num">{{ percent(primary.progress_percent) }}<span v-if="primary.latest_verified" class="ms-2 text-xs text-muted font-normal">{{ number(primary.latest_verified.value) }} / {{ number(primary.target) }}</span></dd>
          <div class="mt-2 h-1.5 rounded-full bg-surface overflow-hidden"><div class="h-full rounded-full bg-teal" :style="{ width: `${impactPct}%` }" /></div>
        </div>
      </dl>
    </div>
    <div class="mt-4 flex justify-end"><AppButton size="sm" variant="secondary" :to="to">{{ $t('followUp.owner.open') }}</AppButton></div>
  </article>
</template>

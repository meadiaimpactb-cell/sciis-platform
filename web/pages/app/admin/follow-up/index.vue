<script setup lang="ts">
/**
 * Follow-up & impact (impact.verify) — projects in negotiation / execution / operation: execution status (PRJ-03),
 * milestone plan (PRJ-01), overdue reports (PRJ-02) and items awaiting a decision (plans, reports, indicator values,
 * held updates). GET /admin/follow-up {search, execution, status, needs_action} → { data, meta.stats }.
 */
import type { FollowUpStats, FollowUpSummary } from '~/types/api'
import { EXECUTION_STATUSES } from '~/types/enums'
definePageMeta({ layout: 'portal', middleware: ['role'] })
const { t } = useI18n()
useHead({ title: t('followUp.admin.metaTitle') })
const api = useApi()
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const { label } = useEnumLabel()
const { date, number } = useFormat()
const allowed = computed(() => auth.canAny('impact.verify'))
const qv = (k: string) => (typeof route.query[k] === 'string' ? (route.query[k] as string) : '')
const search = ref(qv('search'))
const execution = ref(qv('execution'))
const status = ref(qv('status'))
const needsAction = ref(route.query.needs_action === '1')
const rows = ref<FollowUpSummary[]>([])
const stats = ref<FollowUpStats | null>(null)
const loading = ref(false)
const load = async () => {
  if (!allowed.value) return
  loading.value = true
  try {
    const q: Record<string, unknown> = {}
    if (search.value.trim()) q.search = search.value.trim()
    if (execution.value) q.execution = execution.value
    if (status.value) q.status = status.value
    if (needsAction.value) q.needs_action = '1'
    const r = await api.get<{ data: FollowUpSummary[]; meta: { stats: FollowUpStats } }>('/admin/follow-up', q)
    rows.value = r.data ?? []
    stats.value = r.meta?.stats ?? null
  } finally { loading.value = false }
}
const syncUrl = () => router.replace({ query: { ...(search.value.trim() ? { search: search.value.trim() } : {}), ...(execution.value ? { execution: execution.value } : {}), ...(status.value ? { status: status.value } : {}), ...(needsAction.value ? { needs_action: '1' } : {}) } })
onMounted(load)
watch([execution, status, needsAction], () => { syncUrl(); load() })
let st: ReturnType<typeof setTimeout>
watch(search, () => { clearTimeout(st); st = setTimeout(() => { syncUrl(); load() }, 300) })
const followUpStatuses = ['negotiating', 'in_execution', 'operating'] as const
const columns = [
  { key: 'name', label: t('followUp.admin.columns.project') },
  { key: 'status', label: t('followUp.admin.columns.stage') },
  { key: 'execution_status', label: t('followUp.admin.columns.execution') },
  { key: 'plan_status', label: t('followUp.admin.columns.plan') },
  { key: 'milestones', label: t('followUp.admin.columns.milestones') },
  { key: 'reports_overdue', label: t('followUp.admin.columns.reports'), sortable: true },
  { key: 'pending', label: t('followUp.admin.columns.pending') },
]
const pendingTitle = (r: FollowUpSummary) => (r.pending ? t('followUp.admin.pendingBreakdown', { plan: number(r.pending.plan), reports: number(r.pending.reports), values: number(r.pending.indicator_values), updates: number(r.pending.updates) }) : '')
</script>

<template>
  <div>
    <PageHeader :title="$t('followUp.admin.title')" :sub="$t('followUp.admin.sub')" />
    <EmptyState v-if="!allowed" :title="$t('portal.roleGate.title')" :text="$t('portal.roleGate.text')" />
    <template v-else>
      <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 mb-5">
        <StatCard :label="$t('followUp.admin.stats.total')" :value="number(stats?.total ?? 0)" />
        <StatCard :label="$t('enums.executionStatus.on_track')" :value="number(stats?.on_track ?? 0)" tone="success" />
        <StatCard :label="$t('enums.executionStatus.delayed')" :value="number(stats?.delayed ?? 0)" tone="warning" />
        <StatCard :label="$t('enums.executionStatus.stalled')" :value="number(stats?.stalled ?? 0)" tone="danger" />
        <StatCard :label="$t('followUp.admin.stats.overdueReports')" :value="number(stats?.overdue_reports ?? 0)" tone="danger" />
        <StatCard :label="$t('followUp.admin.stats.pending')" :value="number(stats?.pending_verifications ?? 0)" tone="teal" />
      </div>
      <div class="card p-3 mb-4 grid sm:grid-cols-2 lg:grid-cols-4 gap-3 items-center">
        <input v-model="search" type="search" class="input" :placeholder="$t('common.searchPlaceholder')" :aria-label="$t('common.search')" />
        <select v-model="execution" class="input" :aria-label="$t('followUp.admin.filters.execution')"><option value="">{{ $t('followUp.admin.filters.execution') }}: {{ $t('common.all') }}</option><option v-for="s in EXECUTION_STATUSES" :key="s" :value="s">{{ label('executionStatus', s) }}</option></select>
        <select v-model="status" class="input" :aria-label="$t('followUp.admin.filters.stage')"><option value="">{{ $t('followUp.admin.filters.stage') }}: {{ $t('common.all') }}</option><option v-for="s in followUpStatuses" :key="s" :value="s">{{ label('opportunityStatus', s) }}</option></select>
        <label class="inline-flex items-center gap-2 text-sm text-ink cursor-pointer"><input v-model="needsAction" type="checkbox" class="h-4 w-4 rounded border-border accent-accent" />{{ $t('followUp.admin.filters.needsAction') }}</label>
      </div>
      <DataTable :columns="columns" :rows="rows" row-key="uuid" :loading="loading && !rows.length" :empty-text="$t('followUp.admin.noProjects')">
        <template #cell-name="{ row }">
          <NuxtLink :to="$localePath(`/app/admin/follow-up/${row.uuid}`)" class="font-medium hover:text-blue">{{ row.name }}</NuxtLink>
          <span class="block text-xs text-muted"><span class="num">{{ row.reference_no ?? '—' }}</span><template v-if="row.owner?.name"> · {{ row.owner.name }}</template></span>
        </template>
        <template #cell-status="{ value }"><AppBadge kind="opportunity" :value="value" /></template>
        <template #cell-execution_status="{ row }">
          <div class="flex flex-col items-start gap-1">
            <FollowUpBadge group="execution" :value="row.execution_status" />
            <span v-if="row.status_overridden" class="text-xs text-warning">{{ $t('followUp.status.overridden') }}</span>
            <span v-if="row.escalated_at" class="text-xs text-danger num">{{ $t('followUp.status.escalated', { date: date(row.escalated_at) }) }}</span>
          </div>
        </template>
        <template #cell-plan_status="{ value }"><FollowUpBadge group="plan" :value="value" /></template>
        <template #cell-milestones="{ row }">
          <span v-if="row.milestones" class="num whitespace-nowrap">{{ $t('followUp.admin.milestonesCount', { done: number(row.milestones.done), total: number(row.milestones.total) }) }}</span>
          <span v-else class="text-muted">—</span>
          <span v-if="row.milestones?.overdue" class="block text-xs text-danger num">{{ $t('followUp.admin.overdueCount', { n: number(row.milestones.overdue) }) }}</span>
        </template>
        <template #cell-reports_overdue="{ row }">
          <span v-if="row.reports_overdue" class="text-danger font-medium num">{{ $t('followUp.admin.overdueCount', { n: number(row.reports_overdue) }) }}</span>
          <span v-else class="text-muted">—</span>
          <span v-if="row.next_report_due_on" class="block text-xs text-muted num">{{ $t('followUp.admin.nextDue', { date: date(row.next_report_due_on) }) }}</span>
        </template>
        <template #cell-pending="{ row }">
          <span v-if="row.pending?.total" class="inline-flex items-center rounded-full border border-warning/30 bg-warning/10 px-2.5 py-0.5 text-xs font-medium text-warning num" :title="pendingTitle(row)">{{ number(row.pending.total) }}</span>
          <span v-else class="text-muted">—</span>
        </template>
      </DataTable>
    </template>
  </div>
</template>

<script setup lang="ts">
/** Admin dashboard — doc §7.7 (ADM-01..07, EVL-03, MAT-02) */
import type { DashboardAdmin, QueueItem } from '~/types/api'
import { opportunityStatusColor } from '~/composables/useStatusColor'
definePageMeta({ layout: 'portal', middleware: ['role'] })
const { t } = useI18n()
useHead({ title: t('admin.dashboard.metaTitle') })
const api = useApi()
const auth = useAuthStore()
const route = useRoute()
const toast = useToast()
const { label, term } = useEnumLabel()
const { number, date, relative } = useFormat()
const { data, loading, refresh } = useAsyncResource(() => api.get<{ data: DashboardAdmin }>('/admin/dashboard').then((r) => r.data))
const queueTab = ref('all')
const queueTabs = computed(() => {
  const q = data.value?.queue ?? []
  return ['all', 'new', 'under_review', 'needs_completion', 'eligible', 'overdue'].map((k) => ({ key: k, label: t(`admin.dashboard.queueTabs.${k}`), count: k === 'all' ? q.length : k === 'overdue' ? q.filter((x) => x.is_overdue).length : q.filter((x) => x.status === k).length }))
})
const queue = computed(() => (data.value?.queue ?? []).filter((q) => queueTab.value === 'all' || (queueTab.value === 'overdue' ? q.is_overdue : q.status === queueTab.value)))
const pipeline = computed(() => (data.value?.pipeline ?? []).map((p) => ({ label: label('opportunityStatus', p.status), value: p.count, color: opportunityStatusColor(p.status) })))
const panel = computed(() => data.value?.evaluation_focus?.panel)
const axes = ['readiness', 'feasibility', 'impact'] as const
const busy = ref<string | null>(null)
const raise = async () => {
  if (!data.value?.evaluation_focus) return
  busy.value = 'raise'
  try {
    const recs = panel.value?.assignments.map((a) => a.evaluation?.recommendation).filter(Boolean)
    const result = recs?.every((r) => r === 'eligible') ? 'eligible' : recs?.some((r) => r === 'not_eligible_now') ? 'not_eligible_now' : 'eligible_with_conditions'
    await api.post(`/admin/opportunities/${data.value.evaluation_focus.opportunity.uuid}/evaluation/decide`, { result, conditions: panel.value?.assignments.map((a) => a.evaluation?.comment).filter(Boolean).join(' / ') })
    toast.success(t('common.success')); await refresh()
  } catch (e) { toast.error((e as Error).message) } finally { busy.value = null }
}
const requestCompletion = async () => {
  if (!data.value?.evaluation_focus) return
  busy.value = 'completion'
  try {
    const items = panel.value?.assignments.flatMap((a) => Object.entries(a.evaluation?.scores ?? {}).filter(([, s]) => s.note).map(([k, s]) => ({ field: k, note: s.note }))) ?? []
    await api.post(`/admin/opportunities/${data.value.evaluation_focus.opportunity.uuid}/completion-requests`, { items: items.length ? items : [{ field: 'general', note: 'استكمال وفق ملاحظات المقيّمين' }], reason: 'طلب استكمال بعد التقييم' })
    toast.success(t('common.success')); await refresh()
  } catch (e) { toast.error((e as Error).message) } finally { busy.value = null }
}
const sendSummary = async (uuid: string) => {
  if (!data.value?.candidates_focus) return
  busy.value = uuid
  try { await api.post(`/admin/opportunities/${data.value.candidates_focus.opportunity.uuid}/nominations`, { investor_user_ids: [uuid], rationale: 'ترشيح من لوحة الإدارة وفق المعايير المطابقة' }); toast.success(t('admin.dashboard.summarySent')); await refresh() } catch (e) { toast.error((e as Error).message) } finally { busy.value = null }
}
const exportExcel = async () => { try { const r = await api.get<{ url: string; note?: string }>('/admin/reports/opportunities_funnel/export.xlsx'); toast.info(r.note ?? r.url) } catch (e) { toast.error((e as Error).message) } }
const slaClass = (q: QueueItem) => (q.is_overdue ? 'text-danger font-medium' : (q.sla_days_left ?? 99) <= 2 ? 'text-warning font-medium' : 'text-muted')
const criteria5 = ['sector', 'stage', 'region', 'type', 'range'] as const
</script>

<template>
  <div>
    <PageHeader :title="$t('admin.dashboard.title')" :eyebrow="$t('portal.admin')" :sub="auth.roles.map((r) => label('role', r)).join('، ')">
      <template #actions><AppButton variant="secondary" size="sm" @click="exportExcel"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3v12m0 0l-4-4m4 4l4-4M4 17v3h16v-3" /></svg>{{ $t('admin.dashboard.export') }}</AppButton></template>
    </PageHeader>
    <InlineAlert v-if="route.query.gate === 'role'" tone="warning" :title="$t('portal.roleGate.title')" class="mb-6">{{ $t('portal.roleGate.text') }}</InlineAlert>
    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <div v-else-if="data" class="space-y-6">
      <!-- today counters -->
      <section>
        <h2 class="text-sm font-medium text-muted mb-3">{{ $t('admin.dashboard.today') }} · {{ date(new Date().toISOString()) }}</h2>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
          <StatCard :label="$t('admin.dashboard.counters.new')" :value="number(data.today.new)" tone="blue" to="/app/admin/opportunities?status=new" />
          <StatCard :label="$t('admin.dashboard.counters.overdue')" :value="number(data.today.overdue)" :tone="data.today.overdue ? 'danger' : 'default'" to="/app/admin/opportunities?overdue=1" />
          <StatCard :label="$t('admin.dashboard.counters.pending_memberships')" :value="number(data.today.pending_memberships)" tone="warning" to="/app/admin/memberships" />
          <StatCard :label="$t('admin.dashboard.counters.interest_rate')" :value="`${number(data.today.interest_rate)}%`" tone="success" to="/app/admin/reports" />
        </div>
      </section>

      <div class="grid lg:grid-cols-3 gap-6">
        <SectionCard :title="$t('admin.dashboard.pipeline')"><BarChart :series="pipeline" /></SectionCard>
        <SectionCard :title="$t('admin.dashboard.membershipRequests')" class="lg:col-span-2" flush>
          <table class="table-base"><thead><tr><th>{{ $t('admin.memberships.columns.name') }}</th><th>{{ $t('admin.memberships.columns.type') }}</th><th>{{ $t('common.status') }}</th><th>{{ $t('admin.memberships.columns.completion') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="m in data.membership_requests" :key="m.id"><td class="font-medium">{{ m.user?.name }}</td><td class="text-muted">{{ label('userType', m.user?.type) }}</td><td><AppBadge kind="membership" :value="m.status" /></td><td class="num">{{ number(m.profile?.completion_percent) }}%</td><td class="text-end"><AppButton size="sm" variant="outline" :to="`/app/admin/memberships/${m.id}`">{{ $t('common.view') }}</AppButton></td></tr>
              <tr v-if="!data.membership_requests.length"><td colspan="5" class="text-center text-muted py-6">{{ $t('common.noData') }}</td></tr>
            </tbody></table>
        </SectionCard>
      </div>

      <!-- work queue -->
      <SectionCard :title="$t('admin.dashboard.queue')" flush>
        <div class="px-4 pt-2"><Tabs v-model="queueTab" :tabs="queueTabs" /></div>
        <div class="overflow-x-auto"><table class="table-base">
          <thead><tr><th>{{ $t('admin.opportunities.columns.ref') }}</th><th>{{ $t('admin.opportunities.columns.name') }}</th><th>{{ $t('common.status') }}</th><th>{{ $t('admin.dashboard.owner') }}</th><th>{{ $t('admin.dashboard.assignee') }}</th><th>{{ $t('admin.dashboard.sla') }}</th><th>{{ $t('common.updatedAt') }}</th></tr></thead>
          <tbody>
            <tr v-for="q in queue" :key="q.uuid"><td class="num text-muted">{{ q.reference_no }}</td><td><NuxtLink :to="$localePath(`/app/admin/opportunities/${q.uuid}`)" class="font-medium hover:text-blue">{{ q.name }}</NuxtLink><span class="block text-xs text-muted">{{ term(q.sector) }}</span></td><td><AppBadge kind="opportunity" :value="q.status" /></td><td class="text-muted">{{ q.owner_name }}</td><td class="text-muted">{{ q.assignee?.name ?? '—' }}</td><td :class="slaClass(q)">{{ q.sla_days_left == null ? '—' : q.is_overdue ? $t('common.overdueBy', { n: Math.abs(q.sla_days_left) }) : $t('common.daysLeft', { n: q.sla_days_left }) }}</td><td class="text-muted whitespace-nowrap">{{ relative(q.updated_at) }}</td></tr>
            <tr v-if="!queue.length"><td colspan="7" class="text-center text-muted py-6">{{ $t('common.noData') }}</td></tr>
          </tbody></table></div>
      </SectionCard>

      <div class="grid xl:grid-cols-2 gap-6">
        <!-- evaluation panel -->
        <SectionCard :title="$t('admin.dashboard.evaluationPanel')" :sub="data.evaluation_focus ? `${data.evaluation_focus.opportunity.name} · ${data.evaluation_focus.opportunity.reference_no}` : undefined">
          <template #actions><AppButton v-if="data.evaluation_focus" variant="ghost" size="sm" :to="`/app/admin/opportunities/${data.evaluation_focus.opportunity.uuid}?tab=evaluation`">{{ $t('common.details') }}</AppButton></template>
          <template v-if="panel && panel.assignments.length === 2">
            <div class="overflow-x-auto"><table class="table-base text-sm">
              <thead><tr><th>{{ $t('admin.dashboard.axis') }}</th><th v-for="(a, i) in panel.assignments" :key="a.id">{{ $t('admin.dashboard.evaluator', { n: i + 1 }) }}<span class="block text-[10px] font-normal normal-case text-muted">{{ a.evaluator.name }}</span></th><th>{{ $t('admin.opportunity.diff') }}</th></tr></thead>
              <tbody>
                <tr v-for="ax in axes" :key="ax"><td class="font-medium">{{ label('evaluationAxis', ax) }}</td><td v-for="a in panel.assignments" :key="a.id" class="num">{{ number(a.evaluation?.axis_totals[ax]) }} / 5</td><td class="num text-muted">{{ number(Math.abs((panel.assignments[0].evaluation?.axis_totals[ax] ?? 0) - (panel.assignments[1].evaluation?.axis_totals[ax] ?? 0))) }}</td></tr>
                <tr class="bg-bg/60"><td class="font-semibold">{{ $t('admin.dashboard.overall') }}</td><td v-for="a in panel.assignments" :key="a.id" class="num font-semibold">{{ number(a.evaluation?.overall) }}</td><td class="num">{{ number(Math.abs((panel.assignments[0].evaluation?.overall ?? 0) - (panel.assignments[1].evaluation?.overall ?? 0))) }}</td></tr>
                <tr><td class="font-medium">{{ $t('admin.dashboard.recommendation') }}</td><td v-for="a in panel.assignments" :key="a.id"><AppBadge v-if="a.evaluation" kind="eval" :value="a.evaluation.recommendation" /></td><td /></tr>
              </tbody></table></div>
            <ul v-if="panel.diffs.length" class="mt-3 text-xs text-warning space-y-1"><li v-for="d in panel.diffs" :key="d.criterion_key">{{ $t('admin.opportunity.diff') }} ≥ 2: {{ panel.criteria.find((c) => c.key === d.criterion_key)?.label_ar }}</li></ul>
            <div v-if="panel.decision" class="mt-4"><AppBadge kind="eval" :value="panel.decision.result" size="md" /> <span class="text-xs text-muted">{{ panel.decision.decided_by.name }} · {{ date(panel.decision.decided_at) }}</span></div>
            <div v-else class="mt-4 flex flex-wrap gap-2"><AppButton :loading="busy === 'raise'" @click="raise">{{ $t('admin.dashboard.raiseForApproval') }}</AppButton><AppButton variant="secondary" :loading="busy === 'completion'" @click="requestCompletion">{{ $t('admin.dashboard.requestCompletion') }}</AppButton></div>
          </template>
          <p v-else class="text-sm text-muted">{{ $t('admin.opportunity.noEvaluation') }}</p>
        </SectionCard>

        <!-- candidates -->
        <SectionCard :title="$t('admin.dashboard.candidates')" :sub="data.candidates_focus ? `${data.candidates_focus.opportunity.name} — ${$t('admin.dashboard.candidatesSub')}` : $t('admin.dashboard.candidatesSub')">
          <ul v-if="data.candidates_focus" class="divide-y divide-border">
            <li v-for="c in data.candidates_focus.candidates" :key="c.investor.uuid" class="py-3 flex flex-col sm:flex-row sm:items-center gap-3">
              <div class="flex-1 min-w-0">
                <p class="font-medium">{{ c.investor.name }} <span class="text-xs text-muted">· {{ label('userType', c.investor.type) }}</span></p>
                <ul class="mt-1 flex flex-wrap gap-1"><li v-for="k in criteria5" :key="k" class="text-[11px] rounded-full px-2 py-0.5 border" :class="c.matched_criteria[k] ? 'bg-success/10 text-success border-success/30' : 'bg-bg text-muted border-border'">{{ $t(`investor.nominations.criteria.${k}`) }}</li></ul>
              </div>
              <div class="flex items-center gap-3 shrink-0"><span class="num text-lg font-semibold text-blue">{{ c.match_score }}/5</span><AppButton v-if="!c.already_nominated" size="sm" :loading="busy === c.investor.uuid" @click="sendSummary(c.investor.uuid)">{{ $t('admin.dashboard.sendSummary') }}</AppButton><AppBadge v-else kind="generic" value="approved" :label="$t('admin.dashboard.summarySent')" /></div>
            </li>
          </ul>
          <p v-else class="text-sm text-muted">{{ $t('common.noData') }}</p>
        </SectionCard>
      </div>

      <SectionCard v-if="data.my_tasks.length" :title="$t('admin.dashboard.myTasks')">
        <ul class="divide-y divide-border"><li v-for="tk in data.my_tasks" :key="tk.id" class="py-2.5 flex items-center justify-between gap-3 text-sm"><NuxtLink :to="$localePath(tk.href)" class="hover:text-blue">{{ tk.title }}</NuxtLink><span class="text-xs text-muted">{{ tk.due_at ? date(tk.due_at) : '' }}</span></li></ul>
      </SectionCard>
    </div>
  </div>
</template>

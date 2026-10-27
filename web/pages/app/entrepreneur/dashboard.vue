<script setup lang="ts">
/** Entrepreneur dashboard — doc §7.6 (ENT-04..09) */
import type { DashboardEntrepreneur, Interest, RequiredNowItem } from '~/types/api'
import { confirmDialog, toastApiError, toastInfo, toastSuccess } from '~/utils/notify'
definePageMeta({ layout: 'portal', middleware: ['entrepreneur'] })
const { t } = useI18n()
useHead({ title: t('entrepreneur.dashboard.metaTitle') })
const api = useApi()
const auth = useAuthStore()
const router = useRouter()
const localePath = useLocalePath()
const { label } = useEnumLabel()
const { date, daysUntil, number } = useFormat()
const { data, loading, refresh } = useAsyncResource(() => api.get<{ data: DashboardEntrepreneur }>('/entrepreneur/dashboard').then((r) => r.data))
const opportunities = computed(() => data.value?.opportunities ?? [])
const primary = computed(() => opportunities.value.find((o) => !['withdrawn', 'closed'].includes(o.status)) ?? opportunities.value[0] ?? null)
const requiredNow = computed(() => data.value?.required_now ?? [])
const documents = computed(() => data.value?.documents ?? [])
const accessRequests = computed(() => data.value?.access_requests ?? [])
const actionHref = (it: RequiredNowItem) => {
  if (it.action === 'decide_access') return '/app/entrepreneur/access-requests'
  if (it.action === 'answer_info') return '/app/entrepreneur/info-requests'
  if (it.action === 'approve_teaser') return `/app/entrepreneur/opportunities/${it.opportunity_uuid}/preview`
  if (it.action === 'upload') return `/app/entrepreneur/opportunities/${it.opportunity_uuid}/edit?step=documents`
  return `/app/entrepreneur/opportunities/${it.opportunity_uuid}/edit`
}
const busy = ref<string | null>(null)
const entity = (k: string) => t(`notify.entities.${k}`)
const decide = async (i: Interest, decision: 'approve' | 'decline') => {
  if (decision === 'decline') {
    const name = i.investor?.name ?? t('entrepreneur.dashboard.investorLabel')
    if (!(await confirmDialog(t('notify.declineTitle'), t('notify.declineText', { name }), t('notify.declineConfirm')))) return
  }
  busy.value = `${decision}-${i.id}`
  try {
    await api.post(`/entrepreneur/access-requests/${i.id}/decide`, { decision })
    if (decision === 'approve') toastSuccess(t('notify.approved', { item: entity('accessRequest') }))
    else toastInfo(t('notify.rejectedNotified', { item: entity('accessRequest') }))
    await refresh()
  } catch (e) { toastApiError(e) } finally { busy.value = null }
}
const withdraw = async () => {
  const o = primary.value
  if (!o || !(await confirmDialog(t('notify.withdrawTitle'), t('notify.withdrawText', { name: o.name }), t('notify.withdrawConfirm')))) return
  busy.value = 'withdraw'
  try {
    await api.post(`/entrepreneur/opportunities/${o.uuid}/withdraw`)
    toastSuccess(t('notify.withdrawn', { item: entity('project') }))
    await refresh()
  } catch (e) { toastApiError(e) } finally { busy.value = null }
}
/** MTG-02 — a meeting follow-up action assigned to the owner is marked done right from "required now". */
const markActionDone = async (it: RequiredNowItem) => {
  if (!it.meeting_action_id) return
  busy.value = `action-${it.id}`
  try {
    await api.patch(`/meeting-actions/${it.meeting_action_id}`, { done: true })
    toastSuccess(t('notify.markedDone', { item: entity('meetingAction') }))
    await refresh()
  } catch (e) { toastApiError(e) } finally { busy.value = null }
}
const createProject = async () => {
  busy.value = 'create'
  try {
    const r = await api.post<{ data: { uuid: string } }>('/entrepreneur/opportunities', { name: t('entrepreneur.preview.defaultName') })
    toastSuccess(t('notify.created', { item: entity('project') }))
    router.push(localePath(`/app/entrepreneur/opportunities/${r.data.uuid}/edit`))
  } catch (e) { toastApiError(e) } finally { busy.value = null }
}
</script>

<template>
  <div>
    <PageHeader :title="$t('entrepreneur.dashboard.welcome', { name: auth.me?.name })" :eyebrow="$t('portal.entrepreneur')">
      <template #actions>
        <AppButton v-if="primary && !['connected', 'negotiating', 'in_execution', 'operating', 'closed', 'withdrawn'].includes(primary.status)" variant="ghost" size="sm" :loading="busy === 'withdraw'" @click="withdraw">{{ $t('entrepreneur.dashboard.withdraw') }}</AppButton>
        <AppButton v-if="primary" variant="secondary" size="sm" :to="`/app/entrepreneur/opportunities/${primary.uuid}/preview`">{{ $t('entrepreneur.dashboard.fullPreview') }}</AppButton>
        <AppButton v-if="primary" size="sm" :to="`/app/entrepreneur/opportunities/${primary.uuid}/edit`">{{ $t('entrepreneur.dashboard.editProject') }}</AppButton>
        <AppButton v-else size="sm" :loading="busy === 'create'" @click="createProject">{{ $t('entrepreneur.dashboard.createProject') }}</AppButton>
      </template>
    </PageHeader>
    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <template v-else-if="data">
      <EmptyState v-if="!primary" :title="$t('entrepreneur.dashboard.noProject')"><AppButton :loading="busy === 'create'" @click="createProject">{{ $t('entrepreneur.dashboard.createProject') }}</AppButton></EmptyState>
      <div v-else class="grid gap-6 lg:grid-cols-3">
        <!-- progress strip -->
        <SectionCard :title="$t('entrepreneur.dashboard.progress')" class="lg:col-span-3">
          <template #actions><AppButton v-if="['negotiating', 'in_execution', 'operating'].includes(primary.status)" size="sm" :to="`/app/entrepreneur/follow-up/${primary.uuid}`">{{ $t('followUp.owner.open') }}</AppButton><AppButton size="sm" variant="outline" :to="`/app/entrepreneur/opportunities/${primary.uuid}`">{{ $t('entrepreneur.journey.open') }}</AppButton></template>
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
            <div class="flex flex-wrap items-center gap-3">
              <h3 class="font-display text-lg font-bold text-ink">{{ primary.name }}</h3>
              <AppBadge kind="opportunity" :value="primary.status" size="md" :soft="false" />
            </div>
            <dl class="flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted">
              <div v-if="primary.reference_no"><dt class="inline">{{ $t('entrepreneur.dashboard.referenceNo') }}:</dt> <dd class="inline num text-ink font-medium">{{ primary.reference_no }}</dd></div>
              <div v-if="primary.submitted_at"><dt class="inline">{{ $t('entrepreneur.dashboard.submittedAt') }}:</dt> <dd class="inline text-ink font-medium">{{ date(primary.submitted_at) }}</dd></div>
              <div><dt class="inline">{{ $t('entrepreneur.form.checklistTitle') }}:</dt> <dd class="inline num text-ink font-medium">{{ number(primary.completion_percent) }}%</dd></div>
            </dl>
          </div>
          <StatusStrip :status="primary.status" />
          <div v-if="opportunities.length > 1" class="mt-4 flex flex-wrap gap-2 text-xs">
            <NuxtLink v-for="o in opportunities" :key="o.uuid" :to="$localePath(`/app/entrepreneur/opportunities/${o.uuid}`)" class="chip !py-1">{{ o.name }} <AppBadge kind="opportunity" :value="o.status" :dot="false" /></NuxtLink>
          </div>
        </SectionCard>

        <!-- EVL-03 — evaluation result + improvement plan -->
        <SectionCard v-if="primary.evaluation_decision" :title="$t('components.evaluationDecision.title')" class="lg:col-span-3">
          <EvaluationDecisionCard :decision="primary.evaluation_decision" />
        </SectionCard>

        <!-- required now -->
        <SectionCard :title="$t('entrepreneur.dashboard.requiredNow')" class="lg:col-span-2">
          <p v-if="!requiredNow.length" class="text-sm text-muted">{{ $t('entrepreneur.dashboard.nothingRequired') }}</p>
          <ul v-else class="divide-y divide-border">
            <li v-for="it in requiredNow" :key="it.id" class="py-3 flex flex-col sm:flex-row sm:items-center gap-3">
              <div class="flex-1 min-w-0">
                <p class="font-medium text-ink">{{ it.title }}</p>
                <p v-if="it.note" class="text-sm text-muted">{{ it.note }}</p>
                <p v-if="it.due_at" class="text-xs mt-0.5 num" :class="(daysUntil(it.due_at) ?? 1) < 0 ? 'text-danger' : 'text-muted'">{{ $t('common.dueAt') }}: {{ date(it.due_at) }} ({{ (daysUntil(it.due_at) ?? 0) < 0 ? $t('common.overdueBy', { n: Math.abs(daysUntil(it.due_at) ?? 0) }) : $t('common.daysLeft', { n: daysUntil(it.due_at) }) }})</p>
              </div>
              <AppButton v-if="it.action === 'meeting_action'" size="sm" variant="outline" :loading="busy === `action-${it.id}`" @click="markActionDone(it)">{{ $t('components.meetingActions.markDone') }}</AppButton>
              <AppButton v-else size="sm" :to="actionHref(it)">{{ $t(`entrepreneur.dashboard.actions.${it.action}`) }}</AppButton>
            </li>
          </ul>
        </SectionCard>

        <!-- preview card -->
        <SectionCard :title="$t('entrepreneur.dashboard.previewTitle')" :sub="$t('entrepreneur.dashboard.previewText')">
          <OpportunityCard v-if="data.preview" :opportunity="data.preview" :to="`/app/entrepreneur/opportunities/${data.preview.uuid}/preview`" :show-status="false" />
          <div class="mt-4 grid grid-cols-2 gap-3">
            <StatCard :label="$t('entrepreneur.dashboard.infoOpen')" :value="number(data.info_requests_open ?? data.pending_info_requests ?? 0)" to="/app/entrepreneur/info-requests" tone="blue" />
            <StatCard :label="$t('entrepreneur.dashboard.meetingsUpcoming')" :value="number(data.meetings_upcoming ?? data.counters?.meetings ?? 0)" to="/app/entrepreneur/meetings" tone="success" />
          </div>
        </SectionCard>

        <!-- documents -->
        <SectionCard :title="$t('entrepreneur.dashboard.documents')" class="lg:col-span-2" flush>
          <div class="overflow-x-auto"><table class="table-base"><thead><tr><th>{{ $t('entrepreneur.form.documents.kind') }}</th><th>{{ $t('common.name') }}</th><th>{{ $t('common.status') }}</th><th>{{ $t('common.notes') }}</th></tr></thead>
            <tbody>
              <tr v-for="d in documents" :key="d.uuid"><td>{{ label('documentKind', d.kind) }}</td><td class="font-medium text-ink">{{ d.title }}<span class="block text-xs text-muted num">v{{ d.version }} · {{ date(d.created_at ?? d.uploaded_at) }}</span></td><td><AppBadge kind="document" :value="d.status" /></td><td class="text-muted text-xs max-w-xs">{{ d.reviewer_note ?? '—' }}</td></tr>
              <tr v-if="!documents.length"><td colspan="4" class="text-center text-muted py-6">{{ $t('common.noData') }}</td></tr>
            </tbody></table></div>
          <div class="px-4 py-3 border-t border-border"><AppButton size="sm" variant="outline" :to="`/app/entrepreneur/opportunities/${primary.uuid}/edit?step=documents`">{{ $t('common.upload') }}</AppButton></div>
        </SectionCard>

        <!-- access requests -->
        <SectionCard :title="$t('entrepreneur.dashboard.accessRequests')" :sub="$t('entrepreneur.dashboard.accessRequestsText')">
          <ul class="space-y-3">
            <li v-for="i in accessRequests" :key="i.id" class="rounded-xl border border-border bg-glass p-3 text-sm">
              <div class="flex items-center justify-between gap-2"><span class="font-medium text-ink">{{ i.investor?.name ?? $t('entrepreneur.dashboard.investorLabel') }}</span><AppBadge kind="interest" :value="i.status" /></div>
              <p class="text-xs text-muted mt-1">{{ i.opportunity.name }} · <span class="num">{{ date(i.created_at) }}</span></p>
              <p v-if="i.note" class="text-xs mt-1 text-text">{{ i.note }}</p>
              <div v-if="i.status === 'awaiting_owner_approval'" class="mt-2 flex gap-2"><AppButton size="sm" :loading="busy === `approve-${i.id}`" @click="decide(i, 'approve')">{{ $t('common.approve') }}</AppButton><AppButton size="sm" variant="ghost" :loading="busy === `decline-${i.id}`" @click="decide(i, 'decline')">{{ $t('common.decline') }}</AppButton></div>
            </li>
            <li v-if="!accessRequests.length" class="text-sm text-muted">{{ $t('common.noData') }}</li>
          </ul>
        </SectionCard>
      </div>
    </template>
  </div>
</template>

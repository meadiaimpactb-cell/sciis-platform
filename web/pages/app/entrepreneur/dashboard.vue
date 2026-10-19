<script setup lang="ts">
/** Entrepreneur dashboard — doc §7.6 (ENT-04..09) */
import type { DashboardEntrepreneur, Interest, RequiredNowItem } from '~/types/api'
definePageMeta({ layout: 'portal', middleware: ['entrepreneur'] })
const { t } = useI18n()
useHead({ title: t('entrepreneur.dashboard.metaTitle') })
const api = useApi()
const auth = useAuthStore()
const router = useRouter()
const localePath = useLocalePath()
const toast = useToast()
const { label } = useEnumLabel()
const { date, daysUntil, number } = useFormat()
const { data, loading, refresh } = useAsyncResource(() => api.get<{ data: DashboardEntrepreneur }>('/entrepreneur/dashboard').then((r) => r.data))
const primary = computed(() => data.value?.opportunities.find((o) => !['withdrawn', 'closed'].includes(o.status)) ?? data.value?.opportunities[0] ?? null)
const actionHref = (it: RequiredNowItem) => {
  if (it.action === 'decide_access') return '/app/entrepreneur/access-requests'
  if (it.action === 'answer_info') return '/app/entrepreneur/info-requests'
  if (it.action === 'approve_teaser') return `/app/entrepreneur/opportunities/${it.opportunity_uuid}/preview`
  if (it.action === 'upload') return `/app/entrepreneur/opportunities/${it.opportunity_uuid}/edit?step=documents`
  return `/app/entrepreneur/opportunities/${it.opportunity_uuid}/edit`
}
const busy = ref<number | null>(null)
const decide = async (i: Interest, decision: 'approve' | 'decline') => {
  busy.value = i.id
  try { await api.post(`/entrepreneur/access-requests/${i.id}/decide`, { decision }); toast.success(t('common.success')); await refresh() } catch (e) { toast.error((e as Error).message) } finally { busy.value = null }
}
const withdraw = async () => {
  if (!primary.value || !confirm(t('entrepreneur.dashboard.withdrawConfirm'))) return
  try { await api.post(`/entrepreneur/opportunities/${primary.value.uuid}/withdraw`); toast.success(t('common.success')); await refresh() } catch (e) { toast.error((e as Error).message) }
}
const createProject = async () => { const r = await api.post<{ data: { uuid: string } }>('/entrepreneur/opportunities', {}); router.push(localePath(`/app/entrepreneur/opportunities/${r.data.uuid}/edit`)) }
</script>

<template>
  <div>
    <PageHeader :title="$t('entrepreneur.dashboard.welcome', { name: auth.me?.name })" :eyebrow="$t('portal.entrepreneur')">
      <template #actions>
        <AppButton v-if="primary && !['connected', 'negotiating', 'in_execution', 'operating', 'closed', 'withdrawn'].includes(primary.status)" variant="ghost" size="sm" @click="withdraw">{{ $t('entrepreneur.dashboard.withdraw') }}</AppButton>
        <AppButton v-if="primary" variant="secondary" size="sm" :to="`/app/entrepreneur/opportunities/${primary.uuid}/preview`">{{ $t('entrepreneur.dashboard.fullPreview') }}</AppButton>
        <AppButton v-if="primary" size="sm" :to="`/app/entrepreneur/opportunities/${primary.uuid}/edit`">{{ $t('entrepreneur.dashboard.editProject') }}</AppButton>
        <AppButton v-else size="sm" @click="createProject">{{ $t('entrepreneur.dashboard.createProject') }}</AppButton>
      </template>
    </PageHeader>
    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <template v-else-if="data">
      <EmptyState v-if="!primary" :title="$t('entrepreneur.dashboard.noProject')"><AppButton @click="createProject">{{ $t('entrepreneur.dashboard.createProject') }}</AppButton></EmptyState>
      <div v-else class="grid gap-6 lg:grid-cols-3">
        <!-- progress strip -->
        <SectionCard :title="$t('entrepreneur.dashboard.progress')" class="lg:col-span-3">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
            <div class="flex flex-wrap items-center gap-3">
              <h3 class="text-lg font-semibold">{{ primary.name }}</h3>
              <AppBadge kind="opportunity" :value="primary.status" size="md" :soft="false" />
            </div>
            <dl class="flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted">
              <div v-if="primary.reference_no"><dt class="inline">{{ $t('entrepreneur.dashboard.referenceNo') }}:</dt> <dd class="inline num text-ink font-medium">{{ primary.reference_no }}</dd></div>
              <div v-if="primary.submitted_at"><dt class="inline">{{ $t('entrepreneur.dashboard.submittedAt') }}:</dt> <dd class="inline text-ink font-medium">{{ date(primary.submitted_at) }}</dd></div>
              <div><dt class="inline">{{ $t('entrepreneur.form.checklistTitle') }}:</dt> <dd class="inline num text-ink font-medium">{{ number(primary.completion_percent) }}%</dd></div>
            </dl>
          </div>
          <StatusStrip :status="primary.status" />
          <div v-if="data.opportunities.length > 1" class="mt-4 flex flex-wrap gap-2 text-xs">
            <NuxtLink v-for="o in data.opportunities" :key="o.uuid" :to="$localePath(`/app/entrepreneur/opportunities/${o.uuid}/edit`)" class="chip !py-0.5">{{ o.name }} <AppBadge kind="opportunity" :value="o.status" :dot="false" /></NuxtLink>
          </div>
        </SectionCard>

        <!-- required now -->
        <SectionCard :title="$t('entrepreneur.dashboard.requiredNow')" class="lg:col-span-2">
          <p v-if="!data.required_now.length" class="text-sm text-muted">{{ $t('entrepreneur.dashboard.nothingRequired') }}</p>
          <ul v-else class="divide-y divide-border">
            <li v-for="it in data.required_now" :key="it.id" class="py-3 flex flex-col sm:flex-row sm:items-center gap-3">
              <div class="flex-1 min-w-0">
                <p class="font-medium">{{ it.title }}</p>
                <p v-if="it.note" class="text-sm text-muted">{{ it.note }}</p>
                <p v-if="it.due_at" class="text-xs mt-0.5" :class="(daysUntil(it.due_at) ?? 1) < 0 ? 'text-danger' : 'text-muted'">{{ $t('common.dueAt') }}: {{ date(it.due_at) }} ({{ (daysUntil(it.due_at) ?? 0) < 0 ? $t('common.overdueBy', { n: Math.abs(daysUntil(it.due_at) ?? 0) }) : $t('common.daysLeft', { n: daysUntil(it.due_at) }) }})</p>
              </div>
              <AppButton size="sm" :to="actionHref(it)">{{ $t(`entrepreneur.dashboard.actions.${it.action}`) }}</AppButton>
            </li>
          </ul>
        </SectionCard>

        <!-- preview card -->
        <SectionCard :title="$t('entrepreneur.dashboard.previewTitle')" :sub="$t('entrepreneur.dashboard.previewText')">
          <OpportunityCard v-if="data.preview" :opportunity="data.preview" :to="`/app/entrepreneur/opportunities/${data.preview.uuid}/preview`" :show-status="false" />
          <div class="mt-3 grid grid-cols-2 gap-3 text-center">
            <NuxtLink :to="$localePath('/app/entrepreneur/info-requests')" class="rounded-lg bg-bg p-3"><p class="text-2xl font-semibold num">{{ number(data.info_requests_open) }}</p><p class="text-xs text-muted">{{ $t('entrepreneur.dashboard.infoOpen') }}</p></NuxtLink>
            <NuxtLink :to="$localePath('/app/entrepreneur/meetings')" class="rounded-lg bg-bg p-3"><p class="text-2xl font-semibold num">{{ number(data.meetings_upcoming) }}</p><p class="text-xs text-muted">{{ $t('entrepreneur.dashboard.meetingsUpcoming') }}</p></NuxtLink>
          </div>
        </SectionCard>

        <!-- documents -->
        <SectionCard :title="$t('entrepreneur.dashboard.documents')" class="lg:col-span-2" flush>
          <table class="table-base"><thead><tr><th>{{ $t('entrepreneur.form.documents.kind') }}</th><th>{{ $t('common.name') }}</th><th>{{ $t('common.status') }}</th><th>{{ $t('common.notes') }}</th></tr></thead>
            <tbody>
              <tr v-for="d in data.documents" :key="d.uuid"><td>{{ label('documentKind', d.kind) }}</td><td class="font-medium">{{ d.title }}<span class="block text-xs text-muted num">v{{ d.version }} · {{ date(d.created_at) }}</span></td><td><AppBadge kind="document" :value="d.status" /></td><td class="text-muted text-xs max-w-xs">{{ d.reviewer_note ?? '—' }}</td></tr>
              <tr v-if="!data.documents.length"><td colspan="4" class="text-center text-muted py-6">{{ $t('common.noData') }}</td></tr>
            </tbody></table>
          <div class="px-4 py-3 border-t border-border"><AppButton size="sm" variant="outline" :to="`/app/entrepreneur/opportunities/${primary.uuid}/edit?step=documents`">{{ $t('common.upload') }}</AppButton></div>
        </SectionCard>

        <!-- access requests -->
        <SectionCard :title="$t('entrepreneur.dashboard.accessRequests')" :sub="$t('entrepreneur.dashboard.accessRequestsText')">
          <ul class="space-y-3">
            <li v-for="i in data.access_requests" :key="i.id" class="rounded-lg border border-border p-3 text-sm">
              <div class="flex items-center justify-between gap-2"><span class="font-medium">{{ i.investor?.name ?? $t('entrepreneur.dashboard.investorLabel') }}</span><AppBadge kind="interest" :value="i.status" /></div>
              <p class="text-xs text-muted mt-1">{{ i.opportunity.name }} · {{ date(i.created_at) }}</p>
              <p v-if="i.note" class="text-xs mt-1 text-text/80">{{ i.note }}</p>
              <div v-if="i.status === 'awaiting_owner_approval'" class="mt-2 flex gap-2"><AppButton size="sm" :loading="busy === i.id" @click="decide(i, 'approve')">{{ $t('common.approve') }}</AppButton><AppButton size="sm" variant="ghost" @click="decide(i, 'decline')">{{ $t('common.decline') }}</AppButton></div>
            </li>
            <li v-if="!data.access_requests.length" class="text-sm text-muted">{{ $t('common.noData') }}</li>
          </ul>
        </SectionCard>
      </div>
    </template>
  </div>
</template>

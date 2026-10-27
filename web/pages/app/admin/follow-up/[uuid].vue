<script setup lang="ts">
/**
 * Follow-up file of one project (impact.verify) — GET /admin/follow-up/{uuid}; every decision returns the refreshed file:
 *  PRJ-01 plan review (approve | changes_requested + note) · reopen a done milestone (reason)
 *  PRJ-02 report review (accept | changes_requested + note)
 *  PRJ-03 execution-status override (status + reason) / back to the computed status
 *  IMP-02 indicator value review (verify | needs_clarification + reason)
 *  ENT-10 held update review (publish | reject + note) · AUD-01 decision trail.
 * Notes: FollowUpReviewRequest / FollowUpReasonRequest / ExecutionStatusRequest — 5..2000 characters.
 */
import { z } from 'zod'
import type { FollowUpDetail, FollowUpIndicator, FollowUpIndicatorValue, FollowUpMilestone, FollowUpReport, FollowUpUpdate } from '~/types/api'
import { EXECUTION_STATUSES } from '~/types/enums'
import { requiredChoice, requiredString } from '~/utils/validation'
import { confirmDialog, toastApiError, toastSuccess } from '~/utils/notify'
import { FOLLOW_UP_RULES } from '~/utils/followUpRules'
definePageMeta({ layout: 'portal', middleware: ['role'] })
const { t, te } = useI18n()
const route = useRoute()
const uuid = computed(() => String(route.params.uuid))
const api = useApi()
const auth = useAuthStore()
const { label } = useEnumLabel()
const { date, dateTime, number } = useFormat()
const allowed = computed(() => auth.canAny('impact.verify'))
const base = computed(() => `/admin/follow-up/${uuid.value}`)
const DOCS = '/admin/follow-up/documents'
const { data, loading, refresh } = useAsyncResource(() => (allowed.value ? api.get<{ data: FollowUpDetail }>(base.value).then((r) => r.data) : Promise.resolve(null)))
useHead(() => ({ title: data.value ? `${data.value.name} — ${t('followUp.admin.metaTitle')}` : t('followUp.admin.metaTitle') }))
const fu = computed(() => data.value?.follow_up ?? null)
const name = computed(() => data.value?.name ?? '')

const tab = ref('plan')
const tabs = computed(() => [
  { key: 'plan', label: t('followUp.tabs.plan'), count: data.value?.milestones_list.length ?? 0 },
  { key: 'reports', label: t('followUp.tabs.reports'), count: data.value?.reports.length ?? 0 },
  { key: 'impact', label: t('followUp.tabs.impact'), count: data.value?.indicators.length ?? 0 },
  { key: 'updates', label: t('followUp.tabs.updates'), count: data.value?.updates.length ?? 0 },
  { key: 'history', label: t('followUp.tabs.history') },
])

const busy = ref<string | null>(null)
const ent = (k: string) => t(`followUp.entities.${k}`)
/** Posts a decision and swaps in the refreshed file. */
const act = async (key: string, path: string, body: Record<string, unknown>, done: string, method: 'post' | 'put' = 'post') => {
  busy.value = key
  try {
    const r = method === 'put' ? await api.put<{ data: FollowUpDetail }>(`${base.value}${path}`, body) : await api.post<{ data: FollowUpDetail }>(`${base.value}${path}`, body)
    data.value = r.data
    toastSuccess(done)
  } catch (e) { toastApiError(e) } finally { busy.value = null }
}

// ---------------------------------------------------------------- positive decisions (confirmed first)
const approvePlan = async () => {
  if (!(await confirmDialog(t('followUp.plan.approveConfirmTitle'), t('followUp.plan.approveConfirmText', { name: name.value }), t('followUp.plan.approve')))) return
  await act('plan', '/plan/review', { decision: 'approve' }, t('notify.approved', { item: ent('plan') }))
}
const periodText = (r: FollowUpReport) => t('followUp.reports.periodLabel', { start: date(r.period_start), end: date(r.period_end) })
const acceptReport = async (r: FollowUpReport) => {
  if (!(await confirmDialog(t('followUp.reports.acceptConfirmTitle'), t('followUp.reports.acceptConfirmText', { name: name.value, period: periodText(r) }), t('followUp.reports.accept')))) return
  await act(`report-${r.id}`, `/reports/${r.id}/review`, { decision: 'accept' }, t('followUp.toast.accepted', { item: `${ent('report')} (${periodText(r)})` }))
}
const verifyValue = async (i: FollowUpIndicator, v: FollowUpIndicatorValue) => {
  if (!(await confirmDialog(t('followUp.impact.verifyConfirmTitle'), t('followUp.impact.verifyConfirmText', { name: i.name, value: number(v.value) }), t('followUp.impact.verify')))) return
  await act(`value-${v.id}`, `/indicator-values/${v.id}/review`, { decision: 'verify' }, t('followUp.toast.verified', { item: `${ent('value')} «${i.name}»` }))
}
const publishUpdate = async (u: FollowUpUpdate) => {
  const title = u.title || t('followUp.updates.untitled')
  if (!(await confirmDialog(t('followUp.updates.publishConfirmTitle'), t('followUp.updates.publishConfirmText', { name: title }), t('followUp.updates.publish')))) return
  await act(`update-${u.id}`, `/updates/${u.id}/review`, { decision: 'publish' }, t('followUp.toast.published', { item: `${ent('update')} «${title}»` }))
}

// ---------------------------------------------------------------- decisions that need a note / reason
type NoteKind = 'plan' | 'report' | 'value' | 'update' | 'reopen'
const pending = ref<{ kind: NoteKind; id?: number; title: string; fieldLabel: string; item: string } | null>(null)
const noteForm = useZodForm(z.object({ note: requiredString('followUp.decision.note', FOLLOW_UP_RULES.noteMin, FOLLOW_UP_RULES.noteMax) }), { note: '' }, { aliases: { reason: 'note' } })
const askNote = (kind: NoteKind, title: string, fieldLabel: string, item: string, id?: number) => { noteForm.reset(); pending.value = { kind, id, title, fieldLabel, item } }
const sendNote = () => noteForm.submit(async (v) => {
  const p = pending.value
  if (!p) return
  const note = v.note.trim()
  const map: Record<NoteKind, { path: string; body: Record<string, unknown>; toast: string }> = {
    plan: { path: '/plan/review', body: { decision: 'changes_requested', note }, toast: t('followUp.toast.changesRequested', { item: p.item }) },
    report: { path: `/reports/${p.id}/review`, body: { decision: 'changes_requested', note }, toast: t('followUp.toast.changesRequested', { item: p.item }) },
    value: { path: `/indicator-values/${p.id}/review`, body: { decision: 'needs_clarification', note }, toast: t('followUp.toast.clarification', { item: p.item }) },
    update: { path: `/updates/${p.id}/review`, body: { decision: 'reject', note }, toast: t('followUp.toast.notApproved', { item: p.item }) },
    reopen: { path: `/milestones/${p.id}/reopen`, body: { reason: note }, toast: t('notify.reopened', { item: p.item }) },
  }
  const m = map[p.kind]
  const r = await api.post<{ data: FollowUpDetail }>(`${base.value}${m.path}`, m.body)
  data.value = r.data
  pending.value = null
  toastSuccess(m.toast)
})
const requestPlanChanges = () => askNote('plan', t('followUp.plan.requestChanges'), t('followUp.decision.note'), ent('plan'))
const requestReportChanges = (r: FollowUpReport) => askNote('report', t('followUp.reports.requestChanges'), t('followUp.decision.note'), `${ent('report')} (${periodText(r)})`, r.id)
const clarifyValue = (i: FollowUpIndicator, v: FollowUpIndicatorValue) => askNote('value', t('followUp.impact.clarifyTitle'), t('followUp.impact.clarifyReason'), `${ent('value')} «${i.name}»`, v.id)
const rejectUpdate = (u: FollowUpUpdate) => askNote('update', t('followUp.updates.rejectTitle'), t('followUp.updates.rejectReason'), `${ent('update')} «${u.title || t('followUp.updates.untitled')}»`, u.id)
const reopenMilestone = (m: FollowUpMilestone) => askNote('reopen', t('followUp.milestone.reopenTitle'), t('followUp.milestone.reopenReason'), `${ent('milestone')} «${m.title}»`, m.id)

// ---------------------------------------------------------------- PRJ-03 override
const overrideOpen = ref(false)
const overrideForm = useZodForm(
  z.object({ status: requiredChoice('followUp.override.status'), reason: requiredString('followUp.override.reason', FOLLOW_UP_RULES.noteMin, FOLLOW_UP_RULES.noteMax) }),
  { status: '' as string | number, reason: '' },
)
const openOverride = () => { overrideForm.reset({ status: fu.value?.status_override ?? '', reason: fu.value?.override_reason ?? '' }); overrideOpen.value = true }
const saveOverride = () => overrideForm.submit(async (v) => {
  const r = await api.put<{ data: FollowUpDetail }>(`${base.value}/status`, { status: String(v.status), reason: v.reason.trim() })
  data.value = r.data
  overrideOpen.value = false
  toastSuccess(t('notify.updated', { item: `${ent('status')} «${name.value}»` }))
})
const clearOverride = async () => {
  if (!(await confirmDialog(t('followUp.override.clearConfirmTitle'), t('followUp.override.clearConfirmText', { name: name.value }), t('followUp.override.clear')))) return
  await act('clear', '/status', { status: null }, t('followUp.toast.overrideCleared', { item: ent('status') }), 'put')
}
const statusOptions = computed(() => EXECUTION_STATUSES.map((s) => ({ value: s, label: label('executionStatus', s) })))
const historyLabel = (action: string) => { const k = `followUp.history.actions.${action.replace(/\./g, '_')}`; return te(k) ? t(k) : action }
</script>

<template>
  <div>
    <EmptyState v-if="!allowed" :title="$t('portal.roleGate.title')" :text="$t('portal.roleGate.text')" />
    <div v-else-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <template v-else-if="data">
      <PageHeader :title="data.name" :eyebrow="$t('followUp.admin.title')" :sub="[data.reference_no, data.owner?.name].filter(Boolean).join(' · ')">
        <template #actions>
          <AppButton variant="ghost" size="sm" to="/app/admin/follow-up">{{ $t('common.back') }}</AppButton>
          <AppButton v-if="auth.canAny('opportunities.view')" variant="secondary" size="sm" :to="`/app/admin/opportunities/${data.uuid}`">{{ $t('followUp.admin.openFile') }}</AppButton>
        </template>
      </PageHeader>

      <div class="space-y-6">
        <FollowUpStatusPanel :detail="data" audience="staff">
          <template v-if="fu" #actions>
            <AppButton v-if="fu.status_override" size="sm" variant="ghost" :loading="busy === 'clear'" @click="clearOverride">{{ $t('followUp.override.clear') }}</AppButton>
            <AppButton size="sm" variant="outline" @click="openOverride">{{ $t('followUp.override.button') }}</AppButton>
          </template>
        </FollowUpStatusPanel>

        <SectionCard v-if="fu?.remediation_plan || data.execution_status === 'delayed' || data.execution_status === 'stalled'" :title="$t('followUp.remediation.title')">
          <p v-if="fu?.remediation_plan" class="text-sm leading-6 text-text whitespace-pre-line">{{ fu.remediation_plan }}</p>
          <p v-else class="text-sm text-muted">{{ $t('followUp.remediation.empty') }}</p>
          <p v-if="fu?.remediation_updated_at" class="mt-2 text-xs text-muted num">{{ $t('followUp.remediation.updatedAt', { date: dateTime(fu.remediation_updated_at) }) }}</p>
        </SectionCard>

        <div>
          <Tabs v-model="tab" :tabs="tabs" />
          <!-- PRJ-01 -->
          <div v-show="tab === 'plan'" id="panel-plan" role="tabpanel" aria-labelledby="tab-plan" class="mt-5">
            <SectionCard :title="$t('followUp.plan.title')" :sub="$t('followUp.plan.staffSub')">
              <template #actions>
                <template v-if="fu?.plan_status === 'submitted'">
                  <AppButton size="sm" variant="ghost" @click="requestPlanChanges">{{ $t('followUp.plan.requestChanges') }}</AppButton>
                  <AppButton size="sm" :loading="busy === 'plan'" @click="approvePlan">{{ $t('followUp.plan.approve') }}</AppButton>
                </template>
              </template>
              <div class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                <FollowUpBadge group="plan" :value="fu?.plan_status" />
                <span v-if="fu?.plan_version" class="text-muted num">{{ $t('followUp.plan.version', { n: number(fu.plan_version) }) }}</span>
                <span v-if="fu?.plan_submitted_at" class="text-muted num">{{ $t('followUp.plan.submittedAt', { date: date(fu.plan_submitted_at) }) }}</span>
                <span v-if="data.budget_share_total != null" class="text-muted num">{{ $t('followUp.plan.budgetTotal', { n: number(data.budget_share_total) }) }}</span>
              </div>
              <InlineAlert v-if="fu?.plan_review_note" class="mb-4" :tone="fu.plan_status === 'changes_requested' ? 'warning' : 'info'" :title="$t('followUp.plan.reviewNote')">{{ fu.plan_review_note }}</InlineAlert>
              <FollowUpMilestones :milestones="data.milestones_list" :download-base="DOCS">
                <template #actions="{ milestone }">
                  <AppButton v-if="milestone.status === 'done'" size="sm" variant="ghost" @click="reopenMilestone(milestone)">{{ $t('followUp.milestone.reopen') }}</AppButton>
                </template>
              </FollowUpMilestones>
            </SectionCard>
          </div>

          <!-- PRJ-02 -->
          <div v-show="tab === 'reports'" id="panel-reports" role="tabpanel" aria-labelledby="tab-reports" class="mt-5 space-y-5">
            <SectionCard :title="$t('followUp.reports.schedule')" flush>
              <div v-if="data.periods?.length" class="overflow-x-auto">
                <table class="table-base">
                  <thead><tr><th>{{ $t('followUp.reports.period') }}</th><th>{{ $t('followUp.reports.dueOn') }}</th><th>{{ $t('followUp.reports.state') }}</th></tr></thead>
                  <tbody>
                    <tr v-for="p in data.periods" :key="p.period_start">
                      <td class="num">{{ $t('followUp.reports.periodLabel', { start: date(p.period_start), end: date(p.period_end) }) }}</td>
                      <td class="num">{{ date(p.due_on) }}<span v-if="p.days_overdue" class="block text-xs text-danger">{{ $t('common.overdueBy', { n: p.days_overdue }) }}</span></td>
                      <td><FollowUpBadge group="period" :value="p.state" /></td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <p v-else class="px-5 py-4 text-sm text-muted">{{ $t('followUp.reports.notStarted') }}</p>
            </SectionCard>
            <SectionCard :title="$t('followUp.reports.title')" :sub="$t('followUp.reports.sub')">
              <div v-if="data.reports.length" class="space-y-4">
                <FollowUpReportCard v-for="r in data.reports" :key="r.id" :report="r" :download-base="DOCS">
                  <template v-if="r.status === 'submitted'" #actions>
                    <AppButton size="sm" variant="ghost" @click="requestReportChanges(r)">{{ $t('followUp.reports.requestChanges') }}</AppButton>
                    <AppButton size="sm" :loading="busy === `report-${r.id}`" @click="acceptReport(r)">{{ $t('followUp.reports.accept') }}</AppButton>
                  </template>
                </FollowUpReportCard>
              </div>
              <p v-else class="text-sm text-muted">{{ $t('followUp.reports.empty') }}</p>
            </SectionCard>
          </div>

          <!-- IMP-01/02 -->
          <div v-show="tab === 'impact'" id="panel-impact" role="tabpanel" aria-labelledby="tab-impact" class="mt-5">
            <SectionCard :title="$t('followUp.impact.title')" :sub="$t('followUp.impact.sub')">
              <div v-if="data.indicators.length" class="space-y-5">
                <BarChart v-if="data.indicators.length > 1" :series="data.indicators.map((i) => ({ label: i.name, value: Math.max(0, i.progress_percent ?? 0), color: 'var(--accent-hi)' }))" :max="100" unit="%" />
                <FollowUpIndicatorCard v-for="i in data.indicators" :key="i.id" :indicator="i" :download-base="DOCS">
                  <template #value-actions="{ value }">
                    <div v-if="value.status === 'entered'" class="inline-flex gap-1.5">
                      <AppButton size="sm" variant="ghost" @click="clarifyValue(i, value)">{{ $t('followUp.impact.clarify') }}</AppButton>
                      <AppButton size="sm" variant="outline" :loading="busy === `value-${value.id}`" @click="verifyValue(i, value)">{{ $t('followUp.impact.verify') }}</AppButton>
                    </div>
                  </template>
                </FollowUpIndicatorCard>
              </div>
              <p v-else class="text-sm text-muted">{{ $t('followUp.impact.noIndicators') }}</p>
            </SectionCard>
          </div>

          <!-- ENT-10 -->
          <div v-show="tab === 'updates'" id="panel-updates" role="tabpanel" aria-labelledby="tab-updates" class="mt-5">
            <SectionCard :title="$t('followUp.updates.title')" :sub="data.updates_require_review ? $t('followUp.updates.reviewHint') : $t('followUp.updates.staffDirectHint')">
              <div v-if="data.updates.length" class="space-y-4">
                <FollowUpUpdateCard v-for="u in data.updates" :key="u.id" :update="u" :download-base="DOCS" show-status>
                  <template v-if="u.status === 'pending_review'" #actions>
                    <AppButton size="sm" variant="ghost" @click="rejectUpdate(u)">{{ $t('followUp.updates.reject') }}</AppButton>
                    <AppButton size="sm" :loading="busy === `update-${u.id}`" @click="publishUpdate(u)">{{ $t('followUp.updates.publish') }}</AppButton>
                  </template>
                </FollowUpUpdateCard>
              </div>
              <p v-else class="text-sm text-muted">{{ $t('followUp.updates.empty') }}</p>
            </SectionCard>
          </div>

          <!-- AUD-01 -->
          <div v-show="tab === 'history'" id="panel-history" role="tabpanel" aria-labelledby="tab-history" class="mt-5">
            <SectionCard :title="$t('followUp.history.title')">
              <ol v-if="data.history?.length" class="divide-y divide-border">
                <li v-for="h in data.history" :key="h.id" class="py-2.5 flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-3 text-sm">
                  <span class="text-xs text-muted num sm:w-40 shrink-0">{{ dateTime(h.created_at) }}</span>
                  <span class="font-medium">{{ historyLabel(h.action) }}</span>
                  <span class="text-muted">{{ h.user ?? $t('followUp.history.system') }}</span>
                  <span v-if="h.after?.note || h.after?.reason" class="text-xs text-muted sm:ms-auto max-w-md truncate" :title="String(h.after?.note ?? h.after?.reason)">{{ h.after?.note ?? h.after?.reason }}</span>
                </li>
              </ol>
              <p v-else class="text-sm text-muted">{{ $t('followUp.history.empty') }}</p>
            </SectionCard>
          </div>
        </div>
      </div>

      <Modal :open="!!pending" :title="pending?.title" @close="pending = null">
        <form class="space-y-4" novalidate @submit.prevent="sendNote">
          <p class="text-sm text-muted">{{ pending?.item }}</p>
          <FormField v-model="noteForm.values.note" name="note" type="textarea" :label="pending?.fieldLabel" :help="$t('followUp.decision.ownerSees')" :error="noteForm.errors.value.note" :rows="4" :maxlength="FOLLOW_UP_RULES.noteMax" required />
        </form>
        <template #footer><FormAlert class="basis-full" /><AppButton variant="ghost" @click="pending = null">{{ $t('common.cancel') }}</AppButton><AppButton :loading="noteForm.submitting.value" @click="sendNote">{{ $t('followUp.decision.submit') }}</AppButton></template>
      </Modal>

      <Modal :open="overrideOpen" :title="$t('followUp.override.title')" @close="overrideOpen = false">
        <form class="space-y-4" novalidate @submit.prevent="saveOverride">
          <p class="text-sm text-muted">{{ $t('followUp.override.hint') }}</p>
          <FormField v-model="overrideForm.values.status" name="status" type="select" :label="$t('followUp.override.status')" :options="statusOptions" :error="overrideForm.errors.value.status" required />
          <FormField v-model="overrideForm.values.reason" name="reason" type="textarea" :label="$t('followUp.override.reason')" :error="overrideForm.errors.value.reason" :rows="4" :maxlength="FOLLOW_UP_RULES.noteMax" required />
        </form>
        <template #footer><FormAlert class="basis-full" /><AppButton variant="ghost" @click="overrideOpen = false">{{ $t('common.cancel') }}</AppButton><AppButton :loading="overrideForm.submitting.value" @click="saveOverride">{{ $t('common.save') }}</AppButton></template>
      </Modal>
    </template>
  </div>
</template>

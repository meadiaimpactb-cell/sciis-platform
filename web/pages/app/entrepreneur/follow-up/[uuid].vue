<script setup lang="ts">
/**
 * Owner follow-up file — GET /entrepreneur/follow-up/{uuid}:
 *  PRJ-01 milestone plan: add / edit / delete while draft or changes_requested (MilestoneRequest), submit for approval,
 *         revise (needs a new approval), mark a milestone done with evidence (CompleteMilestoneRequest, file required)
 *  PRJ-02 progress report for an open period (ProgressReportRequest, ≤ 5 files) and resubmission after a change request
 *  PRJ-03 remediation plan (RemediationPlanRequest) while delayed / stalled
 *  IMP-02 indicator value per period with optional evidence (IndicatorValueRequest), correction after a clarification
 *  ENT-10 update to the connected investors (InvestorUpdateRequest).
 */
import { z } from 'zod'
import type { FollowUpDetail, FollowUpIndicator, FollowUpIndicatorValue, FollowUpMilestone, FollowUpReport } from '~/types/api'
import { EXECUTION_STATUSES } from '~/types/enums'
import { optionalNumber, optionalText, requiredChoice, requiredDate, requiredNumber, requiredString, vmsg } from '~/utils/validation'
import { confirmDialog, toastApiError, toastError, toastSuccess } from '~/utils/notify'
import { FOLLOW_UP_RULES as R } from '~/utils/followUpRules'
definePageMeta({ layout: 'portal', middleware: ['entrepreneur'] })
const { t } = useI18n()
const route = useRoute()
const uuid = computed(() => String(route.params.uuid))
const api = useApi()
const tax = useTaxonomyStore()
void tax.load().catch(() => {})
const { label } = useEnumLabel()
const { date, dateTime, number } = useFormat()
const base = computed(() => `/entrepreneur/follow-up/${uuid.value}`)
const DOCS = '/me/documents'
const maxMb = computed(() => tax.settings?.documents?.max_size_mb ?? 25)
const { data, loading, refresh } = useAsyncResource(() => api.get<{ data: FollowUpDetail }>(base.value).then((r) => r.data))
useHead(() => ({ title: data.value ? `${data.value.name} — ${t('followUp.owner.metaTitle')}` : t('followUp.owner.metaTitle') }))
const fu = computed(() => data.value?.follow_up ?? null)
const name = computed(() => data.value?.name ?? '')
const planEditable = computed(() => fu.value?.plan_status === 'draft' || fu.value?.plan_status === 'changes_requested')
const planApproved = computed(() => fu.value?.plan_status === 'approved')
const isLate = computed(() => data.value?.execution_status === 'delayed' || data.value?.execution_status === 'stalled')
const openPeriods = computed(() => (data.value?.periods ?? []).filter((p) => !p.report_id))
const ent = (k: string) => t(`followUp.entities.${k}`)
const periodText = (p: { period_start: string; period_end: string }) => t('followUp.reports.periodLabel', { start: date(p.period_start), end: date(p.period_end) })

const tab = ref(typeof route.query.tab === 'string' ? route.query.tab : 'plan')
const tabs = computed(() => [
  { key: 'plan', label: t('followUp.tabs.plan'), count: data.value?.milestones_list.length ?? 0 },
  { key: 'reports', label: t('followUp.tabs.reports'), count: data.value?.reports.length ?? 0 },
  { key: 'impact', label: t('followUp.tabs.impact'), count: data.value?.indicators.length ?? 0 },
  { key: 'updates', label: t('followUp.tabs.updates'), count: data.value?.updates.length ?? 0 },
])
const busy = ref<string | null>(null)

// ---------------------------------------------------------------- PRJ-01 milestones
const msOpen = ref(false)
const msEditing = ref<FollowUpMilestone | null>(null)
const blankMs = () => ({ title: '', deliverable: '', due_at: '', responsible: '', budget_share: null as number | null, description: '' })
const msForm = useZodForm(
  z.object({
    title: requiredString('followUp.milestone.title', R.titleMin, R.titleMax),
    deliverable: requiredString('followUp.milestone.deliverable', R.deliverableMin, R.deliverableMax),
    due_at: requiredDate('followUp.milestone.dueAt'),
    responsible: optionalText(R.responsibleMax),
    budget_share: optionalNumber({ min: 0, max: R.budgetShareMax }),
    description: optionalText(R.descriptionMax),
  }),
  blankMs(),
)
const openMilestone = (m?: FollowUpMilestone) => {
  msEditing.value = m ?? null
  msForm.reset(m ? { title: m.title, deliverable: m.deliverable ?? '', due_at: m.due_at ?? '', responsible: m.responsible ?? '', budget_share: m.budget_share, description: m.description ?? '' } : blankMs())
  msOpen.value = true
}
const saveMilestone = () => msForm.submit(async (v) => {
  const body = { title: v.title.trim(), deliverable: v.deliverable.trim(), due_at: v.due_at, responsible: v.responsible?.trim() || null, budget_share: v.budget_share ?? null, description: v.description?.trim() || null }
  if (msEditing.value) await api.patch(`${base.value}/milestones/${msEditing.value.id}`, body)
  else await api.post(`${base.value}/milestones`, body)
  msOpen.value = false
  toastSuccess(t(msEditing.value ? 'notify.updated' : 'notify.created', { item: `${ent('milestone')} «${body.title}»` }))
  await refresh()
})
const deleteMilestone = async (m: FollowUpMilestone) => {
  if (!(await confirmDialog(t('notify.deleteTitle'), t('notify.deleteText', { name: m.title }), t('notify.deleteConfirm')))) return
  busy.value = `del-${m.id}`
  try {
    await api.del(`${base.value}/milestones/${m.id}`)
    toastSuccess(t('notify.deleted', { name: m.title }))
    await refresh()
  } catch (e) { toastApiError(e) } finally { busy.value = null }
}
const planAction = async (kind: 'submit' | 'revise') => {
  const ok = kind === 'submit'
    ? await confirmDialog(t('followUp.plan.submitConfirmTitle'), t('followUp.plan.submitConfirmText', { name: name.value }), t('followUp.plan.submit'))
    : await confirmDialog(t('followUp.plan.reviseConfirmTitle'), t('followUp.plan.reviseConfirmText', { name: name.value }), t('followUp.plan.revise'))
  if (!ok) return
  busy.value = kind
  try {
    const r = await api.post<{ data: FollowUpDetail }>(`${base.value}/plan/${kind}`)
    data.value = r.data
    toastSuccess(kind === 'submit' ? t('notify.submitted', { item: ent('plan') }) : t('notify.reopened', { item: ent('plan') }))
  } catch (e) { toastApiError(e) } finally { busy.value = null }
}

// complete with evidence
const doneFor = ref<FollowUpMilestone | null>(null)
const doneFile = ref<File | null>(null)
const doneForm = useZodForm(z.object({ file_name: requiredString('followUp.milestone.evidence'), note: optionalText(R.evidenceNoteMax) }), { file_name: '', note: '' }, { aliases: { file: 'file_name' } })
const openDone = (m: FollowUpMilestone) => { doneFor.value = m; doneFile.value = null; doneForm.reset() }
const pickDoneFile = (f: File) => { doneFile.value = f; doneForm.values.file_name = f.name; doneForm.clearErrors(['file_name']) }
const saveDone = () => doneForm.submit(async (v) => {
  const m = doneFor.value
  if (!m || !doneFile.value) return
  const fd = new FormData()
  fd.append('file', doneFile.value)
  if (v.note?.trim()) fd.append('note', v.note.trim())
  await api.post(`${base.value}/milestones/${m.id}/complete`, fd)
  doneFor.value = null
  toastSuccess(t('notify.markedDone', { item: `${ent('milestone')} «${m.title}»` }))
  await refresh()
})

// ---------------------------------------------------------------- PRJ-02 reports
type MsRow = { milestone_id: number; title: string; status: string; note: string }
const reportOpen = ref(false)
const reportEditing = ref<FollowUpReport | null>(null)
const reportFiles = ref<File[]>([])
const blankReport = () => ({ period_start: '' as string | number, narrative: '', achieved: '', delayed: '', risks: '', support_needed: '', spend_amount: null as number | null, execution_status: '', milestone_updates: [] as MsRow[] })
const reportForm = useZodForm(
  z.object({
    period_start: requiredChoice('followUp.reports.period'),
    narrative: requiredString('followUp.reports.narrative', R.narrativeMin, R.longTextMax),
    achieved: requiredString('followUp.reports.achieved', R.achievedMin, R.longTextMax),
    delayed: optionalText(R.longTextMax),
    risks: optionalText(R.longTextMax),
    support_needed: optionalText(R.longTextMax),
    spend_amount: optionalNumber({ min: 0, max: R.spendMax }),
    execution_status: z.string(),
    milestone_updates: z.array(z.object({ milestone_id: z.number(), title: z.string(), status: z.string(), note: optionalText(R.milestoneNoteMax) })),
  }),
  blankReport(),
)
const msRows = (r?: FollowUpReport): MsRow[] => (data.value?.milestones_list ?? []).filter((m) => m.status !== 'done').map((m) => {
  const prev = r?.milestone_updates.find((u) => u.milestone_id === m.id)
  return { milestone_id: m.id, title: m.title, status: prev?.status ?? '', note: prev?.note ?? '' }
})
const openReport = (r?: FollowUpReport) => {
  reportEditing.value = r ?? null
  reportFiles.value = []
  reportForm.reset(r
    ? { period_start: r.period_start, narrative: r.narrative ?? '', achieved: r.achieved ?? '', delayed: r.delayed ?? '', risks: r.risks ?? '', support_needed: r.support_needed ?? '', spend_amount: r.spend_amount, execution_status: r.execution_status ?? '', milestone_updates: msRows(r) }
    : { ...blankReport(), period_start: openPeriods.value[openPeriods.value.length - 1]?.period_start ?? '', milestone_updates: msRows() })
  reportOpen.value = true
}
const addReportFile = (f: File) => {
  if (reportFiles.value.length >= R.reportFilesMax) return toastError(vmsg('maxItems', { n: R.reportFilesMax }))
  reportFiles.value.push(f)
}
const saveReport = () => reportForm.submit(async (v) => {
  const fd = new FormData()
  const editing = reportEditing.value
  if (!editing) fd.append('period_start', String(v.period_start))
  fd.append('narrative', v.narrative.trim())
  fd.append('achieved', v.achieved.trim())
  for (const k of ['delayed', 'risks', 'support_needed'] as const) if (v[k]?.trim()) fd.append(k, v[k]!.trim())
  if (v.spend_amount != null) fd.append('spend_amount', String(v.spend_amount))
  if (v.execution_status) fd.append('execution_status', v.execution_status)
  v.milestone_updates.filter((u) => u.status).forEach((u, i) => {
    fd.append(`milestone_updates[${i}][milestone_id]`, String(u.milestone_id))
    fd.append(`milestone_updates[${i}][status]`, u.status)
    if (u.note?.trim()) fd.append(`milestone_updates[${i}][note]`, u.note.trim())
  })
  reportFiles.value.forEach((f) => fd.append('files[]', f))
  if (editing) await api.post(`${base.value}/reports/${editing.id}/resubmit`, fd)
  else await api.post(`${base.value}/reports`, fd)
  reportOpen.value = false
  const period = editing ? periodText(editing) : periodText(openPeriods.value.find((p) => p.period_start === v.period_start) ?? { period_start: String(v.period_start), period_end: String(v.period_start) })
  toastSuccess(t('notify.submitted', { item: `${ent('report')} (${period})` }))
  await refresh()
})
const periodOptions = computed(() => openPeriods.value.map((p) => ({ value: p.period_start, label: `${periodText(p)} · ${t('followUp.reports.dueOn')} ${date(p.due_on)}` })))
const selfStatusOptions = computed(() => EXECUTION_STATUSES.map((s) => ({ value: s, label: label('executionStatus', s) })))
const msStatusOptions = computed(() => [{ value: 'planned', label: t('followUp.enums.milestone.planned') }, { value: 'in_progress', label: t('followUp.enums.milestone.in_progress') }])

// ---------------------------------------------------------------- IMP-02 indicator values
const valueFor = ref<{ indicator: FollowUpIndicator; value?: FollowUpIndicatorValue } | null>(null)
const valueFile = ref<File | null>(null)
const today = () => new Date().toISOString().slice(0, 10)
const valueForm = useZodForm(
  z.object({
    period_start: requiredDate('followUp.impact.periodStart'),
    period_end: requiredDate('followUp.impact.periodEnd'),
    value: requiredNumber('followUp.impact.value', { min: -R.valueAbsMax, max: R.valueAbsMax }),
    note: optionalText(R.noteMax),
  })
    .refine((v) => !v.period_start || !v.period_end || v.period_end >= v.period_start, { path: ['period_end'], error: () => vmsg('endAfterStart') })
    .refine((v) => !v.period_end || v.period_end <= today(), { path: ['period_end'], error: () => t('followUp.impact.endNotFuture') }),
  { period_start: '', period_end: '', value: null as unknown as number, note: '' },
)
const openValue = (indicator: FollowUpIndicator, value?: FollowUpIndicatorValue) => {
  valueFor.value = { indicator, value }
  valueFile.value = null
  valueForm.reset(value ? { period_start: value.period_start, period_end: value.period_end, value: value.value, note: value.note ?? '' } : { period_start: '', period_end: today(), value: null as unknown as number, note: '' })
}
const saveValue = () => valueForm.submit(async (v) => {
  const target = valueFor.value
  if (!target) return
  const fd = new FormData()
  fd.append('period_start', v.period_start)
  fd.append('period_end', v.period_end)
  fd.append('value', String(v.value))
  if (v.note?.trim()) fd.append('note', v.note.trim())
  if (valueFile.value) fd.append('file', valueFile.value)
  if (target.value) await api.post(`${base.value}/indicator-values/${target.value.id}/resubmit`, fd)
  else await api.post(`${base.value}/indicators/${target.indicator.id}/values`, fd)
  valueFor.value = null
  toastSuccess(t('notify.submitted', { item: `${ent('value')} «${target.indicator.name}»` }))
  await refresh()
})

// ---------------------------------------------------------------- ENT-10 updates
const updateFile = ref<File | null>(null)
const updateForm = useZodForm(
  z.object({
    title: optionalText(R.titleMax).refine((v) => !v?.trim() || v.trim().length >= R.titleMin, { error: () => vmsg('minLength', { n: R.titleMin }) }),
    body: requiredString('followUp.updates.body', R.updateBodyMin, R.updateBodyMax),
    include_indicators: z.boolean(),
  }),
  { title: '', body: '', include_indicators: true },
)
const postUpdate = () => updateForm.submit(async (v) => {
  const fd = new FormData()
  if (v.title?.trim()) fd.append('title', v.title.trim())
  fd.append('body', v.body.trim())
  fd.append('include_indicators', v.include_indicators ? '1' : '0')
  if (updateFile.value) fd.append('file', updateFile.value)
  const r = await api.post<{ data: { status: string } }>(`${base.value}/updates`, fd)
  const title = v.title?.trim() || t('followUp.updates.untitled')
  updateForm.reset()
  updateFile.value = null
  toastSuccess(r.data?.status === 'pending_review' ? t('followUp.toast.heldForReview', { item: `${ent('update')} «${title}»` }) : t('followUp.toast.published', { item: `${ent('update')} «${title}»` }))
  await refresh()
})

// ---------------------------------------------------------------- PRJ-03 remediation
const remForm = useZodForm(z.object({ remediation_plan: requiredString('followUp.remediation.field', R.remediationMin, R.remediationMax) }), { remediation_plan: '' })
watch(() => fu.value?.remediation_plan, (v) => { if (v != null && !remForm.values.remediation_plan) remForm.reset({ remediation_plan: v }) }, { immediate: true })
const saveRemediation = () => remForm.submit(async (v) => {
  const r = await api.put<{ data: FollowUpDetail }>(`${base.value}/remediation`, { remediation_plan: v.remediation_plan.trim() })
  data.value = r.data
  toastSuccess(t('notify.savedItem', { item: ent('remediation') }))
})
</script>

<template>
  <div>
    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <template v-else-if="data">
      <PageHeader :title="data.name" :eyebrow="$t('followUp.owner.title')" :sub="data.reference_no ?? undefined">
        <template #actions><AppButton variant="ghost" size="sm" to="/app/entrepreneur/follow-up">{{ $t('common.back') }}</AppButton></template>
      </PageHeader>

      <div class="space-y-6">
        <FollowUpStatusPanel :detail="data" audience="owner" />

        <SectionCard v-if="isLate || fu?.remediation_plan" :title="$t('followUp.remediation.title')" :sub="$t('followUp.remediation.sub')">
          <form class="space-y-3" novalidate @submit.prevent="saveRemediation">
            <FormField v-model="remForm.values.remediation_plan" name="remediation_plan" type="textarea" :label="$t('followUp.remediation.field')" :error="remForm.errors.value.remediation_plan" :rows="4" :maxlength="R.remediationMax" required />
            <p v-if="fu?.remediation_updated_at" class="text-xs text-muted num">{{ $t('followUp.remediation.updatedAt', { date: dateTime(fu.remediation_updated_at) }) }}</p>
            <FormAlert />
            <AppButton type="submit" size="sm" :loading="remForm.submitting.value">{{ $t('followUp.remediation.save') }}</AppButton>
          </form>
        </SectionCard>

        <div>
          <Tabs v-model="tab" :tabs="tabs" />

          <!-- PRJ-01 -->
          <div v-show="tab === 'plan'" id="panel-plan" role="tabpanel" aria-labelledby="tab-plan" class="mt-5">
            <SectionCard :title="$t('followUp.plan.title')" :sub="$t('followUp.plan.ownerSub')">
              <template #actions>
                <AppButton v-if="planEditable" size="sm" variant="outline" @click="openMilestone()">+ {{ $t('followUp.plan.add') }}</AppButton>
                <AppButton v-if="planEditable" size="sm" :disabled="!data.milestones_list.length" :loading="busy === 'submit'" @click="planAction('submit')">{{ $t('followUp.plan.submit') }}</AppButton>
                <AppButton v-if="fu?.plan_status === 'submitted' || planApproved" size="sm" variant="ghost" :loading="busy === 'revise'" @click="planAction('revise')">{{ $t('followUp.plan.revise') }}</AppButton>
              </template>
              <div class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                <FollowUpBadge group="plan" :value="fu?.plan_status" />
                <span v-if="fu?.plan_version" class="text-muted num">{{ $t('followUp.plan.version', { n: number(fu.plan_version) }) }}</span>
                <span v-if="data.budget_share_total != null" class="text-muted num">{{ $t('followUp.plan.budgetTotal', { n: number(data.budget_share_total) }) }}</span>
              </div>
              <InlineAlert v-if="fu?.plan_review_note && fu.plan_status === 'changes_requested'" class="mb-4" tone="warning" :title="$t('followUp.plan.changesRequested')">{{ fu.plan_review_note }}</InlineAlert>
              <InlineAlert v-else-if="fu?.plan_status === 'submitted'" class="mb-4">{{ $t('followUp.plan.locked') }}</InlineAlert>
              <InlineAlert v-else-if="planApproved" class="mb-4" tone="success">{{ $t('followUp.plan.approvedHint') }}</InlineAlert>
              <InlineAlert v-else-if="fu?.plan_status === 'draft' && fu.plan_version" class="mb-4" tone="warning">{{ $t('followUp.plan.revisionHint') }}</InlineAlert>
              <FollowUpMilestones :milestones="data.milestones_list" :download-base="DOCS">
                <template #actions="{ milestone }">
                  <template v-if="planEditable && milestone.status !== 'done'">
                    <AppButton size="sm" variant="ghost" @click="openMilestone(milestone)">{{ $t('common.edit') }}</AppButton>
                    <AppButton size="sm" variant="ghost" class="text-danger" :loading="busy === `del-${milestone.id}`" @click="deleteMilestone(milestone)">{{ $t('common.delete') }}</AppButton>
                  </template>
                  <AppButton v-if="planApproved && milestone.status !== 'done'" size="sm" variant="outline" @click="openDone(milestone)">{{ $t('followUp.milestone.markDone') }}</AppButton>
                </template>
              </FollowUpMilestones>
            </SectionCard>
          </div>

          <!-- PRJ-02 -->
          <div v-show="tab === 'reports'" id="panel-reports" role="tabpanel" aria-labelledby="tab-reports" class="mt-5 space-y-5">
            <SectionCard :title="$t('followUp.reports.schedule')" flush>
              <template v-if="openPeriods.length" #actions><AppButton size="sm" @click="openReport()">{{ $t('followUp.reports.submit') }}</AppButton></template>
              <div v-if="data.periods?.length" class="overflow-x-auto">
                <table class="table-base">
                  <thead><tr><th>{{ $t('followUp.reports.period') }}</th><th>{{ $t('followUp.reports.dueOn') }}</th><th>{{ $t('followUp.reports.state') }}</th></tr></thead>
                  <tbody>
                    <tr v-for="p in data.periods" :key="p.period_start">
                      <td class="num">{{ periodText(p) }}</td>
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
                  <template v-if="r.status === 'changes_requested'" #actions><AppButton size="sm" @click="openReport(r)">{{ $t('followUp.reports.resubmit') }}</AppButton></template>
                </FollowUpReportCard>
              </div>
              <p v-else class="text-sm text-muted">{{ $t('followUp.reports.empty') }}</p>
            </SectionCard>
          </div>

          <!-- IMP-01/02 -->
          <div v-show="tab === 'impact'" id="panel-impact" role="tabpanel" aria-labelledby="tab-impact" class="mt-5">
            <SectionCard :title="$t('followUp.impact.title')" :sub="$t('followUp.impact.ownerSub')">
              <div v-if="data.indicators.length" class="space-y-5">
                <FollowUpIndicatorCard v-for="i in data.indicators" :key="i.id" :indicator="i" :download-base="DOCS">
                  <template #header><AppButton size="sm" variant="outline" @click="openValue(i)">+ {{ $t('followUp.impact.addValue') }}</AppButton></template>
                  <template #value-actions="{ value }"><AppButton v-if="value.status === 'needs_clarification'" size="sm" variant="outline" @click="openValue(i, value)">{{ $t('followUp.impact.resubmit') }}</AppButton></template>
                </FollowUpIndicatorCard>
              </div>
              <EmptyState v-else compact :title="$t('followUp.impact.noIndicators')" :text="$t('followUp.impact.noIndicatorsOwner')" />
            </SectionCard>
          </div>

          <!-- ENT-10 -->
          <div v-show="tab === 'updates'" id="panel-updates" role="tabpanel" aria-labelledby="tab-updates" class="mt-5 space-y-5">
            <SectionCard :title="$t('followUp.updates.new')" :sub="data.updates_require_review ? $t('followUp.updates.reviewHint') : $t('followUp.updates.directHint', { n: number(data.connected_investors ?? 0) })">
              <form class="space-y-4" novalidate @submit.prevent="postUpdate">
                <FormField v-model="updateForm.values.title" name="title" :label="$t('followUp.updates.titleField')" :error="updateForm.errors.value.title" :maxlength="R.titleMax" />
                <FormField v-model="updateForm.values.body" name="body" type="textarea" :label="$t('followUp.updates.body')" :error="updateForm.errors.value.body" :rows="5" :maxlength="R.updateBodyMax" required />
                <FormField v-model="updateForm.values.include_indicators" name="include_indicators" type="checkbox" :label="$t('followUp.updates.includeIndicators')" />
                <FileUpload :label="$t('followUp.updates.attachment')" :max-mb="maxMb" @file="(f) => (updateFile = f)" @error="toastError" />
                <p v-if="updateFile" class="text-sm flex items-center gap-2">{{ updateFile.name }} <button type="button" class="text-danger text-xs kbd-focus rounded" @click="updateFile = null">{{ $t('common.delete') }}</button></p>
                <FormAlert />
                <AppButton type="submit" :loading="updateForm.submitting.value">{{ $t('followUp.updates.post') }}</AppButton>
              </form>
            </SectionCard>
            <SectionCard :title="$t('followUp.updates.title')">
              <div v-if="data.updates.length" class="space-y-4"><FollowUpUpdateCard v-for="u in data.updates" :key="u.id" :update="u" :download-base="DOCS" show-status /></div>
              <p v-else class="text-sm text-muted">{{ $t('followUp.updates.empty') }}</p>
            </SectionCard>
          </div>
        </div>
      </div>

      <!-- milestone add / edit -->
      <Modal :open="msOpen" :title="msEditing ? $t('followUp.plan.edit') : $t('followUp.plan.add')" size="lg" @close="msOpen = false">
        <form class="space-y-4" novalidate @submit.prevent="saveMilestone">
          <FormField v-model="msForm.values.title" name="title" :label="$t('followUp.milestone.title')" :error="msForm.errors.value.title" :maxlength="R.titleMax" required />
          <FormField v-model="msForm.values.deliverable" name="deliverable" type="textarea" :label="$t('followUp.milestone.deliverable')" :error="msForm.errors.value.deliverable" :rows="2" :maxlength="R.deliverableMax" required />
          <div class="grid sm:grid-cols-3 gap-3">
            <FormField v-model="msForm.values.due_at" name="due_at" type="date" :label="$t('followUp.milestone.dueAt')" :error="msForm.errors.value.due_at" required ltr />
            <FormField v-model="msForm.values.responsible" name="responsible" :label="$t('followUp.milestone.responsible')" :error="msForm.errors.value.responsible" :maxlength="R.responsibleMax" />
            <FormField v-model="msForm.values.budget_share" name="budget_share" type="number" :label="$t('followUp.milestone.budgetShare')" :error="msForm.errors.value.budget_share" :min="0" :max="R.budgetShareMax" :step="0.5" ltr />
          </div>
          <FormField v-model="msForm.values.description" name="description" type="textarea" :label="$t('followUp.milestone.description')" :error="msForm.errors.value.description" :rows="3" :maxlength="R.descriptionMax" />
        </form>
        <template #footer><FormAlert class="basis-full" /><AppButton variant="ghost" @click="msOpen = false">{{ $t('common.cancel') }}</AppButton><AppButton :loading="msForm.submitting.value" @click="saveMilestone">{{ $t('common.save') }}</AppButton></template>
      </Modal>

      <!-- milestone done + evidence -->
      <Modal :open="!!doneFor" :title="$t('followUp.milestone.markDoneTitle')" @close="doneFor = null">
        <form class="space-y-4" novalidate @submit.prevent="saveDone">
          <p class="text-sm font-medium">{{ doneFor?.title }}</p>
          <FileUpload :label="$t('followUp.milestone.evidence')" :max-mb="maxMb" :error="doneForm.errors.value.file_name" @file="pickDoneFile" @error="toastError" />
          <p v-if="doneFile" class="text-sm">{{ doneFile.name }}</p>
          <p class="text-xs text-muted">{{ $t('followUp.milestone.markDoneHint') }}</p>
          <FormField v-model="doneForm.values.note" name="note" type="textarea" :label="$t('followUp.milestone.evidenceNote')" :error="doneForm.errors.value.note" :rows="3" :maxlength="R.evidenceNoteMax" />
        </form>
        <template #footer><FormAlert class="basis-full" /><AppButton variant="ghost" @click="doneFor = null">{{ $t('common.cancel') }}</AppButton><AppButton :loading="doneForm.submitting.value" @click="saveDone">{{ $t('followUp.milestone.markDone') }}</AppButton></template>
      </Modal>

      <!-- progress report -->
      <Modal :open="reportOpen" :title="reportEditing ? $t('followUp.reports.resubmitTitle') : $t('followUp.reports.formTitle')" size="lg" @close="reportOpen = false">
        <form class="space-y-4" novalidate @submit.prevent="saveReport">
          <FormField v-if="!reportEditing" v-model="reportForm.values.period_start" name="period_start" type="select" :label="$t('followUp.reports.period')" :options="periodOptions" :error="reportForm.errors.value.period_start" required />
          <p v-else class="text-sm"><span class="text-muted">{{ $t('followUp.reports.period') }}:</span> <span class="num font-medium">{{ periodText(reportEditing) }}</span></p>
          <InlineAlert v-if="reportEditing?.review_note" tone="warning" :title="$t('followUp.reports.reviewNote')">{{ reportEditing.review_note }}</InlineAlert>
          <FormField v-model="reportForm.values.narrative" name="narrative" type="textarea" :label="$t('followUp.reports.narrative')" :error="reportForm.errors.value.narrative" :rows="3" :maxlength="R.longTextMax" required />
          <FormField v-model="reportForm.values.achieved" name="achieved" type="textarea" :label="$t('followUp.reports.achieved')" :error="reportForm.errors.value.achieved" :rows="3" :maxlength="R.longTextMax" required />
          <div class="grid sm:grid-cols-2 gap-3">
            <FormField v-model="reportForm.values.delayed" name="delayed" type="textarea" :label="$t('followUp.reports.delayed')" :error="reportForm.errors.value.delayed" :rows="3" :maxlength="R.longTextMax" />
            <FormField v-model="reportForm.values.risks" name="risks" type="textarea" :label="$t('followUp.reports.risks')" :error="reportForm.errors.value.risks" :rows="3" :maxlength="R.longTextMax" />
          </div>
          <FormField v-model="reportForm.values.support_needed" name="support_needed" type="textarea" :label="$t('followUp.reports.supportNeeded')" :error="reportForm.errors.value.support_needed" :rows="2" :maxlength="R.longTextMax" />
          <div class="grid sm:grid-cols-2 gap-3">
            <FormField v-model="reportForm.values.spend_amount" name="spend_amount" type="number" :label="$t('followUp.reports.spend')" :error="reportForm.errors.value.spend_amount" :min="0" :step="1000" ltr />
            <FormField v-model="reportForm.values.execution_status" name="execution_status" type="select" :label="$t('followUp.reports.selfStatus')" :options="selfStatusOptions" :error="reportForm.errors.value.execution_status" />
          </div>
          <div v-if="reportForm.values.milestone_updates.length">
            <p class="label">{{ $t('followUp.reports.milestoneUpdates') }}</p>
            <div v-for="(u, i) in reportForm.values.milestone_updates" :key="u.milestone_id" class="mb-2 grid grid-cols-1 sm:grid-cols-[1fr_160px_1fr] gap-2 items-start">
              <span class="text-sm pt-2.5">{{ u.title }}</span>
              <select v-model="u.status" class="input" :aria-label="`${$t('common.status')} — ${u.title}`"><option value="">{{ $t('followUp.reports.noChange') }}</option><option v-for="o in msStatusOptions" :key="o.value" :value="o.value">{{ o.label }}</option></select>
              <input v-model="u.note" class="input" :class="reportForm.errors.value[`milestone_updates.${i}.note`] && 'input-error'" :maxlength="R.milestoneNoteMax" :placeholder="$t('followUp.reports.milestoneNote')" :aria-label="`${$t('followUp.reports.milestoneNote')} — ${u.title}`" />
            </div>
          </div>
          <div>
            <FileUpload :label="$t('followUp.reports.attachments')" :max-mb="maxMb" :disabled="reportFiles.length >= R.reportFilesMax" @file="addReportFile" @error="toastError" />
            <p class="help">{{ $t('followUp.reports.filesMax', { n: R.reportFilesMax }) }}</p>
            <ul v-if="reportFiles.length" class="mt-2 flex flex-wrap gap-1.5"><li v-for="(f, i) in reportFiles" :key="`${f.name}-${i}`" class="chip text-xs !py-0.5 inline-flex items-center gap-1">{{ f.name }}<button type="button" class="ms-1" :aria-label="`${$t('common.delete')} ${f.name}`" @click="reportFiles.splice(i, 1)">×</button></li></ul>
          </div>
        </form>
        <template #footer><FormAlert class="basis-full" /><AppButton variant="ghost" @click="reportOpen = false">{{ $t('common.cancel') }}</AppButton><AppButton :loading="reportForm.submitting.value" @click="saveReport">{{ reportEditing ? $t('followUp.reports.resubmit') : $t('followUp.reports.submit') }}</AppButton></template>
      </Modal>

      <!-- indicator value -->
      <Modal :open="!!valueFor" :title="valueFor?.value ? $t('followUp.impact.resubmit') : $t('followUp.impact.addValue')" @close="valueFor = null">
        <form class="space-y-4" novalidate @submit.prevent="saveValue">
          <p class="text-sm font-medium">{{ valueFor?.indicator.name }}<span v-if="valueFor?.indicator.unit" class="text-muted font-normal"> ({{ valueFor.indicator.unit }})</span></p>
          <InlineAlert v-if="valueFor?.value?.review_note" tone="warning" :title="$t('followUp.impact.reviewNote')">{{ valueFor.value.review_note }}</InlineAlert>
          <div class="grid sm:grid-cols-2 gap-3">
            <FormField v-model="valueForm.values.period_start" name="period_start" type="date" :label="$t('followUp.impact.periodStart')" :error="valueForm.errors.value.period_start" required ltr />
            <FormField v-model="valueForm.values.period_end" name="period_end" type="date" :label="$t('followUp.impact.periodEnd')" :error="valueForm.errors.value.period_end" required ltr />
          </div>
          <FormField v-model="valueForm.values.value" name="value" type="number" :label="$t('followUp.impact.value')" :error="valueForm.errors.value.value" required ltr />
          <FormField v-model="valueForm.values.note" name="note" type="textarea" :label="$t('followUp.impact.note')" :error="valueForm.errors.value.note" :rows="2" :maxlength="R.noteMax" />
          <FileUpload :label="$t('followUp.impact.evidence')" :max-mb="maxMb" @file="(f) => (valueFile = f)" @error="toastError" />
          <p v-if="valueFile" class="text-sm">{{ valueFile.name }}</p>
        </form>
        <template #footer><FormAlert class="basis-full" /><AppButton variant="ghost" @click="valueFor = null">{{ $t('common.cancel') }}</AppButton><AppButton :loading="valueForm.submitting.value" @click="saveValue">{{ $t('common.send') }}</AppButton></template>
      </Modal>
    </template>
  </div>
</template>

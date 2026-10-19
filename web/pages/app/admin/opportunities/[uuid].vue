<script setup lang="ts">
/** Admin opportunity file — full record, timeline, internal notes, transitions, completion builder, document review,
 *  teaser editor/approval, evaluation assign/score/decide, nominations (ADM-01..05, EVL-01..03, MAT-01..05, DOC-03). */
import type { OpportunityFull, StatusTransition, InternalNote, CompletionRequest, EvaluationPanel, Candidate, Nomination, Interest, Teaser, DocumentResource } from '~/types/api'
import type { OpportunityStatus, EvaluationResult, DocumentStatus } from '~/types/enums'
import { EVALUATION_RESULTS, DOCUMENT_KINDS } from '~/types/enums'

definePageMeta({ layout: 'portal', middleware: ['role'] })
type Full = OpportunityFull & { timeline: StatusTransition[]; notes: InternalNote[]; completion_requests: CompletionRequest[]; evaluation: EvaluationPanel; candidates: Candidate[]; nominations: Nomination[]; interests: Interest[]; allowed_transitions: OpportunityStatus[]; reviewers: { id: number; name: string }[]; evaluators: { id: number; name: string }[]; max_nominations: number; teaser: Teaser | null }
const { t } = useI18n()
const api = useApi()
const auth = useAuthStore()
const route = useRoute()
const toast = useToast()
const tax = useTaxonomyStore()
await tax.load().catch(() => {})
const { label, term } = useEnumLabel()
const { number, sar, date, dateTime, fileSize } = useFormat()
const uuid = route.params.uuid as string
const { data: o, loading, refresh } = useAsyncResource(() => api.get<{ data: Full }>(`/admin/opportunities/${uuid}`).then((r) => r.data))
watch(o, (v) => v && useHead({ title: `${v.reference_no ?? ''} ${v.name}` }))
const tab = ref((route.query.tab as string) ?? 'file')
const tabs = computed(() => [
  { key: 'file', label: t('admin.opportunity.tabs.file') },
  { key: 'timeline', label: t('admin.opportunity.tabs.timeline'), count: o.value?.timeline.length },
  { key: 'notes', label: t('admin.opportunity.tabs.notes'), count: o.value?.notes.length },
  { key: 'documents', label: t('admin.opportunity.tabs.documents'), count: o.value?.documents.length },
  { key: 'completion', label: t('admin.opportunity.tabs.completion'), count: o.value?.completion_requests.length },
  { key: 'teaser', label: t('admin.opportunity.tabs.teaser') },
  { key: 'evaluation', label: t('admin.opportunity.tabs.evaluation') },
  { key: 'nominations', label: t('admin.opportunity.tabs.nominations'), count: o.value?.nominations.length },
])
const busy = ref<string | null>(null)
const run = async (key: string, fn: () => Promise<unknown>, ok = t('common.success')) => {
  busy.value = key
  try { await fn(); toast.success(ok); await refresh() } catch (e) { const err = e as { errors?: Record<string, string[]>; message: string }; toast.error(err.errors ? Object.values(err.errors).flat().join(' · ') : err.message) } finally { busy.value = null }
}
// transition
const transitionOpen = ref(false); const toStatus = ref<OpportunityStatus | ''>(''); const reason = ref('')
const transition = () => run('transition', () => api.post(`/admin/opportunities/${uuid}/transition`, { to: toStatus.value, reason: reason.value || undefined })).then(() => { transitionOpen.value = false; reason.value = '' })
// assign
const reviewerId = ref('')
const assign = () => run('assign', () => api.post(`/admin/opportunities/${uuid}/assign`, { reviewer_user_id: Number(reviewerId.value) }))
// notes
const noteBody = ref('')
const addNote = () => run('note', () => api.post(`/admin/opportunities/${uuid}/notes`, { body: noteBody.value })).then(() => (noteBody.value = ''))
// completion builder
const crItems = ref<{ kind: 'field' | 'document'; key: string; note: string }[]>([{ kind: 'document', key: 'pitch_deck', note: '' }])
const crDue = ref('')
const sendCompletion = () => run('completion', () => api.post(`/admin/opportunities/${uuid}/completion-requests`, { items: crItems.value.map((i) => (i.kind === 'document' ? { document_kind: i.key, note: i.note } : { field: i.key, note: i.note })), due_at: crDue.value ? new Date(crDue.value).toISOString() : undefined, reason: 'طلب استكمال' })).then(() => (crItems.value = [{ kind: 'document', key: 'pitch_deck', note: '' }]))
// documents
const docNote = reactive<Record<string, string>>({})
const reviewDoc = (d: DocumentResource, status: DocumentStatus) => run(`doc-${d.uuid}`, () => api.post(`/admin/opportunities/${uuid}/documents/${d.uuid}/review`, { status, note: docNote[d.uuid] }))
// teaser
const teaser = reactive({ summary_ar: '', summary_en: '', highlights: '' })
watch(o, (v) => { if (v?.teaser) { teaser.summary_ar = v.teaser.summary_ar; teaser.summary_en = v.teaser.summary_en ?? ''; teaser.highlights = v.teaser.highlights.join('\n') } else if (v) { teaser.summary_ar = v.short_description } })
const saveTeaser = () => run('teaser', () => api.post(`/admin/opportunities/${uuid}/teaser`, { summary_ar: teaser.summary_ar, summary_en: teaser.summary_en || null, highlights: teaser.highlights.split('\n').map((h) => h.trim()).filter(Boolean) }))
const approveTeaser = () => run('teaser-approve', () => api.post(`/admin/opportunities/${uuid}/teaser/approve`))
// evaluation
const ev1 = ref(''); const ev2 = ref('')
const assignEvaluators = () => run('ev-assign', () => api.post(`/admin/opportunities/${uuid}/evaluation/assign`, { evaluator_ids: [Number(ev1.value), Number(ev2.value)] }))
const myAssignment = computed(() => o.value?.evaluation.assignments.find((a) => a.evaluator.id === (auth.me ? Number((auth.me.uuid.match(/^u-(\d+)/)?.[1] ?? 0)) : 0) || (auth.me?.email.includes('evaluator') && a.evaluator.name === auth.me?.name)))
const scoreForm = reactive<{ scores: Record<string, { score: number; note: string }>; recommendation: EvaluationResult | ''; comment: string }>({ scores: {}, recommendation: '', comment: '' })
const scoringId = ref<number | null>(null)
const openScore = (id: number) => { scoringId.value = id; o.value?.evaluation.criteria.forEach((c) => (scoreForm.scores[c.key] ??= { score: 0, note: '' })) }
const submitScore = () => run('score', () => api.post(`/admin/evaluation-assignments/${scoringId.value}/score`, scoreForm)).then(() => (scoringId.value = null))
const coi = (id: number, has: boolean) => run(`coi-${id}`, () => api.post(`/admin/evaluation-assignments/${id}/coi`, { has_conflict: has }))
const decision = reactive<{ result: EvaluationResult | ''; conditions: string; reasons: string }>({ result: '', conditions: '', reasons: '' })
const decide = () => run('decide', () => api.post(`/admin/opportunities/${uuid}/evaluation/decide`, decision))
// nominations
const selected = ref<string[]>([]); const rationale = ref('')
const nominate = () => run('nominate', () => api.post(`/admin/opportunities/${uuid}/nominations`, { investor_user_ids: selected.value, rationale: rationale.value })).then(() => { selected.value = []; rationale.value = '' })
const axes = ['readiness', 'feasibility', 'impact'] as const
const criteria5 = ['sector', 'stage', 'region', 'type', 'range'] as const
const canTransition = computed(() => auth.hasRole('reviewer', 'matching_officer'))
const canEvaluate = computed(() => auth.hasRole('evaluator'))
const canDecide = computed(() => auth.hasRole('reviewer'))
const canMatch = computed(() => auth.hasRole('matching_officer'))
</script>

<template>
  <div>
    <div v-if="loading && !o" class="text-muted">{{ $t('common.loading') }}</div>
    <template v-else-if="o">
      <nav class="text-xs text-muted mb-3" aria-label="breadcrumb"><NuxtLink :to="$localePath('/app/admin/opportunities')" class="hover:text-blue">{{ $t('admin.opportunities.title') }}</NuxtLink> / <span class="num">{{ o.reference_no ?? '—' }}</span></nav>
      <header class="card card-pad">
        <div class="flex flex-col lg:flex-row lg:items-start gap-4">
          <div class="flex-1 min-w-0">
            <div class="flex flex-wrap items-center gap-2"><AppBadge kind="opportunity" :value="o.status" :soft="false" size="md" /><AppBadge v-if="o.is_overdue" kind="generic" value="stalled" :label="$t('common.overdue')" /><span class="text-sm text-muted">{{ term(tax.byId('sectors', o.sector_id)) }} · {{ label('stage', o.stage) }}</span></div>
            <h1 class="mt-2 text-2xl font-semibold">{{ o.name }}</h1>
            <p class="text-sm text-muted">{{ o.owner?.name }} · {{ o.organization?.name ?? label('userType', o.owner?.type) }}</p>
          </div>
          <div class="flex flex-wrap gap-2 shrink-0">
            <AppButton v-if="canTransition && o.allowed_transitions.length" @click="transitionOpen = true">{{ $t('admin.opportunity.transition') }}</AppButton>
            <div v-if="canTransition" class="flex gap-1"><select v-model="reviewerId" class="input w-44" :aria-label="$t('admin.opportunity.assignTo')"><option value="">{{ $t('admin.opportunity.assignTo') }}</option><option v-for="r in o.reviewers" :key="r.id" :value="String(r.id)">{{ r.name }}</option></select><AppButton variant="secondary" :disabled="!reviewerId" :loading="busy === 'assign'" @click="assign">{{ $t('admin.opportunity.assign') }}</AppButton></div>
          </div>
        </div>
        <dl class="mt-4 grid grid-cols-2 lg:grid-cols-5 gap-3 text-sm">
          <div><dt class="text-xs text-muted">{{ $t('admin.dashboard.assignee') }}</dt><dd class="font-medium">{{ o.assigned_reviewer?.name ?? '—' }}</dd></div>
          <div><dt class="text-xs text-muted">{{ $t('entrepreneur.dashboard.submittedAt') }}</dt><dd class="font-medium">{{ date(o.submitted_at) }}</dd></div>
          <div><dt class="text-xs text-muted">{{ $t('admin.dashboard.sla') }}</dt><dd class="font-medium">{{ date(o.review_due_at ?? o.completion_due_at ?? o.evaluation_due_at) }}</dd></div>
          <div><dt class="text-xs text-muted">{{ $t('admin.dashboard.recommendation') }}</dt><dd><AppBadge v-if="o.evaluation_result" kind="eval" :value="o.evaluation_result" /><span v-else>—</span></dd></div>
          <div><dt class="text-xs text-muted">{{ $t('admin.opportunity.isListed') }}</dt><dd class="font-medium">{{ o.is_listed ? $t('common.yes') : $t('common.no') }}</dd></div>
        </dl>
      </header>

      <Tabs v-model="tab" :tabs="tabs" class="mt-6" />
      <div class="mt-5">
        <!-- FILE -->
        <div v-show="tab === 'file'" class="grid lg:grid-cols-2 gap-5">
          <SectionCard :title="$t('admin.opportunity.ownerInfo')" :sub="label('sensitivity', 'sensitive_personal')">
            <KeyValue :items="[{ label: $t('common.name'), value: o.owner?.name }, { label: $t('common.email'), value: o.owner?.email, ltr: true }, { label: $t('common.phone'), value: o.owner?.phone, ltr: true, num: true }, { label: $t('auth.register.orgName'), value: o.organization?.name }, { label: $t('entrepreneur.form.organization.legalForm'), value: label('legalForm', o.organization?.legal_form) }, { label: $t('entrepreneur.form.organization.cr'), value: o.organization?.cr_number, ltr: true }]" />
          </SectionCard>
          <SectionCard :title="$t('admin.opportunity.financials')" :sub="label('sensitivity', 'confidential')">
            <KeyValue :items="[{ label: $t('entrepreneur.form.financials.fundingAmount'), value: sar(o.funding_amount), num: true }, { label: $t('entrepreneur.form.project.revenueState'), value: label('revenueState', o.revenue_state) }, { label: $t('entrepreneur.form.financials.priorFunding'), value: o.prior_funding ? `${$t('common.yes')} — ${o.prior_funding_desc ?? ''}` : $t('common.no') }]" />
            <ul class="mt-3 text-sm space-y-1"><li v-for="u in o.use_of_funds" :key="u.item" class="flex justify-between"><span>{{ u.item }}</span><span class="num text-muted">{{ number(u.percent) }}%</span></li></ul>
          </SectionCard>
          <SectionCard :title="$t('investor.detail.tabs.overview')" :sub="label('sensitivity', 'public')" class="lg:col-span-2">
            <div class="grid md:grid-cols-2 gap-5 text-sm">
              <div><h4 class="font-medium">{{ $t('investor.detail.problem') }}</h4><p class="text-text/85 leading-6 mt-1">{{ o.problem_statement || '—' }}</p></div>
              <div><h4 class="font-medium">{{ $t('investor.detail.solution') }}</h4><p class="text-text/85 leading-6 mt-1">{{ o.solution || '—' }}</p></div>
              <div><h4 class="font-medium">{{ $t('investor.detail.expectedChange') }}</h4><p class="text-text/85 leading-6 mt-1">{{ o.expected_change || '—' }}</p></div>
              <div><h4 class="font-medium">{{ $t('investor.detail.beneficiariesDesc') }}</h4><p class="text-text/85 leading-6 mt-1">{{ o.beneficiaries_desc || '—' }} <span class="num text-muted">({{ number(o.beneficiaries_current) }} → {{ number(o.beneficiaries_target) }})</span></p></div>
            </div>
            <div class="mt-5 overflow-x-auto"><table class="table-base"><thead><tr><th>{{ $t('entrepreneur.form.impact.indicator') }}</th><th>{{ $t('entrepreneur.form.impact.baseline') }}</th><th>{{ $t('entrepreneur.form.impact.target') }}</th><th>{{ $t('entrepreneur.form.impact.source') }}</th><th>{{ $t('entrepreneur.form.impact.disclosed') }}</th></tr></thead><tbody><tr v-for="i in o.indicators" :key="i.name"><td class="font-medium">{{ i.name }} <span v-if="i.is_primary" class="text-[10px] rounded-full bg-blue-soft text-blue px-1.5">{{ $t('entrepreneur.form.impact.primary') }}</span></td><td class="num">{{ number(i.baseline) }} {{ i.unit }}</td><td class="num">{{ number(i.target) }} {{ i.unit }}</td><td class="text-muted">{{ i.data_source }}</td><td>{{ i.is_disclosed ? $t('common.yes') : $t('common.no') }}</td></tr></tbody></table></div>
          </SectionCard>
          <SectionCard :title="$t('admin.opportunity.disclosures')" :sub="label('sensitivity', 'confidential')" class="lg:col-span-2">
            <KeyValue :cols="2" :items="[{ label: $t('entrepreneur.form.disclosures.legalDisputes'), value: o.legal_disputes ? `${$t('common.yes')} — ${o.legal_disputes_desc ?? ''}` : $t('common.no') }, { label: $t('entrepreneur.form.disclosures.licenseRequired'), value: o.license_required ? `${$t('common.yes')} — ${o.license_status ?? ''}` : $t('common.no') }, { label: $t('entrepreneur.form.disclosures.operationalRisks'), value: o.operational_risks }, { label: $t('entrepreneur.form.disclosures.councilRelationship') + ' (' + label('sensitivity', 'internal') + ')', value: o.council_relationship ? `${$t('common.yes')} — ${o.council_relationship_desc ?? ''}` : $t('common.no') }, { label: $t('entrepreneur.form.disclosures.dataAccuracy'), value: date(o.data_accuracy_declared_at) }, { label: $t('entrepreneur.form.disclosures.teaserConsent'), value: o.teaser_publication_consent ? $t('common.yes') : $t('common.no') }]" />
          </SectionCard>
        </div>
        <!-- TIMELINE -->
        <SectionCard v-show="tab === 'timeline'" :title="$t('admin.opportunity.tabs.timeline')"><Timeline :items="o.timeline" /></SectionCard>
        <!-- NOTES -->
        <div v-show="tab === 'notes'" class="grid lg:grid-cols-[1fr_360px] gap-5">
          <SectionCard :title="$t('admin.opportunity.tabs.notes')" :sub="$t('admin.opportunity.noteHint')">
            <ul class="space-y-3"><li v-for="n in o.notes" :key="n.id" class="rounded-lg bg-bg p-3 text-sm"><p class="leading-6">{{ n.body }}</p><p class="text-xs text-muted mt-1">{{ n.author.name }} · {{ dateTime(n.created_at) }}</p></li><li v-if="!o.notes.length" class="text-sm text-muted">{{ $t('common.noData') }}</li></ul>
          </SectionCard>
          <SectionCard :title="$t('admin.opportunity.addNote')"><FormField v-model="noteBody" name="note" type="textarea" :label="$t('admin.opportunity.noteBody')" :rows="4" /><AppButton class="mt-3" :disabled="!noteBody" :loading="busy === 'note'" @click="addNote">{{ $t('admin.opportunity.addNote') }}</AppButton></SectionCard>
        </div>
        <!-- DOCUMENTS -->
        <SectionCard v-show="tab === 'documents'" :title="$t('admin.opportunity.tabs.documents')" flush>
          <div class="overflow-x-auto"><table class="table-base"><thead><tr><th>{{ $t('entrepreneur.form.documents.kind') }}</th><th>{{ $t('common.name') }}</th><th>{{ $t('common.status') }}</th><th>{{ $t('admin.opportunity.docNote') }}</th><th></th></tr></thead>
            <tbody><tr v-for="d in o.documents" :key="d.uuid"><td>{{ label('documentKind', d.kind) }}</td><td class="font-medium">{{ d.title }}<span class="block text-xs text-muted num">v{{ d.version }} · {{ fileSize(d.size_bytes) }} · {{ d.scan_result }}</span></td><td><AppBadge kind="document" :value="d.status" /></td><td><input v-model="docNote[d.uuid]" class="input text-xs" :placeholder="d.reviewer_note ?? $t('admin.opportunity.docNote')" :aria-label="$t('admin.opportunity.docNote')" /></td><td class="text-end whitespace-nowrap"><AppButton size="sm" variant="outline" :loading="busy === `doc-${d.uuid}`" @click="reviewDoc(d, 'accepted')">{{ $t('common.accept') }}</AppButton> <AppButton size="sm" variant="ghost" @click="reviewDoc(d, 'rejected')">{{ $t('common.reject') }}</AppButton></td></tr><tr v-if="!o.documents.length"><td colspan="5" class="text-center text-muted py-6">{{ $t('common.noData') }}</td></tr></tbody></table></div>
        </SectionCard>
        <!-- COMPLETION -->
        <div v-show="tab === 'completion'" class="grid lg:grid-cols-2 gap-5">
          <SectionCard :title="$t('admin.opportunity.tabs.completion')">
            <ul class="space-y-3"><li v-for="c in o.completion_requests" :key="c.id" class="rounded-lg border border-border p-3 text-sm"><div class="flex justify-between gap-2"><span class="text-xs text-muted">{{ c.requested_by?.name }} · {{ date(c.created_at) }}</span><AppBadge kind="generic" :value="c.resolved_at ? 'approved' : 'pending'" :label="c.resolved_at ? $t('common.success') : `${$t('common.dueAt')}: ${date(c.due_at)}`" /></div><ul class="mt-2 space-y-1"><li v-for="(it, i) in c.items" :key="i" class="flex gap-2"><span :class="it.done ? 'text-success' : 'text-warning'">●</span><span><strong>{{ it.document_kind ? label('documentKind', it.document_kind) : it.field }}</strong> — {{ it.note }}</span></li></ul></li><li v-if="!o.completion_requests.length" class="text-sm text-muted">{{ $t('common.noData') }}</li></ul>
          </SectionCard>
          <SectionCard v-if="canDecide" :title="$t('admin.opportunity.completionBuilder')">
            <div class="space-y-3">
              <div v-for="(it, i) in crItems" :key="i" class="grid grid-cols-[110px_1fr_auto] gap-2 items-start">
                <select v-model="it.kind" class="input" :aria-label="$t('admin.opportunity.completionItem')"><option value="document">{{ $t('entrepreneur.form.documents.title') }}</option><option value="field">{{ $t('common.name') }}</option></select>
                <div class="space-y-2"><select v-if="it.kind === 'document'" v-model="it.key" class="input" :aria-label="$t('entrepreneur.form.documents.kind')"><option v-for="k in DOCUMENT_KINDS" :key="k" :value="k">{{ label('documentKind', k) }}</option></select><input v-else v-model="it.key" class="input" :placeholder="$t('admin.opportunity.completionItem')" :aria-label="$t('admin.opportunity.completionItem')" /><input v-model="it.note" class="input" :placeholder="$t('admin.opportunity.completionNote')" :aria-label="$t('admin.opportunity.completionNote')" /></div>
                <AppButton variant="ghost" size="sm" icon :aria-label="$t('common.delete')" @click="crItems.splice(i, 1)">×</AppButton>
              </div>
              <AppButton variant="outline" size="sm" @click="crItems.push({ kind: 'field', key: '', note: '' })">+ {{ $t('admin.opportunity.addItem') }}</AppButton>
              <FormField v-model="crDue" name="due" type="date" :label="$t('admin.opportunity.completionDue')" ltr />
              <AppButton :loading="busy === 'completion'" :disabled="!crItems.length" @click="sendCompletion">{{ $t('admin.opportunity.sendCompletion') }}</AppButton>
            </div>
          </SectionCard>
        </div>
        <!-- TEASER -->
        <div v-show="tab === 'teaser'" class="grid lg:grid-cols-2 gap-5">
          <SectionCard :title="$t('admin.opportunity.tabs.teaser')" :sub="$t('admin.opportunity.teaserPublicOnly')">
            <div class="space-y-4">
              <FormField v-model="teaser.summary_ar" name="summary_ar" type="textarea" :label="$t('admin.opportunity.teaserSummaryAr')" :rows="4" :maxlength="500" required />
              <FormField v-model="teaser.summary_en" name="summary_en" type="textarea" :label="$t('admin.opportunity.teaserSummaryEn')" :rows="3" ltr />
              <FormField v-model="teaser.highlights" name="highlights" type="textarea" :label="$t('admin.opportunity.teaserHighlights')" :rows="3" help="سطر لكل نقطة" />
              <div class="flex gap-2"><AppButton :loading="busy === 'teaser'" @click="saveTeaser">{{ $t('admin.opportunity.saveTeaser') }}</AppButton><AppButton v-if="canMatch && o.teaser && !o.teaser.approved_by_council_at" variant="secondary" :loading="busy === 'teaser-approve'" @click="approveTeaser">{{ $t('admin.opportunity.approveTeaser') }}</AppButton></div>
            </div>
          </SectionCard>
          <SectionCard :title="$t('common.status')">
            <div v-if="o.teaser" class="space-y-3 text-sm">
              <p class="text-muted">{{ $t('common.version') }} <span class="num">{{ o.teaser.version }}</span></p>
              <div class="flex flex-wrap gap-2"><AppBadge kind="generic" :value="o.teaser.approved_by_owner_at ? 'approved' : 'pending'" :label="`${$t('admin.opportunity.ownerApproval')}: ${o.teaser.approved_by_owner_at ? date(o.teaser.approved_by_owner_at) : '—'}`" /><AppBadge kind="generic" :value="o.teaser.approved_by_council_at ? 'approved' : 'pending'" :label="`${$t('admin.opportunity.councilApproval')}: ${o.teaser.approved_by_council_at ? date(o.teaser.approved_by_council_at) : '—'}`" /></div>
              <div class="rounded-lg bg-bg p-3 leading-6">{{ o.teaser.summary_ar }}</div>
              <ul class="flex flex-wrap gap-1.5"><li v-for="h in o.teaser.highlights" :key="h" class="chip text-xs !py-0.5">{{ h }}</li></ul>
            </div>
            <p v-else class="text-sm text-muted">{{ $t('common.noData') }}</p>
          </SectionCard>
        </div>
        <!-- EVALUATION -->
        <div v-show="tab === 'evaluation'" class="space-y-5">
          <SectionCard v-if="!o.evaluation.assignments.length && canDecide" :title="$t('admin.opportunity.assignEvaluators')">
            <div class="grid sm:grid-cols-3 gap-3 items-end">
              <FormField v-model="ev1" name="ev1" type="select" :label="$t('admin.opportunity.evaluator1')" :options="o.evaluators.map((e) => ({ value: String(e.id), label: e.name }))" />
              <FormField v-model="ev2" name="ev2" type="select" :label="$t('admin.opportunity.evaluator2')" :options="o.evaluators.map((e) => ({ value: String(e.id), label: e.name }))" />
              <AppButton :disabled="!ev1 || !ev2 || ev1 === ev2" :loading="busy === 'ev-assign'" @click="assignEvaluators">{{ $t('admin.opportunity.assignEvaluators') }}</AppButton>
            </div>
          </SectionCard>
          <SectionCard v-if="o.evaluation.assignments.length" :title="$t('admin.dashboard.evaluationPanel')" flush>
            <div class="overflow-x-auto"><table class="table-base">
              <thead><tr><th>{{ $t('admin.dashboard.axis') }} / {{ $t('admin.criteria.title') }}</th><th v-for="(a, i) in o.evaluation.assignments" :key="a.id">{{ $t('admin.dashboard.evaluator', { n: i + 1 }) }}<span class="block text-[10px] font-normal normal-case text-muted">{{ a.evaluator.name }} · <AppBadge kind="generic" :value="a.status" :label="label('assignmentStatus', a.status)" :dot="false" /></span></th><th>{{ $t('admin.opportunity.diff') }}</th></tr></thead>
              <tbody>
                <template v-for="ax in axes" :key="ax">
                  <tr class="bg-bg/60"><td class="font-semibold">{{ label('evaluationAxis', ax) }}</td><td v-for="a in o.evaluation.assignments" :key="a.id" class="num font-semibold">{{ a.evaluation ? `${number(a.evaluation.axis_totals[ax])} / 5` : '—' }}</td><td /></tr>
                  <tr v-for="c in o.evaluation.criteria.filter((x) => x.axis === ax)" :key="c.key"><td class="ps-6 text-muted">{{ c.label_ar }} <span class="text-[10px] num">(×{{ c.weight }})</span></td><td v-for="a in o.evaluation.assignments" :key="a.id" class="num">{{ a.evaluation?.scores[c.key]?.score ?? '—' }}<span v-if="a.evaluation?.scores[c.key]?.note" class="block text-[11px] text-muted font-normal">{{ a.evaluation?.scores[c.key]?.note }}</span></td><td class="num" :class="o.evaluation.diffs.some((d) => d.criterion_key === c.key) ? 'text-warning font-medium' : 'text-muted'">{{ o.evaluation.assignments.length === 2 && o.evaluation.assignments.every((a) => a.evaluation) ? Math.abs((o.evaluation.assignments[0].evaluation!.scores[c.key]?.score ?? 0) - (o.evaluation.assignments[1].evaluation!.scores[c.key]?.score ?? 0)) : '—' }}</td></tr>
                </template>
                <tr class="bg-bg"><td class="font-semibold">{{ $t('admin.dashboard.overall') }}</td><td v-for="a in o.evaluation.assignments" :key="a.id" class="num font-semibold">{{ number(a.evaluation?.overall) }}</td><td /></tr>
                <tr><td class="font-medium">{{ $t('admin.dashboard.recommendation') }}</td><td v-for="a in o.evaluation.assignments" :key="a.id"><AppBadge v-if="a.evaluation" kind="eval" :value="a.evaluation.recommendation" /><p v-if="a.evaluation?.comment" class="text-xs text-muted mt-1">{{ a.evaluation.comment }}</p><div v-else-if="canEvaluate" class="flex flex-wrap gap-1"><AppButton v-if="!a.coi_declared_at" size="sm" variant="secondary" @click="coi(a.id, false)">{{ $t('admin.opportunity.coiTitle') }}: {{ $t('common.no') }}</AppButton><AppButton v-if="!a.coi_declared_at" size="sm" variant="ghost" @click="coi(a.id, true)">{{ $t('common.yes') }}</AppButton><AppButton v-if="a.coi_declared_at && !a.coi_has_conflict" size="sm" @click="openScore(a.id)">{{ $t('admin.opportunity.scoreTitle') }}</AppButton></div></td><td /></tr>
              </tbody></table></div>
            <div class="p-4 border-t border-border">
              <div v-if="o.evaluation.decision" class="flex flex-wrap items-center gap-3"><AppBadge kind="eval" :value="o.evaluation.decision.result" size="md" /><span class="text-sm text-muted">{{ o.evaluation.decision.decided_by.name }} · {{ date(o.evaluation.decision.decided_at) }}</span><p v-if="o.evaluation.decision.conditions" class="text-sm w-full">{{ $t('admin.opportunity.conditions') }}: {{ o.evaluation.decision.conditions }}</p></div>
              <div v-else-if="canDecide && o.evaluation.assignments.every((a) => a.evaluation)" class="grid sm:grid-cols-3 gap-3 items-end">
                <FormField v-model="decision.result" name="result" type="select" :label="$t('admin.opportunity.decisionResult')" :options="EVALUATION_RESULTS.map((r) => ({ value: r, label: label('evaluationResult', r) }))" required />
                <FormField v-model="decision.conditions" name="conditions" :label="$t('admin.opportunity.conditions')" />
                <FormField v-model="decision.reasons" name="reasons" :label="$t('admin.opportunity.reasons')" />
                <AppButton :disabled="!decision.result" :loading="busy === 'decide'" @click="decide">{{ $t('admin.opportunity.submitDecision') }}</AppButton>
              </div>
            </div>
          </SectionCard>
          <p v-else-if="!canDecide" class="text-sm text-muted">{{ $t('admin.opportunity.noEvaluation') }}</p>
        </div>
        <!-- NOMINATIONS -->
        <div v-show="tab === 'nominations'" class="grid lg:grid-cols-2 gap-5">
          <SectionCard :title="$t('admin.dashboard.candidates')" :sub="$t('admin.opportunity.maxNominations', { n: o.max_nominations })">
            <ul class="divide-y divide-border">
              <li v-for="c in o.candidates" :key="c.investor.uuid" class="py-2.5 flex items-center gap-3 text-sm">
                <input v-if="canMatch && !c.already_nominated" v-model="selected" type="checkbox" :value="c.investor.uuid" class="h-4 w-4 rounded border-border text-blue" :aria-label="c.investor.name" /><span v-else class="w-4" />
                <div class="flex-1 min-w-0"><p class="font-medium">{{ c.investor.name }}</p><ul class="flex flex-wrap gap-1 mt-0.5"><li v-for="k in criteria5" :key="k" class="text-[10px] rounded-full px-1.5 border" :class="c.matched_criteria[k] ? 'bg-success/10 text-success border-success/30' : 'bg-bg text-muted border-border'">{{ $t(`investor.nominations.criteria.${k}`) }}</li></ul></div>
                <span class="num font-semibold text-blue">{{ c.match_score }}/5</span><AppBadge v-if="c.already_nominated" kind="generic" value="approved" :label="$t('admin.dashboard.summarySent')" />
              </li>
            </ul>
            <div v-if="canMatch" class="mt-4 space-y-3"><FormField v-model="rationale" name="rationale" type="textarea" :label="$t('admin.opportunity.nominateRationale')" :rows="2" /><AppButton :disabled="!selected.length || !rationale" :loading="busy === 'nominate'" @click="nominate">{{ $t('admin.opportunity.nominate') }} ({{ selected.length }})</AppButton></div>
          </SectionCard>
          <div class="space-y-5">
            <SectionCard :title="$t('admin.opportunity.tabs.nominations')">
              <ul class="space-y-2 text-sm"><li v-for="n in o.nominations" :key="n.id" class="rounded-lg bg-bg p-3"><div class="flex justify-between gap-2"><span class="font-medium">{{ n.investor?.name }}</span><AppBadge kind="generic" :value="n.response ?? 'pending'" :label="n.response ? label('nominationResponse', n.response) : $t('common.new')" /></div><p class="text-xs text-muted mt-1">{{ date(n.sent_at) }} · {{ $t('investor.detail.matchScore', { n: n.match_score }) }}</p><p class="text-xs mt-1">{{ n.rationale }}</p></li><li v-if="!o.nominations.length" class="text-muted">{{ $t('common.noData') }}</li></ul>
            </SectionCard>
            <SectionCard :title="$t('admin.interests.title')">
              <ul class="space-y-2 text-sm"><li v-for="i in o.interests" :key="i.id" class="rounded-lg bg-bg p-3 flex justify-between gap-2"><span>{{ i.investor?.name }}<span class="block text-xs text-muted">{{ date(i.created_at) }} · {{ i.next_step ?? i.closed_reason ?? '' }}</span></span><AppBadge kind="interest" :value="i.status" /></li><li v-if="!o.interests.length" class="text-muted">{{ $t('common.noData') }}</li></ul>
            </SectionCard>
          </div>
        </div>
      </div>

      <!-- transition modal -->
      <Modal :open="transitionOpen" :title="$t('admin.opportunity.transition')" @close="transitionOpen = false">
        <div class="space-y-4">
          <FormField v-model="toStatus" name="to" type="select" :label="$t('admin.opportunity.transitionTo')" :options="o.allowed_transitions.map((s) => ({ value: s, label: label('opportunityStatus', s) }))" required />
          <FormField v-model="reason" name="reason" type="textarea" :label="$t('admin.opportunity.transitionReason')" :rows="3" />
        </div>
        <template #footer><AppButton variant="ghost" @click="transitionOpen = false">{{ $t('common.cancel') }}</AppButton><AppButton :disabled="!toStatus" :loading="busy === 'transition'" @click="transition">{{ $t('common.confirm') }}</AppButton></template>
      </Modal>
      <!-- score modal -->
      <Modal :open="scoringId != null" :title="$t('admin.opportunity.scoreTitle')" size="lg" @close="scoringId = null">
        <div class="space-y-4">
          <div v-for="ax in axes" :key="ax">
            <h3 class="font-semibold text-sm mb-2">{{ label('evaluationAxis', ax) }}</h3>
            <div v-for="c in o.evaluation.criteria.filter((x) => x.axis === ax)" :key="c.key" class="rounded-lg border border-border p-3 mb-2">
              <p class="text-sm font-medium">{{ c.label_ar }} <span class="text-xs text-muted num">(×{{ c.weight }})</span></p>
              <div class="mt-2 flex flex-wrap gap-1.5" role="radiogroup" :aria-label="c.label_ar"><button v-for="s in 5" :key="s" type="button" role="radio" :aria-checked="scoreForm.scores[c.key]?.score === s" class="chip num" :class="scoreForm.scores[c.key]?.score === s && 'chip-active'" :title="c.scale_descriptions[String(s) as '1']" @click="scoreForm.scores[c.key] = { ...(scoreForm.scores[c.key] ?? { note: '' }), score: s }">{{ s }}</button></div>
              <p class="text-xs text-muted mt-1">{{ scoreForm.scores[c.key]?.score ? c.scale_descriptions[String(scoreForm.scores[c.key].score) as '1'] : '' }}</p>
              <input v-if="scoreForm.scores[c.key]" v-model="scoreForm.scores[c.key].note" class="input mt-2 text-xs" :placeholder="$t('admin.opportunity.scoreNote')" :aria-label="$t('admin.opportunity.scoreNote')" />
            </div>
          </div>
          <FormField v-model="scoreForm.recommendation" name="recommendation" type="select" :label="$t('admin.dashboard.recommendation')" :options="EVALUATION_RESULTS.map((r) => ({ value: r, label: label('evaluationResult', r) }))" required />
          <FormField v-model="scoreForm.comment" name="comment" type="textarea" :label="$t('common.notes')" :rows="2" />
        </div>
        <template #footer><AppButton variant="ghost" @click="scoringId = null">{{ $t('common.cancel') }}</AppButton><AppButton :disabled="!scoreForm.recommendation" :loading="busy === 'score'" @click="submitScore">{{ $t('admin.opportunity.submitScore') }}</AppButton></template>
      </Modal>
    </template>
  </div>
</template>

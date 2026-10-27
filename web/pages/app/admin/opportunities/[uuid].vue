<script setup lang="ts">
/** Admin opportunity file — full record, timeline, internal notes, transitions, completion builder, document review,
 *  teaser editor/approval, evaluation assign/score/decide, nominations (ADM-01..05, EVL-01..03, MAT-01..05, DOC-03).
 *
 *  Data is a composite built client-side from several endpoints fetched in parallel (#18):
 *  GET /admin/opportunities/{uuid} (full staff resource) + …/evaluation + …/candidates + /admin/interests?filter[opportunity]
 *  + /admin/users?role=reviewer|evaluator. Each secondary call is optional (403/404 → empty), so every role sees what it may. */
import type { OpportunityFull, StatusTransition, InternalNote, CompletionRequest, EvaluationPanel, Evaluation, Candidate, CandidatesResponse, Nomination, Interest, Teaser, DocumentResource, Paginated, UserPick, MatchFlagKey, TermRef, TimelineEvent } from '~/types/api'
import type { OpportunityStatus, EvaluationResult, DocumentStatus, EvaluationAxis } from '~/types/enums'
import { EVALUATION_RESULTS, DOCUMENT_KINDS, OPPORTUNITY_STRIP_STATUSES } from '~/types/enums'
import { z } from 'zod'
import { futureDate, listOf, mustDiffer, optionalText, requiredChoice, requiredNumber, requiredString, RULES, vmsg } from '~/utils/validation'
import { confirmDialog, toastApiError, toastInfo, toastSuccess } from '~/utils/notify'
import { parseApiError } from '~/utils/apiError'

definePageMeta({ layout: 'portal', middleware: ['role'] })
type Person = { id: number; name: string }
type Full = OpportunityFull & { sector?: TermRef | null; timeline: StatusTransition[]; timeline_events: TimelineEvent[]; notes: InternalNote[]; completion_requests: CompletionRequest[]; evaluation: EvaluationPanel; candidates: Candidate[]; nominations: Nomination[]; interests: Interest[]; allowed_transitions: OpportunityStatus[]; reviewers: Person[]; evaluators: Person[]; max_nominations: number; teaser: Teaser | null }
/** Raw staff payload: Laravel nests overview/impact/financials/disclosures and uses `internal_notes` / `required_now`; the mock is flat. */
type Raw = Partial<Full> & Record<string, unknown> & {
  overview?: Record<string, unknown>; impact?: Record<string, unknown>; financials?: Record<string, unknown>; disclosures?: Record<string, unknown>
  internal_notes?: { id: number; author: string | { name: string } | null; body: string; created_at: string }[]
  required_now?: { id: number; items: CompletionRequest['items']; due_at: string | null; created_at?: string }[]
  timeline?: (Partial<StatusTransition> & { from?: OpportunityStatus | null; to?: OpportunityStatus; at?: string; actor?: string | { name: string } | null })[]
  overdue?: string | null
}
const { t } = useI18n()
const api = useApi()
const auth = useAuthStore()
const route = useRoute()
const tax = useTaxonomyStore()
await tax.load().catch(() => {})
const { label, term } = useEnumLabel()
const { number, sar, date, dateTime } = useFormat()
const uuid = route.params.uuid as string

const optional = async <T>(p: Promise<T>): Promise<T | null> => p.catch(() => null)
const emptyPanel = (): EvaluationPanel => ({ criteria: [], assignments: [], diffs: [], decision: null })
const normalizeTimeline = (items: Raw['timeline']): StatusTransition[] => (items ?? []).map((x, i) => ({ id: x.id ?? i + 1, from_status: x.from_status ?? x.from ?? null, to_status: (x.to_status ?? x.to) as OpportunityStatus, created_at: x.created_at ?? x.at ?? '', actor: typeof x.actor === 'string' ? { name: x.actor } : x.actor ?? null, reason: x.reason ?? null }))
const normalizePanel = (p: Partial<EvaluationPanel> | null | undefined): EvaluationPanel => (p ? { ...emptyPanel(), ...p, criteria: p.active_criteria ?? p.criteria ?? [], diffs: p.diffs ?? [], assignments: p.assignments ?? [] } : emptyPanel())
/** EVL-02 — an evaluator's file opens only after the conflict-of-interest declaration, so a 403 on the file shows
 *  the declaration step (from …/my-assignment) instead of an empty page. */
type CoiGate = { assignment: { id: number; status: string; coi_declared_at: string | null; coi_has_conflict: boolean | null; due_at: string | null }; opportunity: { uuid: string; reference_no: string | null; name: string } }
const coiGate = ref<CoiGate | null>(null)
const loadError = ref(false)
const load = async (): Promise<Full | null> => {
  coiGate.value = null
  loadError.value = false
  const [fullRes, ev, cand, ints, reviewers, evaluators] = await Promise.all([
    api.get<{ data: Raw }>(`/admin/opportunities/${uuid}`).then((r) => ({ ok: true as const, data: r.data }), (e: unknown) => ({ ok: false as const, error: e })),
    optional(api.get<{ data: EvaluationPanel }>(`/admin/opportunities/${uuid}/evaluation`).then((r) => r.data)),
    optional(api.get<{ data: CandidatesResponse | Candidate[] }>(`/admin/opportunities/${uuid}/candidates`).then((r) => r.data)),
    optional(api.get<Paginated<Interest>>('/admin/interests', { 'filter[opportunity]': uuid, per_page: 100 }).then((r) => r.data)),
    optional(api.get<Paginated<UserPick>>('/admin/users', { role: 'reviewer,super_admin', per_page: 100 }).then((r) => r.data)),
    optional(api.get<Paginated<UserPick>>('/admin/users', { role: 'evaluator', per_page: 100 }).then((r) => r.data)),
  ])
  if (!fullRes.ok) {
    if (parseApiError(fullRes.error).status === 403 && auth.canAny('evaluations.score')) {
      coiGate.value = await optional(api.get<{ data: CoiGate }>(`/admin/opportunities/${uuid}/my-assignment`).then((r) => r.data))
      if (coiGate.value) return null
    }
    loadError.value = true
    return null
  }
  const full = fullRes.data
  const flat = { ...full, ...(full.overview ?? {}), ...(full.impact ?? {}), ...(full.financials ?? {}), ...(full.disclosures ?? {}) } as Raw
  const candidates = Array.isArray(cand) ? cand : cand?.candidates ?? full.candidates ?? []
  return {
    ...(flat as unknown as Full),
    sector_id: flat.sector_id ?? flat.sector?.id ?? null,
    is_overdue: flat.is_overdue ?? !!flat.overdue,
    use_of_funds: (flat.use_of_funds as Full['use_of_funds']) ?? [],
    indicators: flat.indicators ?? [],
    documents: flat.documents ?? [],
    timeline: normalizeTimeline(full.timeline),
    timeline_events: full.timeline_events ?? [], // ADM-06 merged staff timeline
    notes: full.notes ?? (full.internal_notes ?? []).map((n) => ({ id: n.id, body: n.body, created_at: n.created_at, author: typeof n.author === 'string' ? { name: n.author } : n.author ?? { name: '—' } })),
    completion_requests: (full.completion_requests ?? (full.required_now ?? []).map((c) => ({ id: c.id, items: c.items ?? [], due_at: c.due_at ?? '', resolved_at: null, created_at: c.created_at ?? c.due_at ?? '' }))) as CompletionRequest[],
    evaluation: normalizePanel(ev ?? full.evaluation),
    candidates,
    nominations: full.nominations ?? [],
    interests: ints ?? full.interests ?? [],
    allowed_transitions: full.allowed_transitions ?? [],
    reviewers: reviewers?.map((u) => ({ id: u.id, name: u.name })) ?? full.reviewers ?? [],
    evaluators: evaluators?.map((u) => ({ id: u.id, name: u.name })) ?? full.evaluators ?? [],
    max_nominations: (cand && !Array.isArray(cand) ? cand.max_nominations : undefined) ?? full.max_nominations ?? 5,
    teaser: full.teaser ?? null,
  }
}
const { data: o, loading, refresh } = useAsyncResource(load)
watch(o, (v) => v && useHead({ title: `${v.reference_no ?? ''} ${v.name}` }))
const tab = ref((route.query.tab as string) ?? 'file')
const nominationThread = ref<number | null>(null)
const tabs = computed(() => [
  { key: 'file', label: t('admin.opportunity.tabs.file') },
  { key: 'timeline', label: t('admin.opportunity.tabs.timeline'), count: o.value?.timeline_events.length || o.value?.timeline.length },
  { key: 'notes', label: t('admin.opportunity.tabs.notes'), count: o.value?.notes.length },
  // MTG-03 — messages with the owner (separate from the staff-only internal notes)
  ...(auth.canAny('opportunities.transition') ? [{ key: 'messages', label: t('collab.messages.tab') }] : []),
  { key: 'documents', label: t('admin.opportunity.tabs.documents'), count: o.value?.documents.length },
  { key: 'completion', label: t('admin.opportunity.tabs.completion'), count: o.value?.completion_requests.length },
  { key: 'teaser', label: t('admin.opportunity.tabs.teaser') },
  { key: 'evaluation', label: t('admin.opportunity.tabs.evaluation') },
  { key: 'nominations', label: t('admin.opportunity.tabs.nominations'), count: o.value?.nominations.length },
])
const busy = ref<string | null>(null)
/** Row / button actions without inputs: specific success toast, failures → toastApiError. */
const run = async (key: string, fn: () => Promise<unknown>, ok: string) => {
  busy.value = key
  try { await fn(); toastSuccess(ok); await refresh() } catch (e) { toastApiError(e) } finally { busy.value = null }
}
const item = (k: string) => ({ item: t(`notify.entities.${k}`) })
// shape helpers
const sectorName = computed(() => o.value?.sector?.label ?? term(tax.byId('sectors', o.value?.sector_id ?? null)))
const axisValue = (e: Evaluation | null | undefined, ax: EvaluationAxis): number | null => { const v = e?.axis_totals?.[ax]; return v == null ? null : typeof v === 'number' ? v : v.weighted }
const SHORT: Record<string, MatchFlagKey> = { investment_type: 'type', funding_range: 'range', sector: 'sector', stage: 'stage', region: 'region' }
const flags = (c: Pick<Candidate, 'matched' | 'matched_criteria'>): Partial<Record<MatchFlagKey, boolean>> => c.matched ?? (Array.isArray(c.matched_criteria) ? Object.fromEntries(c.matched_criteria.map((m) => [SHORT[m.key] ?? m.key, true])) : c.matched_criteria)
const criterionLabel = (c: { label_ar?: string; label?: string }) => c.label ?? c.label_ar ?? ''
const oppName = computed(() => o.value?.name ?? '')

// ---------------------------------------------------------------- transition (TransitionOpportunityRequest: reason required for not_eligible / closed)
const REASON_REQUIRED: OpportunityStatus[] = ['not_eligible', 'closed']
const transitionOpen = ref(false)
const transitionForm = useZodForm(
  z.object({ transition_to: requiredChoice('admin.opportunity.transitionTo'), transition_reason: optionalText(RULES.noteMax) })
    .refine((v) => !REASON_REQUIRED.includes(v.transition_to as OpportunityStatus) || !!v.transition_reason?.trim(), { path: ['transition_reason'], error: () => vmsg('requiredNamed', { field: t('admin.opportunity.transitionReason') }) }),
  { transition_to: '', transition_reason: '' },
  { aliases: { to: 'transition_to', reason: 'transition_reason' } },
)
const openTransition = () => { transitionForm.reset(); transitionOpen.value = true }
const transition = () => transitionForm.submit(async (v) => {
  const to = v.transition_to as OpportunityStatus
  // not eligible / closed end the file → explicit consent naming the opportunity and the target status.
  if (to === 'not_eligible' && !(await confirmDialog(t('notify.rejectTitle'), t('notify.rejectText', { name: `${oppName.value} — ${label('opportunityStatus', to)}` }), t('notify.rejectConfirm')))) return
  if (to === 'closed' && !(await confirmDialog(t('notify.closeTitle'), t('notify.closeText', { name: oppName.value }), t('notify.closeConfirm')))) return
  await api.post(`/admin/opportunities/${uuid}/transition`, { to, reason: v.transition_reason?.trim() || undefined })
  transitionOpen.value = false
  if (to === 'closed') toastSuccess(t('notify.closed', item('opportunity')))
  else if (to === 'not_eligible') toastInfo(t('notify.rejectedNotified', item('opportunity')))
  else toastSuccess(t('notify.statusChangedTo', { ...item('opportunity'), status: label('opportunityStatus', to) }))
  await refresh()
})
// ---------------------------------------------------------------- assign reviewer (AssignReviewerRequest)
const assignForm = useZodForm(z.object({ reviewer_user_id: requiredChoice('admin.opportunity.assignTo') }), { reviewer_user_id: '' })
const assign = () => assignForm.submit(async (v) => {
  await api.post(`/admin/opportunities/${uuid}/assign`, { reviewer_user_id: Number(v.reviewer_user_id) })
  toastSuccess(t('notify.assigned', item('opportunity')))
  assignForm.reset()
  await refresh()
})
// ---------------------------------------------------------------- internal notes (InternalNoteRequest: 2..5000)
const noteForm = useZodForm(z.object({ note_body: requiredString('admin.opportunity.noteBody', RULES.noteMin, RULES.longTextMax) }), { note_body: '' }, { aliases: { body: 'note_body' } })
const addNote = () => noteForm.submit(async (v) => {
  await api.post(`/admin/opportunities/${uuid}/notes`, { body: v.note_body.trim() })
  noteForm.reset()
  toastSuccess(t('notify.created', item('note')))
  await refresh()
})
// ---------------------------------------------------------------- completion builder (CompletionRequestRequest: 1..30 items, note ≤1000, due in the future)
type CrItem = { kind: 'field' | 'document'; key: string; note: string }
const blankCrItem = (): CrItem => ({ kind: 'document', key: 'pitch_deck', note: '' })
const completionForm = useZodForm(
  z.object({
    cr_items: listOf(z.object({ kind: z.string(), key: requiredString('admin.opportunity.completionItem', 1, RULES.completionFieldMax), note: optionalText(RULES.completionNoteMax) }), 1, RULES.completionItemsMax),
    cr_due: futureDate(),
  }),
  { cr_items: [blankCrItem()], cr_due: '' },
  { aliases: { items: 'cr_items', due_at: 'cr_due' } },
)
const addCrItem = () => completionForm.values.cr_items.push({ kind: 'field', key: '', note: '' })
const removeCrItem = (i: number) => { completionForm.values.cr_items.splice(i, 1); completionForm.clearErrors(['cr_items']) }
const sendCompletion = () => completionForm.submit(async (v) => {
  await api.post(`/admin/opportunities/${uuid}/completion-requests`, { items: v.cr_items.map((i) => (i.kind === 'document' ? { document_kind: i.key, note: i.note?.trim() || undefined } : { field: i.key.trim(), note: i.note?.trim() || undefined })), due_at: v.cr_due ? new Date(v.cr_due).toISOString() : undefined, reason: t('admin.opportunity.completionReason') })
  completionForm.reset({ cr_items: [blankCrItem()], cr_due: '' })
  toastSuccess(t('notify.sent', item('completionRequest')))
  await refresh()
})
// ---------------------------------------------------------------- documents (ReviewDocumentRequest: note required when rejected, ≤1000)
const docNote = reactive<Record<string, string>>({})
const acceptDoc = (d: DocumentResource) => run(`doc-${d.uuid}`, () => api.post(`/admin/opportunities/${uuid}/documents/${d.uuid}/review`, { status: 'accepted', note: docNote[d.uuid]?.trim() || undefined }), t('notify.approved', item('document')))
const rejectingDoc = ref<DocumentResource | null>(null)
const rejectForm = useZodForm(z.object({ reject_note: requiredString('admin.opportunity.rejectDocNote', 1, RULES.docNoteMax) }), { reject_note: '' }, { aliases: { note: 'reject_note' } })
const openReject = (d: DocumentResource) => { rejectingDoc.value = d; rejectForm.reset({ reject_note: docNote[d.uuid] ?? '' }) }
const rejectDoc = () => rejectForm.submit(async (v) => {
  const d = rejectingDoc.value
  if (!d) return
  if (!(await confirmDialog(t('notify.rejectTitle'), t('notify.rejectText', { name: d.title }), t('notify.rejectConfirm')))) return
  await api.post(`/admin/opportunities/${uuid}/documents/${d.uuid}/review`, { status: 'rejected' as DocumentStatus, note: v.reject_note.trim() })
  rejectingDoc.value = null
  toastInfo(t('notify.rejectedNotified', { item: `${t('notify.entities.document')} «${d.title}»` }))
  await refresh()
})
// ---------------------------------------------------------------- teaser (TeaserRequest: summary_ar 20..600, ≤6 highlights × ≤160)
const lines = (v: string | null | undefined) => (v ?? '').split('\n').map((h) => h.trim()).filter(Boolean)
const teaserForm = useZodForm(
  z.object({
    summary_ar: requiredString('admin.opportunity.teaserSummaryAr', RULES.teaserMin, RULES.teaserMax),
    summary_en: optionalText(RULES.teaserMax),
    highlights: z.string()
      .refine((v) => lines(v).length <= RULES.highlightsMax, { error: () => vmsg('maxItems', { n: RULES.highlightsMax }) })
      .refine((v) => lines(v).every((h) => h.length <= RULES.highlightMax), { error: () => vmsg('maxLength', { n: RULES.highlightMax }) }),
  }),
  { summary_ar: '', summary_en: '', highlights: '' },
)
watch(o, (v) => {
  if (v?.teaser) teaserForm.reset({ summary_ar: v.teaser.summary_ar, summary_en: v.teaser.summary_en ?? '', highlights: (v.teaser.highlights ?? []).join('\n') })
  else if (v) teaserForm.reset({ summary_ar: v.short_description ?? '', summary_en: '', highlights: '' })
}, { immediate: true })
const saveTeaser = () => teaserForm.submit(async (v) => {
  await api.post(`/admin/opportunities/${uuid}/teaser`, { summary_ar: v.summary_ar.trim(), summary_en: v.summary_en?.trim() || null, highlights: lines(v.highlights) })
  toastSuccess(t('notify.savedItem', item('teaser')))
  await refresh()
})
const approveTeaser = () => run('teaser-approve', () => api.post(`/admin/opportunities/${uuid}/teaser/approve`), t('notify.approved', item('teaser')))
// ---------------------------------------------------------------- evaluation: assign two distinct evaluators (AssignEvaluatorsRequest)
const evaluatorsForm = useZodForm(
  mustDiffer(z.object({ ev1: requiredChoice('admin.opportunity.evaluator1'), ev2: requiredChoice('admin.opportunity.evaluator2') }), 'ev1', 'ev2'),
  { ev1: '', ev2: '' },
  { aliases: { evaluator_ids: 'ev2' } },
)
const assignEvaluators = () => evaluatorsForm.submit(async (v) => {
  await api.post(`/admin/opportunities/${uuid}/evaluation/assign`, { evaluator_ids: [Number(v.ev1), Number(v.ev2)] })
  toastSuccess(t('notify.assigned', item('evaluation')))
  await refresh()
})
// ---------------------------------------------------------------- score (ScoreRequest: every criterion 1..5, recommendation required)
type ScoreRow = { score: number | null; note: string }
const scoreForm = useZodForm(
  z.object({
    scores: z.record(z.string(), z.object({ score: requiredNumber('admin.opportunity.scoreRequired', { min: RULES.scoreMin, max: RULES.scoreMax, int: true }).nullable().refine((v) => typeof v === 'number' && v >= RULES.scoreMin, { error: () => vmsg('requiredNamed', { field: t('admin.opportunity.scoreRequired') }) }), note: optionalText(RULES.docNoteMax) })),
    recommendation: requiredChoice('admin.dashboard.recommendation'),
    comment: optionalText(RULES.longTextMax),
  }),
  { scores: {} as Record<string, ScoreRow>, recommendation: '' as EvaluationResult | '', comment: '' },
)
const scoringId = ref<number | null>(null)
const openScore = (id: number) => {
  scoringId.value = id
  scoreForm.reset({ scores: Object.fromEntries((o.value?.evaluation.criteria ?? []).map((c) => [c.key, { score: null, note: '' }])), recommendation: '', comment: '' })
}
const setScore = (key: string, s: number) => {
  scoreForm.values.scores[key] = { ...(scoreForm.values.scores[key] ?? { note: '' }), score: s }
  scoreForm.clearErrors([`scores.${key}`])
}
const submitScore = () => scoreForm.submit(async (v) => {
  await api.post(`/admin/evaluation-assignments/${scoringId.value}/score`, { scores: Object.fromEntries(Object.entries(v.scores).map(([k, s]) => [k, { score: Number(s.score), note: s.note?.trim() || undefined }])), recommendation: v.recommendation, comment: v.comment?.trim() || undefined })
  scoringId.value = null
  toastSuccess(t('notify.submitted', item('evaluation')))
  await refresh()
})
/** COI "yes" withdraws the evaluator from this file → confirmed first. */
const coi = async (id: number, has: boolean) => {
  const name = oppName.value || coiGate.value?.opportunity.name || ''
  if (has && !(await confirmDialog(t('notify.finalTitle'), t('notify.finalText', { name: `${t('admin.opportunity.coiTitle')} — ${name}` }), t('notify.finalConfirm')))) return
  if (!has && coiGate.value) tab.value = 'evaluation'
  await run(`coi-${id}`, () => api.post(`/admin/evaluation-assignments/${id}/coi`, { has_conflict: has }), t('notify.savedItem', item('coi')))
}
// ---------------------------------------------------------------- decision (DecideEvaluationRequest: conditions / reasons required by result, ≤3000)
// EVL-03 — improvement plan (≤20 items: text 3..500, optional target date today or later) with "not eligible now" /
// "eligible with conditions"; the form keys match the API's (`improvement_plan.N.text`) so a 422 lands under the row.
const PLAN_MAX = 20
const todayIso = () => { const d = new Date(); const p = (n: number) => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}` }
type PlanRow = { text: string; due_at: string }
const PLAN_RESULTS = ['not_eligible_now', 'eligible_with_conditions']
const decisionForm = useZodForm(
  z.object({
    result: requiredChoice('admin.opportunity.decisionResult'),
    conditions: optionalText(RULES.descMax),
    reasons: optionalText(RULES.descMax),
    improvement_plan: z.array(z.object({ text: z.string(), due_at: z.string() })),
  })
    .refine((v) => v.result !== 'eligible_with_conditions' || !!v.conditions?.trim(), { path: ['conditions'], error: () => vmsg('requiredNamed', { field: t('admin.opportunity.conditions') }) })
    .refine((v) => v.result !== 'not_eligible_now' || !!v.reasons?.trim(), { path: ['reasons'], error: () => vmsg('requiredNamed', { field: t('admin.opportunity.reasons') }) })
    // Plan rows are only validated (and sent) when the result takes a plan.
    .superRefine((v, ctx) => {
      if (!PLAN_RESULTS.includes(String(v.result))) return
      if (v.improvement_plan.length > PLAN_MAX) ctx.addIssue({ code: 'custom', path: ['improvement_plan'], message: vmsg('maxItems', { n: PLAN_MAX }) })
      v.improvement_plan.forEach((p, i) => {
        const text = p.text.trim()
        if (!text) ctx.addIssue({ code: 'custom', path: ['improvement_plan', i, 'text'], message: vmsg('requiredNamed', { field: t('admin.evaluationDecision.planText') }) })
        else if (text.length < 3) ctx.addIssue({ code: 'custom', path: ['improvement_plan', i, 'text'], message: vmsg('minLength', { n: 3 }) })
        else if (text.length > RULES.shortTextMax) ctx.addIssue({ code: 'custom', path: ['improvement_plan', i, 'text'], message: vmsg('maxLength', { n: RULES.shortTextMax }) })
        if (p.due_at && p.due_at < todayIso()) ctx.addIssue({ code: 'custom', path: ['improvement_plan', i, 'due_at'], message: vmsg('futureDate') })
      })
    }),
  { result: '' as EvaluationResult | '', conditions: '', reasons: '', improvement_plan: [] as PlanRow[] },
)
const planAllowed = computed(() => PLAN_RESULTS.includes(String(decisionForm.values.result)))
const addPlanItem = () => decisionForm.values.improvement_plan.push({ text: '', due_at: '' })
const removePlanItem = (i: number) => { decisionForm.values.improvement_plan.splice(i, 1); decisionForm.clearErrors(['improvement_plan']) }
const decide = () => decisionForm.submit(async (v) => {
  // The eligibility decision is final → explicit consent naming the opportunity and the result.
  if (!(await confirmDialog(t('notify.finalTitle'), t('notify.finalText', { name: `${oppName.value} — ${label('evaluationResult', String(v.result))}` }), t('notify.finalConfirm')))) return
  const plan = planAllowed.value ? v.improvement_plan.map((p) => ({ text: p.text.trim(), due_at: p.due_at || undefined })) : []
  await api.post(`/admin/opportunities/${uuid}/evaluation/decide`, { result: v.result, conditions: v.conditions?.trim() || undefined, reasons: v.reasons?.trim() || undefined, improvement_plan: plan.length ? plan : undefined })
  toastSuccess(t('notify.approved', item('decision')))
  await refresh()
})
const pairDiff = (key: string) => { const a = o.value?.evaluation.assignments ?? []; return a.length === 2 && a.every((x) => x.evaluation) ? Math.abs((a[0].evaluation!.scores[key]?.score ?? 0) - (a[1].evaluation!.scores[key]?.score ?? 0)) : null }
// ---------------------------------------------------------------- nominations — Laravel wants integer user ids (#19); the mock also accepts uuids (NominateRequest: 1..10, rationale 10..2000)
const nominateForm = useZodForm(
  z.object({ nominee_ids: listOf(z.union([z.number(), z.string()]), 1, RULES.nominationsMax), rationale: requiredString('admin.opportunity.nominateRationale', RULES.rationaleMin, RULES.noteMax) }),
  { nominee_ids: [] as (number | string)[], rationale: '' },
  { aliases: { investor_user_ids: 'nominee_ids' } },
)
const candidateId = (c: Candidate) => c.investor.id ?? c.investor.uuid
// MAT-01 — manual search (name / email) + filters, so staff can nominate an investor outside the ranked top list.
// The API keeps the same pool rules (approved members, COI excluded) and nominate() keeps its own checks.
const candFilter = reactive({ search: '', sector: '', region: '', type: '', investor_type: '' })
const candFiltered = computed(() => Object.values(candFilter).some((v) => !!v.trim()))
const searched = ref<Candidate[] | null>(null)
const searching = ref(false)
const shownCandidates = computed(() => (candFiltered.value ? searched.value ?? [] : o.value?.candidates ?? []))
const seen = reactive<Record<string, string>>({}) // id → name: selections survive a new search
watch(shownCandidates, (list) => { for (const c of list) seen[String(candidateId(c))] = c.investor.name }, { immediate: true })
const pickedNames = computed(() => nominateForm.values.nominee_ids.map((id) => seen[String(id)] ?? `#${id}`))
const searchCandidates = async () => {
  if (!candFiltered.value) { searched.value = null; return }
  searching.value = true
  try {
    const params = Object.fromEntries(Object.entries(candFilter).filter(([, v]) => !!v.trim()).map(([k, v]) => [`filter[${k}]`, v.trim()]))
    const r = await api.get<{ data: CandidatesResponse | Candidate[] }>(`/admin/opportunities/${uuid}/candidates`, { ...params, limit: 50 })
    searched.value = Array.isArray(r.data) ? r.data : r.data.candidates ?? []
  } catch (e) { toastApiError(e) } finally { searching.value = false }
}
let candTimer: ReturnType<typeof setTimeout>
watch(candFilter, () => { clearTimeout(candTimer); candTimer = setTimeout(searchCandidates, 300) })
const clearCandFilter = () => { Object.assign(candFilter, { search: '', sector: '', region: '', type: '', investor_type: '' }) }
const nominate = () => nominateForm.submit(async (v) => {
  await api.post(`/admin/opportunities/${uuid}/nominations`, { investor_user_ids: v.nominee_ids, rationale: v.rationale.trim() })
  nominateForm.reset()
  toastSuccess(t('notify.sent', item('nomination')))
  await refresh()
  if (candFiltered.value) await searchCandidates() // refresh the "already nominated" flags of the search results
})
const axes = ['readiness', 'feasibility', 'impact'] as const
const criteria5: MatchFlagKey[] = ['sector', 'stage', 'region', 'type', 'range']
const canTransition = computed(() => auth.canAny('opportunities.transition'))
const canEvaluate = computed(() => auth.canAny('evaluations.score'))
const canDecide = computed(() => auth.canAny('evaluations.approve'))
const canMatch = computed(() => auth.canAny('nominations.manage'))
const isMine = (evaluatorId: number) => !!auth.me && (auth.me as { id?: number }).id === evaluatorId
</script>

<template>
  <div>
    <div v-if="loading && !o" class="text-muted">{{ $t('common.loading') }}</div>
    <section v-else-if="coiGate" class="card card-pad max-w-2xl" aria-labelledby="coi-gate-title">
      <nav class="text-xs text-muted mb-3" aria-label="breadcrumb"><NuxtLink :to="$localePath('/app/admin/dashboard')" class="hover:text-blue">{{ $t('portal.nav.dashboard') }}</NuxtLink> / <span class="num">{{ coiGate.opportunity.reference_no ?? '—' }}</span></nav>
      <h1 class="font-display text-2xl md:text-3xl font-extrabold text-ink">{{ coiGate.opportunity.name }}</h1>
      <template v-if="coiGate.assignment.coi_has_conflict">
        <p class="mt-4 text-desc text-muted" role="status">{{ $t('admin.opportunity.coiGateWithdrawn') }}</p>
      </template>
      <template v-else>
        <h2 id="coi-gate-title" class="mt-5 card-title">{{ $t('admin.opportunity.coiTitle') }}</h2>
        <p class="mt-2 text-desc text-muted">{{ $t('admin.opportunity.coiGateText') }}</p>
        <p v-if="coiGate.assignment.due_at" class="mt-2 text-sm">{{ $t('admin.opportunity.coiGateDue') }}: <span class="num font-medium">{{ date(coiGate.assignment.due_at) }}</span></p>
        <p class="mt-5 font-medium">{{ $t('admin.opportunity.coiQuestion') }}</p>
        <div class="mt-3 flex flex-wrap gap-2">
          <AppButton :loading="busy === `coi-${coiGate.assignment.id}`" @click="coi(coiGate.assignment.id, false)">{{ $t('admin.opportunity.coiNone') }}</AppButton>
          <AppButton variant="ghost" :disabled="!!busy" @click="coi(coiGate.assignment.id, true)">{{ $t('admin.opportunity.coiYes') }}</AppButton>
        </div>
      </template>
    </section>
    <EmptyState v-else-if="loadError" :title="$t('admin.opportunity.loadFailed')" />
    <template v-else-if="o">
      <nav class="text-xs text-muted mb-3" aria-label="breadcrumb"><NuxtLink :to="$localePath('/app/admin/opportunities')" class="hover:text-blue">{{ $t('admin.opportunities.title') }}</NuxtLink> / <span class="num">{{ o.reference_no ?? '—' }}</span></nav>
      <header class="card card-pad">
        <div class="flex flex-col lg:flex-row lg:items-start gap-4">
          <div class="flex-1 min-w-0">
            <div class="flex flex-wrap items-center gap-2"><AppBadge kind="opportunity" :value="o.status" :soft="false" size="md" /><AppBadge v-if="o.is_overdue" kind="generic" value="stalled" :label="$t('common.overdue')" /><span class="text-sm text-muted">{{ sectorName }} · {{ label('stage', o.stage) }}</span></div>
            <h1 class="mt-3 font-display text-2xl md:text-3xl font-extrabold text-ink">{{ o.name }}</h1>
            <p class="mt-1 text-sm text-muted">{{ o.owner?.name }} · {{ o.organization?.name ?? label('userType', o.owner?.type) }}</p>
          </div>
          <div class="flex flex-wrap gap-2 shrink-0">
            <AppButton v-if="canTransition && o.allowed_transitions.length" @click="openTransition">{{ $t('admin.opportunity.transition') }}</AppButton>
            <!-- AGR-02 — the connection's agreements (connected stage or later) -->
            <AppButton v-if="auth.canAny('agreements.manage', 'agreements.approve') && ['connected', 'negotiating', 'in_execution', 'operating'].includes(o.status)" variant="secondary" :to="`/app/admin/agreements?opportunity=${o.uuid}`">{{ $t('agreements.opportunityLink') }}</AppButton>
            <form v-if="canTransition && o.reviewers.length" class="flex flex-col" novalidate @submit.prevent="assign">
              <div class="flex gap-1"><select v-model="assignForm.values.reviewer_user_id" name="reviewer_user_id" class="input w-44" :class="assignForm.errors.value.reviewer_user_id && 'input-error'" :aria-label="$t('admin.opportunity.assignTo')" :aria-invalid="!!assignForm.errors.value.reviewer_user_id" required><option value="">{{ $t('admin.opportunity.assignTo') }}</option><option v-for="r in o.reviewers" :key="r.id" :value="String(r.id)">{{ r.name }}</option></select><AppButton type="submit" variant="secondary" :loading="assignForm.submitting.value">{{ $t('admin.opportunity.assign') }}</AppButton></div>
              <p v-if="assignForm.errors.value.reviewer_user_id" class="error-text" role="alert">{{ assignForm.errors.value.reviewer_user_id }}</p>
              <FormAlert :message="assignForm.formError.value" class="mt-2 max-w-xs" />
            </form>
          </div>
        </div>
        <dl class="mt-5 pt-4 border-t border-border grid grid-cols-2 lg:grid-cols-5 gap-4 text-sm">
          <div><dt class="text-xs text-muted">{{ $t('admin.dashboard.assignee') }}</dt><dd class="font-medium">{{ o.assigned_reviewer?.name ?? '—' }}</dd></div>
          <div><dt class="text-xs text-muted">{{ $t('entrepreneur.dashboard.submittedAt') }}</dt><dd class="font-medium">{{ date(o.submitted_at) }}</dd></div>
          <div><dt class="text-xs text-muted">{{ $t('admin.dashboard.sla') }}</dt><dd class="font-medium">{{ date(o.review_due_at ?? o.completion_due_at ?? o.evaluation_due_at) }}</dd></div>
          <div><dt class="text-xs text-muted">{{ $t('admin.dashboard.recommendation') }}</dt><dd><AppBadge v-if="o.evaluation_result" kind="eval" :value="o.evaluation_result" /><span v-else>—</span></dd></div>
          <div><dt class="text-xs text-muted">{{ $t('admin.opportunity.isListed') }}</dt><dd class="font-medium">{{ o.is_listed ? $t('common.yes') : $t('common.no') }}</dd></div>
        </dl>
        <!-- design v3 — where the file stands on the happy path (the status above, as a stage track; terminal states show the badge only) -->
        <div v-if="(OPPORTUNITY_STRIP_STATUSES as readonly string[]).includes(o.status)" class="mt-5 pt-4 border-t border-border">
          <StageTrack :stages="OPPORTUNITY_STRIP_STATUSES.map((s) => label('opportunityStatus', s))" :current="OPPORTUNITY_STRIP_STATUSES.indexOf(o.status as never)" :label="$t('components.statusStrip.title')" />
        </div>
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
              <div><h4 class="font-bold text-ink">{{ $t('investor.detail.problem') }}</h4><p class="text-text/85 leading-6 mt-1">{{ o.problem_statement || '—' }}</p></div>
              <div><h4 class="font-bold text-ink">{{ $t('investor.detail.solution') }}</h4><p class="text-text/85 leading-6 mt-1">{{ o.solution || '—' }}</p></div>
              <div><h4 class="font-bold text-ink">{{ $t('investor.detail.expectedChange') }}</h4><p class="text-text/85 leading-6 mt-1">{{ o.expected_change || '—' }}</p></div>
              <div><h4 class="font-bold text-ink">{{ $t('investor.detail.beneficiariesDesc') }}</h4><p class="text-text/85 leading-6 mt-1">{{ o.beneficiaries_desc || '—' }} <span class="num text-muted">({{ number(o.beneficiaries_current) }} → {{ number(o.beneficiaries_target) }})</span></p></div>
            </div>
            <div class="mt-5 overflow-x-auto"><table class="table-base"><thead><tr><th>{{ $t('entrepreneur.form.impact.indicator') }}</th><th>{{ $t('entrepreneur.form.impact.baseline') }}</th><th>{{ $t('entrepreneur.form.impact.target') }}</th><th>{{ $t('entrepreneur.form.impact.source') }}</th><th>{{ $t('entrepreneur.form.impact.irisCode') }}</th><th>{{ $t('entrepreneur.form.impact.disclosed') }}</th></tr></thead><tbody><tr v-for="i in o.indicators" :key="i.name"><td class="font-medium">{{ i.name }} <span v-if="i.is_primary" class="text-xs font-normal rounded-full border border-accent/30 bg-accent/10 text-accent-text px-2 py-0.5">{{ $t('entrepreneur.form.impact.primary') }}</span></td><td class="num">{{ number(i.baseline) }} {{ i.unit }}</td><td class="num">{{ number(i.target) }} {{ i.unit }}</td><td class="text-muted">{{ i.data_source }}</td><td class="num" dir="ltr">{{ i.iris_code || '—' }}</td><td>{{ i.is_disclosed ? $t('common.yes') : $t('common.no') }}</td></tr></tbody></table></div>
          </SectionCard>
          <!-- team (س) / market & product / motivation (د) — readiness axis inputs -->
          <OpportunityReadinessProfile :o="o" />
          <!-- FRM-01 — the owner's answers to the additional questions of the project form -->
          <SectionCard v-if="o.extra_answers?.items?.length" :title="$t('platform.extra.title')" :sub="label('sensitivity', 'confidential')" class="lg:col-span-2"><ExtraAnswersView :data="o.extra_answers" /></SectionCard>
          <SectionCard :title="$t('admin.opportunity.disclosures')" :sub="label('sensitivity', 'confidential')" class="lg:col-span-2">
            <KeyValue :cols="2" :items="[{ label: $t('entrepreneur.form.disclosures.legalDisputes'), value: o.legal_disputes ? `${$t('common.yes')} — ${o.legal_disputes_desc ?? ''}` : $t('common.no') }, { label: $t('entrepreneur.form.disclosures.licenseRequired'), value: o.license_required ? `${$t('common.yes')} — ${o.license_status ?? ''}` : $t('common.no') }, { label: $t('entrepreneur.form.disclosures.operationalRisks'), value: o.operational_risks }, { label: $t('entrepreneur.form.disclosures.councilRelationship') + ' (' + label('sensitivity', 'internal') + ')', value: o.council_relationship ? `${$t('common.yes')} — ${o.council_relationship_desc ?? ''}` : $t('common.no') }, { label: $t('entrepreneur.form.disclosures.dataAccuracy'), value: date(o.data_accuracy_declared_at) }, { label: $t('entrepreneur.form.disclosures.teaserConsent'), value: o.teaser_publication_consent ? $t('common.yes') : $t('common.no') }]" />
          </SectionCard>
        </div>
        <!-- MESSAGES (MTG-03) -->
        <SectionCard v-if="tab === 'messages'" :title="$t('collab.messages.withOwner')" :sub="$t('collab.messages.withOwnerSub')"><LazyMessageThread subject-type="opportunity" :subject-key="uuid" staff /></SectionCard>
        <!-- TIMELINE -->
        <SectionCard v-show="tab === 'timeline'" :title="$t('admin.opportunity.tabs.timeline')" :sub="o.timeline_events.length ? $t('admin.timeline.sub') : undefined"><FileTimeline v-if="o.timeline_events.length" :items="o.timeline_events" /><Timeline v-else :items="o.timeline" /></SectionCard>
        <!-- NOTES -->
        <div v-show="tab === 'notes'" class="grid lg:grid-cols-[1fr_360px] gap-5">
          <SectionCard :title="$t('admin.opportunity.tabs.notes')" :sub="$t('admin.opportunity.noteHint')">
            <ul class="space-y-3"><li v-for="n in o.notes" :key="n.id" class="rounded-xl border border-border bg-ink/5 p-3 text-sm"><p class="leading-6">{{ n.body }}</p><p class="text-xs text-muted mt-1">{{ n.author?.name ?? '—' }} · {{ dateTime(n.created_at) }}</p></li><li v-if="!o.notes.length" class="text-sm text-muted">{{ $t('common.noData') }}</li></ul>
          </SectionCard>
          <SectionCard :title="$t('admin.opportunity.addNote')"><form novalidate @submit.prevent="addNote"><FormField v-model="noteForm.values.note_body" name="note_body" type="textarea" :label="$t('admin.opportunity.noteBody')" :error="noteForm.errors.value.note_body" :rows="4" :maxlength="RULES.longTextMax" required /><FormAlert :message="noteForm.formError.value" class="mt-3" /><AppButton type="submit" class="mt-3" :loading="noteForm.submitting.value">{{ $t('admin.opportunity.addNote') }}</AppButton></form></SectionCard>
        </div>
        <!-- DOCUMENTS -->
        <SectionCard v-show="tab === 'documents'" :title="$t('admin.opportunity.tabs.documents')" flush>
          <!-- DOC-03 — version history (latest first, older versions expandable); review + open per version -->
          <DocumentVersions :documents="o.documents" download-base="/admin/documents" show-notes show-size :caption="$t('admin.opportunity.tabs.documents')">
            <template #actions="{ doc }">
              <span class="inline-flex flex-wrap items-center justify-end gap-1.5 me-1.5">
                <input v-model="docNote[doc.uuid]" class="input !py-1.5 text-xs w-40" :maxlength="RULES.docNoteMax" :placeholder="doc.reviewer_note ?? $t('admin.opportunity.docNote')" :aria-label="`${$t('admin.opportunity.docNote')} — ${doc.title} v${doc.version}`" />
                <AppButton size="sm" variant="outline" :loading="busy === `doc-${doc.uuid}`" @click="acceptDoc(doc)">{{ $t('common.accept') }}</AppButton>
                <AppButton size="sm" variant="ghost" class="!text-danger hover:!bg-danger/10" @click="openReject(doc)">{{ $t('common.reject') }}</AppButton>
              </span>
            </template>
          </DocumentVersions>
        </SectionCard>
        <!-- COMPLETION -->
        <div v-show="tab === 'completion'" class="grid lg:grid-cols-2 gap-5">
          <SectionCard :title="$t('admin.opportunity.tabs.completion')">
            <ul class="space-y-3"><li v-for="c in o.completion_requests" :key="c.id" class="rounded-xl border border-border bg-ink/[0.03] p-3 text-sm"><div class="flex justify-between gap-2"><span class="text-xs text-muted">{{ c.requested_by?.name ?? '' }} · {{ date(c.created_at) }}</span><AppBadge kind="generic" :value="c.resolved_at ? 'approved' : 'pending'" :label="c.resolved_at ? $t('common.success') : `${$t('common.dueAt')}: ${date(c.due_at)}`" /></div><ul class="mt-2 space-y-1"><li v-for="(it, i) in c.items" :key="i" class="flex gap-2"><span :class="it.done ? 'text-success' : 'text-warning'">●</span><span><strong>{{ it.document_kind ? label('documentKind', it.document_kind) : it.field }}</strong> — {{ it.note }}</span></li></ul></li><li v-if="!o.completion_requests.length" class="text-sm text-muted">{{ $t('common.noData') }}</li></ul>
          </SectionCard>
          <SectionCard v-if="canDecide" :title="$t('admin.opportunity.completionBuilder')">
            <form class="space-y-3" novalidate @submit.prevent="sendCompletion">
              <div v-for="(it, i) in completionForm.values.cr_items" :key="i" class="grid grid-cols-[110px_1fr_auto] gap-2 items-start">
                <select v-model="it.kind" class="input" :aria-label="$t('admin.opportunity.completionItem')" @change="it.key = it.kind === 'document' ? 'pitch_deck' : ''"><option value="document">{{ $t('entrepreneur.form.documents.title') }}</option><option value="field">{{ $t('common.name') }}</option></select>
                <div class="space-y-2 min-w-0">
                  <select v-if="it.kind === 'document'" v-model="it.key" class="input" :aria-label="$t('entrepreneur.form.documents.kind')"><option v-for="k in DOCUMENT_KINDS" :key="k" :value="k">{{ label('documentKind', k) }}</option></select>
                  <template v-else>
                    <input v-model="it.key" class="input" :class="completionForm.errors.value[`cr_items.${i}.key`] && 'input-error'" :maxlength="RULES.completionFieldMax" :placeholder="$t('admin.opportunity.completionItem')" :aria-label="$t('admin.opportunity.completionItem')" :aria-invalid="!!completionForm.errors.value[`cr_items.${i}.key`]" @blur="completionForm.validateField(`cr_items.${i}.key`)" />
                    <p v-if="completionForm.errors.value[`cr_items.${i}.key`]" class="error-text" role="alert">{{ completionForm.errors.value[`cr_items.${i}.key`] }}</p>
                  </template>
                  <input v-model="it.note" class="input" :class="completionForm.errors.value[`cr_items.${i}.note`] && 'input-error'" :maxlength="RULES.completionNoteMax" :placeholder="$t('admin.opportunity.completionNote')" :aria-label="$t('admin.opportunity.completionNote')" @blur="completionForm.validateField(`cr_items.${i}.note`)" />
                  <p v-if="completionForm.errors.value[`cr_items.${i}.note`]" class="error-text" role="alert">{{ completionForm.errors.value[`cr_items.${i}.note`] }}</p>
                </div>
                <AppButton variant="ghost" size="sm" icon :aria-label="$t('common.delete')" @click="removeCrItem(i)">×</AppButton>
              </div>
              <p v-if="completionForm.errors.value.cr_items" class="error-text" role="alert">{{ completionForm.errors.value.cr_items }}</p>
              <AppButton variant="outline" size="sm" :disabled="completionForm.values.cr_items.length >= RULES.completionItemsMax" @click="addCrItem">+ {{ $t('admin.opportunity.addItem') }}</AppButton>
              <FormField v-model="completionForm.values.cr_due" name="cr_due" type="date" :label="$t('admin.opportunity.completionDue')" :error="completionForm.errors.value.cr_due" ltr />
              <FormAlert :message="completionForm.formError.value" />
              <AppButton type="submit" :loading="completionForm.submitting.value">{{ $t('admin.opportunity.sendCompletion') }}</AppButton>
            </form>
          </SectionCard>
        </div>
        <!-- TEASER -->
        <div v-show="tab === 'teaser'" class="grid lg:grid-cols-2 gap-5">
          <SectionCard :title="$t('admin.opportunity.tabs.teaser')" :sub="$t('admin.opportunity.teaserPublicOnly')">
            <form class="space-y-4" novalidate @submit.prevent="saveTeaser">
              <FormField v-model="teaserForm.values.summary_ar" name="summary_ar" type="textarea" :label="$t('admin.opportunity.teaserSummaryAr')" :error="teaserForm.errors.value.summary_ar" :rows="4" :maxlength="RULES.teaserMax" required />
              <FormField v-model="teaserForm.values.summary_en" name="summary_en" type="textarea" :label="$t('admin.opportunity.teaserSummaryEn')" :error="teaserForm.errors.value.summary_en" :rows="3" :maxlength="RULES.teaserMax" ltr />
              <FormField v-model="teaserForm.values.highlights" name="highlights" type="textarea" :label="$t('admin.opportunity.teaserHighlights')" :error="teaserForm.errors.value.highlights" :rows="3" :help="$t('admin.opportunity.teaserHighlightsHelp', { n: RULES.highlightsMax })" />
              <FormAlert :message="teaserForm.formError.value" />
              <div class="flex flex-wrap gap-2"><AppButton type="submit" :loading="teaserForm.submitting.value">{{ $t('admin.opportunity.saveTeaser') }}</AppButton><AppButton v-if="canMatch && o.teaser && !o.teaser.approved_by_council_at" variant="secondary" :loading="busy === 'teaser-approve'" @click="approveTeaser">{{ $t('admin.opportunity.approveTeaser') }}</AppButton></div>
            </form>
          </SectionCard>
          <SectionCard :title="$t('common.status')">
            <div v-if="o.teaser" class="space-y-3 text-sm">
              <p class="text-muted">{{ $t('common.version') }} <span class="num">{{ o.teaser.version }}</span></p>
              <div class="flex flex-wrap gap-2"><AppBadge kind="generic" :value="o.teaser.approved_by_owner_at ? 'approved' : 'pending'" :label="`${$t('admin.opportunity.ownerApproval')}: ${o.teaser.approved_by_owner_at ? date(o.teaser.approved_by_owner_at) : '—'}`" /><AppBadge kind="generic" :value="o.teaser.approved_by_council_at ? 'approved' : 'pending'" :label="`${$t('admin.opportunity.councilApproval')}: ${o.teaser.approved_by_council_at ? date(o.teaser.approved_by_council_at) : '—'}`" /></div>
              <div class="rounded-xl border border-border bg-ink/5 p-3 leading-6 text-text">{{ o.teaser.summary_ar }}</div>
              <ul class="flex flex-wrap gap-1.5"><li v-for="h in o.teaser.highlights ?? []" :key="h" class="chip text-xs !py-0.5">{{ h }}</li></ul>
            </div>
            <p v-else class="text-sm text-muted">{{ $t('common.noData') }}</p>
          </SectionCard>
        </div>
        <!-- EVALUATION -->
        <div v-show="tab === 'evaluation'" class="space-y-5">
          <SectionCard v-if="!o.evaluation.assignments.length && canDecide" :title="$t('admin.opportunity.assignEvaluators')">
            <form class="grid sm:grid-cols-3 gap-3 items-start" novalidate @submit.prevent="assignEvaluators">
              <FormField v-model="evaluatorsForm.values.ev1" name="ev1" type="select" :label="$t('admin.opportunity.evaluator1')" :options="o.evaluators.map((e) => ({ value: String(e.id), label: e.name }))" :error="evaluatorsForm.errors.value.ev1" required />
              <FormField v-model="evaluatorsForm.values.ev2" name="ev2" type="select" :label="$t('admin.opportunity.evaluator2')" :options="o.evaluators.map((e) => ({ value: String(e.id), label: e.name }))" :error="evaluatorsForm.errors.value.ev2" required />
              <AppButton type="submit" class="sm:mt-[1.625rem]" :loading="evaluatorsForm.submitting.value">{{ $t('admin.opportunity.assignEvaluators') }}</AppButton>
              <FormAlert :message="evaluatorsForm.formError.value" class="sm:col-span-3" />
            </form>
          </SectionCard>
          <SectionCard v-if="o.evaluation.assignments.length" :title="$t('admin.dashboard.evaluationPanel')" flush>
            <div class="overflow-x-auto"><table class="table-base">
              <thead><tr><th>{{ $t('admin.dashboard.axis') }} / {{ $t('admin.criteria.title') }}</th><th v-for="(a, i) in o.evaluation.assignments" :key="a.id">{{ $t('admin.dashboard.evaluator', { n: i + 1 }) }}<span class="block text-xs font-normal normal-case text-muted">{{ a.evaluator.name }} · <AppBadge kind="generic" :value="a.status" :label="label('assignmentStatus', a.status)" :dot="false" /></span></th><th>{{ $t('admin.opportunity.diff') }}<span v-if="o.evaluation.diff_threshold" class="block text-xs font-normal normal-case text-muted">{{ $t('admin.evaluationDecision.diffThreshold', { n: o.evaluation.diff_threshold }) }}</span></th></tr></thead>
              <tbody>
                <template v-for="ax in axes" :key="ax">
                  <tr class="bg-navy-50"><td class="font-semibold text-ink">{{ label('evaluationAxis', ax) }}</td><td v-for="a in o.evaluation.assignments" :key="a.id" class="num font-semibold">{{ axisValue(a.evaluation, ax) == null ? '—' : `${number(axisValue(a.evaluation, ax))} / 5` }}</td><td /></tr>
                  <tr v-for="c in o.evaluation.criteria.filter((x) => x.axis === ax)" :key="c.key"><td class="ps-6 text-muted">{{ criterionLabel(c) }} <span class="text-xs num">(×{{ Number(c.weight) }})</span></td><td v-for="a in o.evaluation.assignments" :key="a.id" class="num">{{ a.evaluation?.scores?.[c.key]?.score ?? '—' }}<span v-if="a.evaluation?.scores?.[c.key]?.note" class="block text-xs text-muted font-normal">{{ a.evaluation?.scores?.[c.key]?.note }}</span></td><td class="num" :class="o.evaluation.diffs.some((d) => d.criterion_key === c.key) ? 'text-warning font-medium' : 'text-muted'">{{ pairDiff(c.key) ?? '—' }}</td></tr>
                </template>
                <tr class="bg-accent/10"><td class="font-semibold text-ink">{{ $t('admin.dashboard.overall') }}</td><td v-for="a in o.evaluation.assignments" :key="a.id" class="num font-semibold">{{ a.evaluation ? number(a.evaluation.overall) : '—' }}</td><td /></tr>
                <tr><td class="font-medium">{{ $t('admin.dashboard.recommendation') }}</td><td v-for="a in o.evaluation.assignments" :key="a.id"><AppBadge v-if="a.evaluation" kind="eval" :value="a.evaluation.recommendation" /><p v-if="a.evaluation?.comment" class="text-xs text-muted mt-1">{{ a.evaluation.comment }}</p><div v-else-if="canEvaluate && !a.evaluation && isMine(a.evaluator.id)" class="flex flex-wrap gap-1"><AppButton v-if="!a.coi_declared_at" size="sm" variant="secondary" @click="coi(a.id, false)">{{ $t('admin.opportunity.coiTitle') }}: {{ $t('common.no') }}</AppButton><AppButton v-if="!a.coi_declared_at" size="sm" variant="ghost" @click="coi(a.id, true)">{{ $t('common.yes') }}</AppButton><AppButton v-if="a.coi_declared_at && !a.coi_has_conflict" size="sm" @click="openScore(a.id)">{{ $t('admin.opportunity.scoreTitle') }}</AppButton></div></td><td /></tr>
              </tbody></table></div>
            <div class="p-4 border-t border-border">
              <div v-if="o.evaluation.decision" class="flex flex-wrap items-center gap-3"><AppBadge kind="eval" :value="o.evaluation.decision.result" size="md" /><span class="text-sm text-muted">{{ o.evaluation.decision.decided_by?.name ?? '' }} · {{ date(o.evaluation.decision.decided_at) }}</span><p v-if="o.evaluation.decision.conditions" class="text-sm w-full">{{ $t('admin.opportunity.conditions') }}: {{ o.evaluation.decision.conditions }}</p><p v-if="o.evaluation.decision.reasons" class="text-sm w-full">{{ $t('admin.opportunity.reasons') }}: {{ o.evaluation.decision.reasons }}</p><div v-if="o.evaluation.decision.improvement_plan?.length" class="w-full"><p class="text-sm font-medium">{{ $t('admin.evaluationDecision.planTitle') }}</p><ol class="mt-1 list-decimal ps-5 text-sm space-y-0.5"><li v-for="(p, i) in o.evaluation.decision.improvement_plan" :key="i">{{ p.text }}<span v-if="p.due_at" class="text-xs text-muted"> — <span class="num">{{ date(p.due_at) }}</span></span></li></ol></div></div>
              <form v-else-if="canDecide && o.evaluation.assignments.length === 2 && o.evaluation.assignments.every((a) => a.evaluation)" class="grid sm:grid-cols-3 gap-3 items-start" novalidate @submit.prevent="decide">
                <FormField v-model="decisionForm.values.result" name="result" type="select" :label="$t('admin.opportunity.decisionResult')" :options="EVALUATION_RESULTS.map((r) => ({ value: r, label: label('evaluationResult', r) }))" :error="decisionForm.errors.value.result" required />
                <FormField v-model="decisionForm.values.conditions" name="conditions" :label="$t('admin.opportunity.conditions')" :error="decisionForm.errors.value.conditions" :maxlength="RULES.descMax" :required="decisionForm.values.result === 'eligible_with_conditions'" />
                <FormField v-model="decisionForm.values.reasons" name="reasons" :label="$t('admin.opportunity.reasons')" :error="decisionForm.errors.value.reasons" :maxlength="RULES.descMax" :required="decisionForm.values.result === 'not_eligible_now'" />
                <!-- EVL-03 — improvement plan shown to the owner -->
                <div v-if="planAllowed" class="sm:col-span-3">
                  <p class="label">{{ $t('admin.evaluationDecision.planTitle') }}</p>
                  <p class="text-xs text-muted mb-2">{{ $t('admin.evaluationDecision.planHint') }}</p>
                  <div v-for="(p, i) in decisionForm.values.improvement_plan" :key="i" class="mb-2">
                    <div class="grid grid-cols-1 sm:grid-cols-[1fr_160px_auto] gap-2">
                      <input v-model="p.text" class="input" :class="decisionForm.errors.value[`improvement_plan.${i}.text`] && 'input-error'" :maxlength="RULES.shortTextMax" :placeholder="$t('admin.evaluationDecision.planText')" :aria-label="$t('admin.evaluationDecision.planText')" :aria-invalid="!!decisionForm.errors.value[`improvement_plan.${i}.text`]" @blur="decisionForm.validateField(`improvement_plan.${i}.text`)" />
                      <input v-model="p.due_at" type="date" class="input ltr" :class="decisionForm.errors.value[`improvement_plan.${i}.due_at`] && 'input-error'" :min="todayIso()" :aria-label="$t('admin.evaluationDecision.planDue')" :title="$t('admin.evaluationDecision.planDue')" />
                      <AppButton variant="ghost" size="sm" icon :aria-label="`${$t('common.delete')} ${p.text}`" @click="removePlanItem(i)">×</AppButton>
                    </div>
                    <p v-if="decisionForm.errors.value[`improvement_plan.${i}.text`] || decisionForm.errors.value[`improvement_plan.${i}.due_at`]" class="error-text" role="alert">{{ decisionForm.errors.value[`improvement_plan.${i}.text`] ?? decisionForm.errors.value[`improvement_plan.${i}.due_at`] }}</p>
                  </div>
                  <p v-if="decisionForm.errors.value.improvement_plan" class="error-text" role="alert">{{ decisionForm.errors.value.improvement_plan }}</p>
                  <AppButton size="sm" variant="outline" :disabled="decisionForm.values.improvement_plan.length >= PLAN_MAX" @click="addPlanItem">+ {{ $t('admin.evaluationDecision.planAdd') }}</AppButton>
                </div>
                <FormAlert :message="decisionForm.formError.value" class="sm:col-span-3" />
                <AppButton type="submit" class="sm:col-span-3 justify-self-start" :loading="decisionForm.submitting.value">{{ $t('admin.opportunity.submitDecision') }}</AppButton>
              </form>
            </div>
          </SectionCard>
          <p v-else-if="!canDecide" class="text-sm text-muted">{{ $t('admin.opportunity.noEvaluation') }}</p>
          <!-- what the readiness criteria are scored on (evaluators read it here and in the score modal) -->
          <div class="grid gap-5"><OpportunityReadinessProfile :o="o" :sections="['team', 'market']" /></div>
        </div>
        <!-- NOMINATIONS -->
        <div v-show="tab === 'nominations'" class="grid lg:grid-cols-2 gap-5">
          <SectionCard :title="$t('admin.dashboard.candidates')" :sub="$t('admin.opportunity.maxNominations', { n: o.max_nominations })">
            <!-- MAT-01 manual search / filters -->
            <div class="mb-3 space-y-2" role="search" :aria-label="$t('admin.candidateSearch.label')">
              <input v-model="candFilter.search" type="search" class="input" maxlength="120" :placeholder="$t('admin.candidateSearch.placeholder')" :aria-label="$t('admin.candidateSearch.label')" />
              <div class="grid grid-cols-2 gap-2">
                <select v-model="candFilter.sector" class="input text-sm" :aria-label="$t('admin.listFilters.sector')"><option value="">{{ $t('admin.listFilters.sector') }}: {{ $t('common.all') }}</option><option v-for="s in tax.list('sectors')" :key="s.key" :value="s.key">{{ term(s) }}</option></select>
                <select v-model="candFilter.region" class="input text-sm" :aria-label="$t('admin.listFilters.region')"><option value="">{{ $t('admin.listFilters.region') }}: {{ $t('common.all') }}</option><option v-for="s in tax.list('regions')" :key="s.key" :value="s.key">{{ term(s) }}</option></select>
                <select v-model="candFilter.type" class="input text-sm" :aria-label="$t('admin.listFilters.investmentType')"><option value="">{{ $t('admin.listFilters.investmentType') }}: {{ $t('common.all') }}</option><option v-for="s in tax.list('investment_types')" :key="s.key" :value="s.key">{{ label('investmentType', s.key) }}</option></select>
                <select v-model="candFilter.investor_type" class="input text-sm" :aria-label="$t('admin.listFilters.investorType')"><option value="">{{ $t('admin.listFilters.investorType') }}: {{ $t('common.all') }}</option><option value="individual">{{ label('userType', 'investor_individual') }}</option><option value="institutional">{{ label('userType', 'investor_institutional') }}</option></select>
              </div>
              <p class="text-xs text-muted flex flex-wrap items-center gap-2"><span>{{ candFiltered ? $t('admin.candidateSearch.resultsHint') : $t('admin.dashboard.candidatesSub') }}</span><button v-if="candFiltered" type="button" class="link" @click="clearCandFilter">{{ $t('admin.candidateSearch.backToRanked') }}</button></p>
            </div>
            <p v-if="searching" class="py-2.5 text-sm text-muted" role="status">{{ $t('common.loading') }}</p>
            <ul class="divide-y divide-border" :aria-busy="searching">
              <li v-for="c in shownCandidates" :key="c.investor.uuid" class="py-2.5 flex items-center gap-3 text-sm">
                <input v-if="canMatch && !c.already_nominated" v-model="nominateForm.values.nominee_ids" type="checkbox" :value="candidateId(c)" class="h-4 w-4 rounded border-border accent-accent" :aria-label="c.investor.name" @change="nominateForm.clearErrors(['nominee_ids'])" /><span v-else class="w-4" />
                <div class="flex-1 min-w-0"><p class="font-medium">{{ c.investor.name }}</p><ul class="flex flex-wrap gap-1 mt-0.5"><li v-for="k in criteria5" :key="k" class="text-xs rounded-full px-2 py-px border" :class="flags(c)[k] ? 'bg-success/10 text-success border-success/30' : 'text-muted border-ink/15'">{{ $t(`investor.nominations.criteria.${k}`) }}</li></ul><p v-if="c.explanation" class="text-xs text-muted mt-1 leading-5">{{ c.explanation }}</p></div>
                <span class="text-end shrink-0"><span v-if="c.weighted_score != null" class="block num font-display font-extrabold text-accent-text" :title="$t('admin.candidateSearch.weightedHint')">{{ c.weighted_score }}%</span><span class="block num text-xs" :class="c.weighted_score != null ? 'text-muted' : 'font-bold text-accent-text'">{{ c.match_score }}/5</span></span><AppBadge v-if="c.already_nominated" kind="generic" value="approved" :label="$t('admin.dashboard.summarySent')" />
              </li>
              <li v-if="!shownCandidates.length && !searching" class="py-2.5 text-sm text-muted">{{ candFiltered ? $t('admin.candidateSearch.none') : $t('common.noData') }}</li>
            </ul>
            <form v-if="canMatch" class="mt-4 space-y-3" novalidate @submit.prevent="nominate"><p v-if="pickedNames.length" class="text-xs text-muted">{{ $t('admin.candidateSearch.selected') }}: <span class="text-text">{{ pickedNames.join(' · ') }}</span></p><p v-if="nominateForm.errors.value.nominee_ids" class="error-text" role="alert">{{ nominateForm.errors.value.nominee_ids }}</p><FormField v-model="nominateForm.values.rationale" name="rationale" type="textarea" :label="$t('admin.opportunity.nominateRationale')" :error="nominateForm.errors.value.rationale" :rows="2" :maxlength="RULES.noteMax" required /><FormAlert :message="nominateForm.formError.value" /><AppButton type="submit" :loading="nominateForm.submitting.value">{{ $t('admin.opportunity.nominate') }} (<span class="num">{{ nominateForm.values.nominee_ids.length }}</span>)</AppButton></form>
          </SectionCard>
          <div class="space-y-5">
            <SectionCard :title="$t('admin.opportunity.tabs.nominations')">
              <ul class="space-y-2 text-sm"><li v-for="n in o.nominations" :key="n.id" class="rounded-xl border border-border bg-ink/[0.03] p-3"><div class="flex justify-between gap-2"><span class="font-medium">{{ n.investor?.name }}</span><AppBadge kind="generic" :value="n.response ?? 'pending'" :label="n.response ? label('nominationResponse', n.response) : $t('common.new')" /></div><p class="text-xs text-muted mt-1">{{ date(n.sent_at) }} · {{ $t('investor.detail.matchScore', { n: n.match_score }) }}</p><p class="text-xs mt-1">{{ n.rationale }}</p><button v-if="auth.canAny('nominations.manage')" type="button" class="mt-1 text-xs link" @click="nominationThread = n.id">{{ $t('collab.messages.button') }}</button></li><li v-if="!o.nominations.length" class="text-muted">{{ $t('common.noData') }}</li></ul>
            </SectionCard>
            <SectionCard :title="$t('admin.interests.title')">
              <ul class="space-y-2 text-sm"><li v-for="i in o.interests" :key="i.id" class="rounded-xl border border-border bg-ink/[0.03] p-3 flex justify-between gap-2"><span>{{ i.investor?.name }}<span class="block text-xs text-muted">{{ date(i.created_at) }} · {{ i.next_step ?? i.closed_reason ?? '' }}</span></span><AppBadge kind="interest" :value="i.status" /></li><li v-if="!o.interests.length" class="text-muted">{{ $t('common.noData') }}</li></ul>
            </SectionCard>
          </div>
        </div>
      </div>

      <!-- MTG-03 — staff ↔ investor messages on a nomination -->
      <Modal :open="nominationThread !== null" :title="$t('collab.messages.withInvestor')" size="lg" @close="nominationThread = null"><LazyMessageThread v-if="nominationThread !== null" subject-type="nomination" :subject-key="nominationThread" staff /></Modal>
      <!-- transition modal -->
      <Modal :open="transitionOpen" :title="$t('admin.opportunity.transition')" @close="transitionOpen = false">
        <form class="space-y-4" novalidate @submit.prevent="transition">
          <FormField v-model="transitionForm.values.transition_to" name="transition_to" type="select" :label="$t('admin.opportunity.transitionTo')" :options="o.allowed_transitions.map((s) => ({ value: s, label: label('opportunityStatus', s) }))" :error="transitionForm.errors.value.transition_to" required />
          <FormField v-model="transitionForm.values.transition_reason" name="transition_reason" type="textarea" :label="$t('admin.opportunity.transitionReason')" :error="transitionForm.errors.value.transition_reason" :rows="3" :maxlength="RULES.noteMax" :required="REASON_REQUIRED.includes(transitionForm.values.transition_to as OpportunityStatus)" />
          <!-- ADM-07 — not_eligible is offered only to the supervisor (evaluations.approve) -->
          <InlineAlert v-if="!canDecide && (o.status === 'new' || o.status === 'under_review')" tone="info">{{ $t('admin.matrix.supervisorSignOff') }}</InlineAlert>
        </form>
        <template #footer><FormAlert :message="transitionForm.formError.value" class="basis-full" /><AppButton variant="ghost" @click="transitionOpen = false">{{ $t('common.cancel') }}</AppButton><AppButton :loading="transitionForm.submitting.value" @click="transition">{{ $t('common.confirm') }}</AppButton></template>
      </Modal>
      <!-- reject document (note required) -->
      <Modal :open="!!rejectingDoc" :title="$t('admin.opportunity.rejectDocTitle')" @close="rejectingDoc = null">
        <form class="space-y-3" novalidate @submit.prevent="rejectDoc">
          <p class="text-sm text-muted">{{ rejectingDoc?.title }}</p>
          <FormField v-model="rejectForm.values.reject_note" name="reject_note" type="textarea" :label="$t('admin.opportunity.rejectDocNote')" :error="rejectForm.errors.value.reject_note" :rows="3" :maxlength="RULES.docNoteMax" required />
        </form>
        <template #footer><FormAlert :message="rejectForm.formError.value" class="basis-full" /><AppButton variant="ghost" @click="rejectingDoc = null">{{ $t('common.cancel') }}</AppButton><AppButton variant="danger" :loading="rejectForm.submitting.value" @click="rejectDoc">{{ $t('common.reject') }}</AppButton></template>
      </Modal>
      <!-- score modal -->
      <Modal :open="scoringId != null" :title="$t('admin.opportunity.scoreTitle')" size="lg" @close="scoringId = null">
        <form class="space-y-4" novalidate @submit.prevent="submitScore">
          <OpportunityReadinessProfile :o="o" compact />
          <div v-for="ax in axes" :key="ax">
            <h3 class="font-display font-bold text-base text-ink mb-2">{{ label('evaluationAxis', ax) }}</h3>
            <div v-for="c in o.evaluation.criteria.filter((x) => x.axis === ax)" :key="c.key" class="rounded-xl border bg-ink/[0.03] p-3 mb-2" :class="scoreForm.errors.value[`scores.${c.key}.score`] ? 'border-danger' : 'border-border'">
              <p class="text-sm font-medium">{{ criterionLabel(c) }} <span class="text-danger" aria-hidden="true">*</span> <span class="text-xs text-muted num">(×{{ Number(c.weight) }})</span></p>
              <div class="mt-2 flex flex-wrap gap-1.5" role="radiogroup" :aria-label="criterionLabel(c)"><button v-for="s in RULES.scoreMax" :key="s" type="button" role="radio" :aria-checked="scoreForm.values.scores[c.key]?.score === s" class="chip num" :class="scoreForm.values.scores[c.key]?.score === s && 'chip-active'" :title="c.scale_descriptions?.[String(s) as '1']" @click="setScore(c.key, s)">{{ s }}</button></div>
              <p class="text-xs text-muted mt-1">{{ scoreForm.values.scores[c.key]?.score ? c.scale_descriptions?.[String(scoreForm.values.scores[c.key].score) as '1'] : '' }}</p>
              <p v-if="scoreForm.errors.value[`scores.${c.key}.score`]" class="error-text" role="alert">{{ $t('admin.opportunity.chooseScore') }}</p>
              <input v-if="scoreForm.values.scores[c.key]" v-model="scoreForm.values.scores[c.key].note" class="input mt-2 text-xs" :maxlength="RULES.docNoteMax" :placeholder="$t('admin.opportunity.scoreNote')" :aria-label="$t('admin.opportunity.scoreNote')" />
            </div>
          </div>
          <FormField v-model="scoreForm.values.recommendation" name="recommendation" type="select" :label="$t('admin.dashboard.recommendation')" :options="EVALUATION_RESULTS.map((r) => ({ value: r, label: label('evaluationResult', r) }))" :error="scoreForm.errors.value.recommendation" required />
          <FormField v-model="scoreForm.values.comment" name="comment" type="textarea" :label="$t('common.notes')" :error="scoreForm.errors.value.comment" :rows="2" :maxlength="RULES.longTextMax" />
        </form>
        <template #footer><FormAlert :message="scoreForm.formError.value" class="basis-full" /><AppButton variant="ghost" @click="scoringId = null">{{ $t('common.cancel') }}</AppButton><AppButton :loading="scoreForm.submitting.value" @click="submitScore">{{ $t('admin.opportunity.submitScore') }}</AppButton></template>
      </Modal>
    </template>
  </div>
</template>

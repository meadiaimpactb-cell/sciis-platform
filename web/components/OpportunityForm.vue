<script setup lang="ts">
/**
 * Multi-step opportunity form (ENT-01..03, ENT-05): basics, organization (conditional on entity), team & founders
 * (repeatable founders, max 6), project, impact (repeatable indicators), market & product, financials, documents,
 * motivation & commitment, disclosures & declarations. Autosaves drafts; checklist before submit.
 *
 * API contract (Laravel): PATCH takes the nested sections {basics, organization, team, impact, market, financials,
 * motivation, disclosures} (taxonomy ids, not keys); indicators go through POST/DELETE …/indicators; submit answers {data:{reference_no}}.
 * ENT-05: `locked_sections` (web steps) + `reopened_fields` from the resource drive what is editable while
 * `needs_completion` — locked fields are disabled and never sent.
 *
 * Verification layer: one vee-validate form (`useZodForm`) over the whole record, rules from utils/validation.ts
 * (mirrors UpdateOpportunityRequest / StoreIndicatorRequest). Autosave (debounced, silent) never waits for validation
 * — a draft may be incomplete; "next" validates the current step, "submit" validates every editable step.
 */
import { z } from 'zod'
import type { OpportunityFull, OpportunityFounder, Checklist, ImpactIndicator, DocumentResource, TaxonomyTerm } from '~/types/api'
import { INVESTMENT_TYPES, PROJECT_STAGES, REVENUE_STATES, LEGAL_FORMS, DOCUMENT_KINDS, INDICATOR_FREQUENCIES, PRODUCT_STAGES, FOUNDERS_COMMITMENTS, FOUNDERS_KNOWN_YEARS, DEV_TEAM_STRUCTURES, SUPPORT_NEEDS, MAX_FOUNDERS } from '~/types/enums'
import { RULES, atLeastOne, irisCodeField, normalizeIrisCode, optionalNumber, optionalText, optionalUrl, parseApiError, percentTotalOk, requiredChoice, requiredNumber, requiredString, vmsg, withProtocol } from '~/utils/validation'
import { confirmDialog, toastApiError, toastError, toastSuccess } from '~/utils/notify'

const props = defineProps<{ uuid: string }>()
const { t } = useI18n()
const api = useApi()
const auth = useAuthStore()
const tax = useTaxonomyStore()
const route = useRoute()
const router = useRouter()
const localePath = useLocalePath()
const { term, label, enumKey } = useEnumLabel()
const { number, relative } = useFormat()

type FormIndicator = ImpactIndicator & { _new?: boolean }
/** `_key` is a client-only row key (founders have no id). */
type FormFounder = { _key: number; name: string; role: string; linkedin_url: string; is_full_time: boolean }
type FundRow = { item: string; percent: number | string }

const opp = ref<OpportunityFull | null>(null)
const checklist = ref<Checklist | null>(null)
/** Indicators as last loaded from the server — diffed on save. */
let serverIndicators: ImpactIndicator[] = []
const dirty = ref(false)
const lastSaved = ref<string | null>(null)
const saving = ref(false)
const submitting = ref(false)
const isEntity = computed(() => auth.me?.type === 'entrepreneur_entity')

// ---------------------------------------------------------------- validation schema (central rules only)
const L = 'entrepreneur.form'
const filled = (v: unknown) => (typeof v === 'string' ? v.trim() !== '' : v !== null && v !== undefined && v !== '')
/**
 * Laravel serialises decimal columns (funding_amount, indicator baseline / target) as strings ("500000.00") and
 * `v-model.number` on an emptied input yields '' — coerce both before the number rules run.
 */
const toNumber = (v: unknown) => (v === '' ? null : typeof v === 'string' && v.trim() !== '' && !Number.isNaN(Number(v)) ? Number(v) : v)
const looseNumber = (opts: Parameters<typeof optionalNumber>[0] = {}) => z.preprocess(toNumber, optionalNumber(opts))
const numberRequired = (...args: Parameters<typeof requiredNumber>) => z.preprocess(toNumber, requiredNumber(...args))
const founderRow = z.looseObject({ name: optionalText(RULES.founderNameMax), role: optionalText(RULES.founderRoleMax), linkedin_url: optionalUrl(RULES.urlMax, true) })
const indicatorRow = z.looseObject({ name: optionalText(RULES.titleMax), unit: optionalText(RULES.indicatorUnitMax), data_source: optionalText(RULES.titleMax), baseline: looseNumber(), target: looseNumber(), iris_code: irisCodeField })
const fundRow = z.looseObject({ item: optionalText(RULES.titleMax), percent: looseNumber({ min: 0, max: RULES.percentTotal }) })
const organizationShape = z.looseObject({
  name: optionalText(RULES.titleMax),
  legal_form: z.string().nullish(),
  cr_number: optionalText(RULES.orgNumberMax),
  unified_number: optionalText(RULES.orgNumberMax),
  license_number: optionalText(RULES.licenseNumberMax),
  founded_on: z.string().nullish(),
  employees_count: looseNumber({ min: 0, max: RULES.employeesMax, int: true }),
  city: optionalText(RULES.cityMax),
})
const schema = z
  .looseObject({
    // basics
    name: requiredString(`${L}.basics.name`, RULES.titleMin, RULES.titleMax),
    short_description: requiredString(`${L}.basics.short`, 1, RULES.shortDescMax),
    // OPP-11 — optional English card fields
    name_en: optionalText(RULES.titleMax),
    short_description_en: optionalText(RULES.shortDescMax),
    sector_id: requiredChoice(`${L}.basics.sector`),
    stage: requiredChoice(`${L}.basics.stage`),
    region_ids: atLeastOne(z.number()),
    investment_type_keys: atLeastOne(z.string()),
    website: optionalUrl(RULES.urlMax, true),
    // organization (entity accounts — the required parts are checked in superRefine)
    organization: organizationShape,
    // team & founders
    founders_count: numberRequired(`${L}.team.foundersCount`, { min: 1, max: RULES.foundersCountMax, int: true }),
    team_size: numberRequired(`${L}.team.teamSize`, { min: 1, max: RULES.teamSizeMax, int: true }),
    founders_commitment: requiredChoice(`${L}.team.commitment`),
    founders_commitment_note: optionalText(RULES.commitmentNoteMax),
    prior_startup_experience_desc: optionalText(RULES.noteMax),
    industry_experience_desc: optionalText(RULES.noteMax),
    advisory_board_desc: optionalText(RULES.noteMax),
    founders: z.array(founderRow),
    // project
    problem_statement: requiredString(`${L}.project.problem`, 1, RULES.longTextMax),
    solution: requiredString(`${L}.project.solution`, 1, RULES.longTextMax),
    expected_change: requiredString(`${L}.project.expectedChange`, 1, RULES.longTextMax),
    beneficiaries_desc: requiredString(`${L}.project.beneficiariesDesc`, 1, RULES.descMax),
    beneficiaries_current: looseNumber({ min: 0, max: RULES.beneficiariesMax, int: true }),
    beneficiaries_target: numberRequired(`${L}.project.beneficiariesTarget`, { min: 0, max: RULES.beneficiariesMax, int: true }),
    revenue_model_type: optionalText(RULES.revenueModelTypeMax),
    revenue_model_desc: optionalText(RULES.descMax),
    revenue_state: requiredChoice(`${L}.project.revenueState`),
    // impact
    indicators: z.array(indicatorRow),
    // market & product
    product_stage: requiredChoice(`${L}.market.productStage`),
    target_customers: requiredString(`${L}.market.targetCustomers`, 1, RULES.descMax),
    competitive_advantage: requiredString(`${L}.market.competitiveAdvantage`, 1, RULES.descMax),
    market_size_desc: optionalText(RULES.descMax),
    competitors: optionalText(RULES.descMax),
    go_to_market: optionalText(RULES.descMax),
    technology_desc: optionalText(RULES.descMax),
    traction_desc: optionalText(RULES.descMax),
    scalability_desc: optionalText(RULES.descMax),
    // financials
    funding_amount: numberRequired(`${L}.financials.fundingAmount`, { min: 0, max: RULES.fundingMax }),
    use_of_funds: z.array(fundRow),
    prior_funding_desc: optionalText(RULES.descMax),
    // motivation
    why_now: requiredString(`${L}.motivation.whyNow`, 1, RULES.descMax),
    program_expectations: optionalText(RULES.descMax),
    // disclosures
    legal_disputes_desc: optionalText(RULES.descMax),
    license_status: optionalText(RULES.licenseStatusMax),
    operational_risks: optionalText(RULES.longTextMax),
    council_relationship_desc: optionalText(RULES.noteMax),
  })
  .superRefine((v, ctx) => {
    const required = (path: (string | number)[], labelKey: string) => ctx.addIssue({ code: 'custom', path, message: vmsg('requiredNamed', { field: t(labelKey) }) })
    if (isEntity.value) {
      const o = (v.organization ?? {}) as Record<string, unknown>
      if (!filled(o.name)) required(['organization', 'name'], `${L}.organization.name`)
      if (!filled(o.legal_form)) required(['organization', 'legal_form'], `${L}.organization.legalForm`)
    }
    // a founder row with any content needs a name (blank rows are ignored and never sent)
    ;((v.founders ?? []) as FormFounder[]).forEach((f, i) => {
      if (!filled(f.name) && (filled(f.role) || filled(f.linkedin_url))) required(['founders', i, 'name'], `${L}.team.founderName`)
    })
    // an indicator row with any content needs a name of at least RULES.indicatorNameMin characters
    ;((v.indicators ?? []) as unknown as FormIndicator[]).forEach((ind, i) => {
      const name = String(ind.name ?? '').trim()
      if (!name && (filled(ind.unit) || filled(ind.data_source) || filled(ind.baseline) || filled(ind.target))) required(['indicators', i, 'name'], `${L}.impact.indicator`)
      else if (name && name.length < RULES.indicatorNameMin) ctx.addIssue({ code: 'custom', path: ['indicators', i, 'name'], message: vmsg('minLength', { n: RULES.indicatorNameMin }) })
    })
    const funds = (v.use_of_funds ?? []) as FundRow[]
    funds.forEach((u, i) => { if (!filled(u.item) && Number(u.percent) > 0) required(['use_of_funds', i, 'item'], `${L}.financials.useItem`) })
    if (!percentTotalOk(funds)) ctx.addIssue({ code: 'custom', path: ['use_of_funds'], message: vmsg('percentTotal') })
  })
// One form over the whole record; `form` stays the reactive alias used by the template and payload().
const vf = useZodForm(schema as never, {} as Record<string, any>)
const form = vf.values
const fieldError = (path: string) => vf.errors.value[path]

const allSteps = computed(() => [
  { key: 'basics', label: t('entrepreneur.form.steps.basics') },
  ...(isEntity.value ? [{ key: 'organization', label: t('entrepreneur.form.steps.organization') }] : []),
  { key: 'team', label: t('entrepreneur.form.steps.team') },
  { key: 'project', label: t('entrepreneur.form.steps.project') },
  { key: 'impact', label: t('entrepreneur.form.steps.impact') },
  { key: 'market', label: t('entrepreneur.form.steps.market') },
  { key: 'financials', label: t('entrepreneur.form.steps.financials') },
  { key: 'documents', label: t('entrepreneur.form.steps.documents') },
  { key: 'motivation', label: t('entrepreneur.form.steps.motivation') },
  { key: 'disclosures', label: t('entrepreneur.form.steps.disclosures') },
])
const step = ref(Math.max(0, allSteps.value.findIndex((s) => s.key === route.query.step)))
const stepKey = computed(() => allSteps.value[step.value]?.key ?? 'basics')
/** Schema keys validated per step (nested paths are covered by their root key). */
const STEP_FIELDS: Record<string, string[]> = {
  basics: ['name', 'short_description', 'name_en', 'short_description_en', 'sector_id', 'stage', 'region_ids', 'investment_type_keys', 'website'],
  organization: ['organization'],
  team: ['founders_count', 'team_size', 'founders_commitment', 'founders_commitment_note', 'prior_startup_experience_desc', 'industry_experience_desc', 'advisory_board_desc', 'founders'],
  project: ['problem_statement', 'solution', 'expected_change', 'beneficiaries_desc', 'beneficiaries_current', 'beneficiaries_target', 'revenue_model_type', 'revenue_model_desc', 'revenue_state'],
  impact: ['indicators'],
  market: ['product_stage', 'target_customers', 'competitive_advantage', 'market_size_desc', 'competitors', 'go_to_market', 'technology_desc', 'traction_desc', 'scalability_desc'],
  financials: ['funding_amount', 'use_of_funds', 'prior_funding_desc'],
  documents: [],
  motivation: ['why_now', 'program_expectations', 'extra_answers'],
  disclosures: ['legal_disputes_desc', 'license_status', 'operational_risks', 'council_relationship_desc'],
}
const stepOf = (path: string) => Object.keys(STEP_FIELDS).find((k) => STEP_FIELDS[k].includes(path.split('.')[0])) ?? null

// ---------------------------------------------------------------- ENT-05 lock
const editableStatus = computed(() => !!opp.value && ['draft', 'needs_completion'].includes(opp.value.status))
const lockedSections = computed<string[]>(() => {
  if (!opp.value) return []
  if (opp.value.locked_sections) return opp.value.locked_sections
  return editableStatus.value ? [] : allSteps.value.map((s) => s.key)
})
const reopened = computed<string[] | null>(() => (opp.value?.status === 'needs_completion' && opp.value.reopened_fields ? opp.value.reopened_fields : null))
const sectionLocked = (key: string) => !editableStatus.value || lockedSections.value.includes(key)
const locked = computed(() => sectionLocked(stepKey.value))
/** Field-level lock: in needs_completion only the reopened fields (or their aliases) are editable. */
const fieldLocked = (name: string, section?: string) => {
  if (sectionLocked(section ?? stepKey.value)) return true
  if (!reopened.value) return false
  const aliases: Record<string, string[]> = { region_ids: ['regions'], investment_type_keys: ['investment_types', 'investment_type_ids', 'investmentTypes'], beneficiary_group_ids: ['beneficiary_groups'], sdg_ids: ['sdgs'], data_accuracy_declared: ['data_accuracy_declared_at'], indicators: ['primary_indicator'], how_heard_id: ['how_heard'] }
  return ![name, ...(aliases[name] ?? [])].some((k) => reopened.value!.includes(k))
}
const docKindLocked = (kind: string) => {
  if (!opp.value || opp.value.status === 'draft') return false
  if (opp.value.status !== 'needs_completion') return !['eligible', 'connected', 'negotiating', 'in_execution', 'operating'].includes(opp.value.status) ? true : false
  if (!reopened.value) return lockedSections.value.includes('documents')
  return !reopened.value.includes(`document:${kind}`) && !reopened.value.includes('documents')
}
/** The keys of a step the user can edit right now (locked sections / non-reopened fields are never validated). */
const editableKeys = (key: string) => (sectionLocked(key) ? [] : (STEP_FIELDS[key] ?? []).filter((f) => !fieldLocked(f, key)))
const validateStep = (key: string) => {
  const keys = editableKeys(key)
  return keys.length ? vf.validateFields(keys) : Promise.resolve(true)
}

// ---------------------------------------------------------------- hydrate
/** Set while hydrate() writes server data into the form, so the autosave watcher ignores those writes. */
let hydrating = false
/** IMP-03 — a row whose IRIS+ code is still being typed (invalid) is neither synced nor overwritten until it is valid. */
const irisPending = (i: FormIndicator) => { const c = normalizeIrisCode(i.iris_code); return !!c && !RULES.irisCodePattern.test(c) }
/**
 * `keepDrafts` (after a save): rows the user is still typing are not sent to the API (founder / indicator without a
 * name, use-of-funds amount without an item) — keep them in the form instead of letting the server copy drop them.
 */
const hydrate = (o: OpportunityFull, keepDrafts = false) => {
  const drafts = keepDrafts && Array.isArray(form.founders)
    ? {
        founders: (form.founders as FormFounder[]).filter((f) => !f.name.trim()),
        indicators: (form.indicators as FormIndicator[]).filter((i) => (i._new && !String(i.name ?? '').trim()) || irisPending(i)),
        funds: (form.use_of_funds as FundRow[]).filter((u) => !String(u.item ?? '').trim() && Number(u.percent) > 0),
      }
    : null
  hydrating = true
  opp.value = o
  const funds = [...(o.use_of_funds ?? []).map((u) => ({ ...u })), ...(drafts?.funds ?? [])]
  const typeKeys = o.investment_type_keys?.length
    ? [...o.investment_type_keys]
    : (o.investment_types ?? []).map((x) => enumKey(x as never)).filter(Boolean) as string[]
  const org = (o.organization ?? {}) as Record<string, unknown>
  // Laravel nests team / market / motivation and also sends them flat; the mock is flat only.
  const tm = o.team ?? o
  const mk = o.market ?? o
  const mv = o.motivation ?? o
  Object.assign(form, {
    name: (o.name ?? '').replace(/\s*\(افتراضي\)$/, ''),
    short_description: o.short_description ?? '',
    name_en: o.name_en ?? '',
    short_description_en: o.short_description_en ?? '',
    sector_id: o.sector_id ? String(o.sector_id) : '',
    stage: o.stage ?? '',
    website: o.website ?? '',
    region_ids: [...(o.region_ids ?? [])],
    investment_type_keys: typeKeys,
    beneficiary_group_ids: [...(o.beneficiary_group_ids ?? [])],
    sdg_ids: [...(o.sdg_ids ?? [])],
    organization: { name: '', legal_form: '', cr_number: '', unified_number: '', license_number: '', founded_on: '', employees_count: null, city: '', ...Object.fromEntries(Object.entries(org).filter(([, v]) => v !== null && v !== undefined)) },
    problem_statement: o.problem_statement ?? '',
    solution: o.solution ?? '',
    expected_change: o.expected_change ?? '',
    beneficiaries_desc: o.beneficiaries_desc ?? '',
    beneficiaries_current: o.beneficiaries_current ?? null,
    beneficiaries_target: o.beneficiaries_target ?? null,
    revenue_model_type: o.revenue_model_type ?? '',
    revenue_model_desc: o.revenue_model_desc ?? '',
    revenue_state: o.revenue_state ?? '',
    last_year_revenue_band_id: o.last_year_revenue_band_id ? String(o.last_year_revenue_band_id) : '',
    funding_amount: o.funding_amount ?? null,
    use_of_funds: funds.length ? funds : [{ item: '', percent: 0 }],
    prior_funding: !!o.prior_funding,
    prior_funding_desc: o.prior_funding_desc ?? '',
    legal_disputes: !!o.legal_disputes,
    legal_disputes_desc: o.legal_disputes_desc ?? '',
    license_required: !!o.license_required,
    license_status: o.license_status ?? '',
    operational_risks: o.operational_risks ?? '',
    council_relationship: !!o.council_relationship,
    council_relationship_desc: o.council_relationship_desc ?? '',
    data_accuracy_declared: !!o.data_accuracy_declared_at,
    teaser_publication_consent: !!o.teaser_publication_consent,
    // rows still being typed stay as typed (an existing row keeps its place)
    indicators: [
      ...(o.indicators ?? []).map((i) => drafts?.indicators.find((d) => !d._new && d.id === i.id) ?? { ...i, sdg_id: i.sdg_id ?? i.sdg?.id ?? null }),
      ...(drafts?.indicators.filter((d) => d._new) ?? []),
    ],
    // team & founders
    founders_count: tm.founders_count ?? null,
    team_size: tm.team_size ?? null,
    founders_commitment: tm.founders_commitment ?? '',
    founders_commitment_note: tm.founders_commitment_note ?? '',
    prior_startup_experience: !!tm.prior_startup_experience,
    prior_startup_experience_desc: tm.prior_startup_experience_desc ?? '',
    industry_experience: !!tm.industry_experience,
    industry_experience_desc: tm.industry_experience_desc ?? '',
    founders_known_years: tm.founders_known_years ?? '',
    has_advisory_board: !!tm.has_advisory_board,
    advisory_board_desc: tm.advisory_board_desc ?? '',
    dev_team_structure: tm.dev_team_structure ?? '',
    founders_agreement_signed: !!tm.founders_agreement_signed,
    founders: [...(tm.founders ?? []).map((f, i): FormFounder => ({ _key: i + 1, name: f.name ?? '', role: f.role ?? '', linkedin_url: f.linkedin_url ?? '', is_full_time: !!f.is_full_time })), ...(drafts?.founders ?? [])],
    // market & product
    product_stage: mk.product_stage ?? '',
    target_customers: mk.target_customers ?? '',
    market_size_desc: mk.market_size_desc ?? '',
    competitors: mk.competitors ?? '',
    competitive_advantage: mk.competitive_advantage ?? '',
    go_to_market: mk.go_to_market ?? '',
    technology_desc: mk.technology_desc ?? '',
    traction_desc: mk.traction_desc ?? '',
    scalability_desc: mk.scalability_desc ?? '',
    // motivation & commitment
    why_now: mv.why_now ?? '',
    support_needed: [...(mv.support_needed ?? [])],
    program_expectations: mv.program_expectations ?? '',
    how_heard_id: mv.how_heard_id ? String(mv.how_heard_id) : '',
    // FRM-01 — admin-defined additional questions ({question_id: value})
    extra_answers: { ...(o.extra_answers?.answers ?? {}) },
  })
  serverIndicators = (o.indicators ?? []).map((i) => ({ ...i }))
  // The watcher job for these writes runs in the same flush, i.e. before this tick callback.
  nextTick(() => { hydrating = false; dirty.value = false })
}
const loadChecklist = async () => { checklist.value = (await api.get<{ data: Checklist }>(`/entrepreneur/opportunities/${props.uuid}/checklist`)).data }
const load = async () => {
  try {
    const [o] = await Promise.all([api.get<{ data: OpportunityFull }>(`/entrepreneur/opportunities/${props.uuid}`), loadChecklist()])
    hydrate(o.data)
  } catch (e) { toastApiError(e) }
}
onMounted(load)

// ---------------------------------------------------------------- payload (nested, Laravel shape)
const num = (v: unknown) => (v === '' || v === null || v === undefined || Number.isNaN(Number(v)) ? null : Number(v))
const str = (v: unknown) => (v === null || v === undefined ? null : String(v).trim() || null)
const pick = <T extends Record<string, unknown>>(section: string, obj: T): Partial<T> => {
  if (!reopened.value) return obj
  // needs_completion: only the reopened fields may be sent (the API rejects anything else with 422).
  return Object.fromEntries(Object.entries(obj).filter(([k]) => !fieldLocked(k, section))) as Partial<T>
}
const payload = () => {
  const typeIds = (form.investment_type_keys as string[]).map((k) => tax.byKey('investment_types', k)?.id).filter((id): id is number => typeof id === 'number')
  const body: Record<string, unknown> = {}
  const basics = pick('basics', {
    name: str(form.name) ?? undefined,
    short_description: str(form.short_description),
    name_en: str(form.name_en),
    short_description_en: str(form.short_description_en),
    sector_id: num(form.sector_id),
    stage: form.stage || null,
    // "example.sa" → "https://example.sa" (the API validates a full URL)
    website: withProtocol(form.website),
    region_ids: form.region_ids,
    investment_type_ids: typeIds,
    investment_type_keys: form.investment_type_keys, // mock/legacy
  })
  if (basics.name === undefined) delete (basics as Record<string, unknown>).name
  const project = pick('project', {
    problem_statement: str(form.problem_statement),
    solution: str(form.solution),
    expected_change: str(form.expected_change),
    beneficiaries_desc: str(form.beneficiaries_desc),
    beneficiaries_current: num(form.beneficiaries_current),
    beneficiaries_target: num(form.beneficiaries_target),
    beneficiary_group_ids: form.beneficiary_group_ids,
    sdg_ids: form.sdg_ids,
    revenue_model_type: str(form.revenue_model_type),
    revenue_model_desc: str(form.revenue_model_desc),
    revenue_state: form.revenue_state || null,
  })
  const { revenue_model_type, revenue_model_desc, revenue_state, ...impact } = project
  const financials = pick('financials', {
    last_year_revenue_band_id: num(form.last_year_revenue_band_id),
    funding_amount: num(form.funding_amount),
    use_of_funds: (form.use_of_funds as FundRow[]).filter((u) => u.item).map((u) => ({ item: u.item, percent: Number(u.percent) || 0 })),
    prior_funding: !!form.prior_funding,
    prior_funding_desc: form.prior_funding ? str(form.prior_funding_desc) : null,
  })
  const disclosures = pick('disclosures', {
    legal_disputes: !!form.legal_disputes,
    legal_disputes_desc: form.legal_disputes ? str(form.legal_disputes_desc) : null,
    license_required: !!form.license_required,
    license_status: form.license_required ? str(form.license_status) : null,
    operational_risks: str(form.operational_risks),
    council_relationship: !!form.council_relationship,
    council_relationship_desc: form.council_relationship ? str(form.council_relationship_desc) : null,
    data_accuracy_declared: !!form.data_accuracy_declared,
    teaser_publication_consent: !!form.teaser_publication_consent,
  })
  const team = pick('team', {
    founders_count: num(form.founders_count),
    team_size: num(form.team_size),
    founders_commitment: form.founders_commitment || null,
    founders_commitment_note: str(form.founders_commitment_note),
    prior_startup_experience: !!form.prior_startup_experience,
    prior_startup_experience_desc: form.prior_startup_experience ? str(form.prior_startup_experience_desc) : null,
    industry_experience: !!form.industry_experience,
    industry_experience_desc: form.industry_experience ? str(form.industry_experience_desc) : null,
    founders_known_years: form.founders_known_years || null,
    has_advisory_board: !!form.has_advisory_board,
    advisory_board_desc: form.has_advisory_board ? str(form.advisory_board_desc) : null,
    dev_team_structure: form.dev_team_structure || null,
    founders_agreement_signed: !!form.founders_agreement_signed,
    // rows without a name are still being typed — never sent (the API requires a name per founder)
    founders: (form.founders as FormFounder[]).filter((f) => f.name.trim()).map((f): OpportunityFounder => ({ name: f.name.trim(), role: str(f.role), linkedin_url: withProtocol(f.linkedin_url), is_full_time: !!f.is_full_time })),
  })
  const market = pick('market', {
    product_stage: form.product_stage || null,
    target_customers: str(form.target_customers),
    market_size_desc: str(form.market_size_desc),
    competitors: str(form.competitors),
    competitive_advantage: str(form.competitive_advantage),
    go_to_market: str(form.go_to_market),
    technology_desc: str(form.technology_desc),
    traction_desc: str(form.traction_desc),
    scalability_desc: str(form.scalability_desc),
  })
  const motivation = pick('motivation', {
    why_now: str(form.why_now),
    support_needed: form.support_needed as string[],
    program_expectations: str(form.program_expectations),
    how_heard_id: num(form.how_heard_id),
  })
  // Empty use-of-funds must not be sent (the API validates the 100% sum only when rows exist).
  if (Array.isArray(financials.use_of_funds) && !financials.use_of_funds.length) delete (financials as Record<string, unknown>).use_of_funds
  if (Object.keys(basics).length && !sectionLocked('basics')) body.basics = basics
  if (Object.keys(team).length && !sectionLocked('team')) body.team = team
  if (Object.keys(market).length && !sectionLocked('market')) body.market = market
  if (Object.keys(motivation).length && !sectionLocked('motivation')) body.motivation = motivation
  if (!sectionLocked('project')) {
    if (Object.keys(impact).length) body.impact = impact
    const fin = { ...(revenue_model_type !== undefined ? { revenue_model_type } : {}), ...(revenue_model_desc !== undefined ? { revenue_model_desc } : {}), ...(revenue_state !== undefined ? { revenue_state } : {}) }
    if (Object.keys(fin).length) body.financials = { ...fin }
  }
  if (Object.keys(financials).length && !sectionLocked('financials')) body.financials = { ...((body.financials as Record<string, unknown>) ?? {}), ...financials }
  if (Object.keys(disclosures).length && !sectionLocked('disclosures')) body.disclosures = disclosures
  if (isEntity.value && !sectionLocked('organization') && !fieldLocked('organization', 'organization')) {
    const o = form.organization as Record<string, unknown>
    body.organization = {
      name: str(o.name), legal_form: o.legal_form || null, cr_number: str(o.cr_number), unified_number: str(o.unified_number), license_number: str(o.license_number),
      founded_on: str(o.founded_on), employees_count: num(o.employees_count), city: str(o.city), website: str(o.website),
    }
  }
  // FRM-01 — additional questions (types checked on save; required ones are a checklist item)
  if (!fieldLocked('extra_answers', 'motivation') && form.extra_answers) body.extra_answers = form.extra_answers
  return body
}

// ---------------------------------------------------------------- indicators sync (POST/DELETE routes)
const indicatorBody = (i: FormIndicator) => ({
  name: i.name, definition: i.definition || null, unit: i.unit || null, baseline: num(i.baseline), target: num(i.target), data_source: i.data_source || null,
  frequency: i.frequency || null, verification_method: i.verification_method || null, iris_code: normalizeIrisCode(i.iris_code) || null, sdg_id: i.sdg_id ?? null, is_primary: !!i.is_primary, is_disclosed: !!i.is_disclosed,
})
const sameIndicator = (a: ImpactIndicator, b: ImpactIndicator) => JSON.stringify(indicatorBody(a)) === JSON.stringify(indicatorBody(b))
const syncIndicators = async () => {
  if (sectionLocked('impact') || fieldLocked('indicators', 'impact')) return
  const current = (form.indicators as FormIndicator[]).filter((i) => i.name && i.name.trim())
  const base = `/entrepreneur/opportunities/${props.uuid}/indicators`
  let changed = false
  // removed or edited server rows → DELETE (edited ones are re-created below; there is no PUT route)
  for (const s of serverIndicators) {
    const match = current.find((c) => !c._new && c.id === s.id)
    if (match && irisPending(match)) continue
    if (!match || !sameIndicator(match, s)) { await api.del(`${base}/${s.id}`); changed = true }
  }
  // new rows + edited rows → POST (primary last so it wins the single-primary rule)
  const toCreate = current.filter((c) => !irisPending(c) && (c._new || !serverIndicators.some((s) => s.id === c.id && sameIndicator(c, s)))).sort((a, b) => Number(!!a.is_primary) - Number(!!b.is_primary))
  for (const c of toCreate) { await api.post(base, indicatorBody(c)); changed = true }
  return changed
}

// ---------------------------------------------------------------- server errors → fields
/** Laravel keys are nested by section (`basics.name`, `team.founders.0.name`, `impact.solution`); the form is flat. */
const SERVER_SECTIONS = ['basics', 'team', 'impact', 'market', 'financials', 'motivation', 'disclosures']
/** Put 422 errors under their fields; returns the form paths that got an error and the messages that fit no field. */
const placeServerErrors = (e: unknown) => {
  const fields: string[] = []
  const orphans: string[] = []
  const mapped: Record<string, string> = {}
  for (const [key, message] of Object.entries(parseApiError(e).errors)) {
    const segs = key.split('.')
    const path = SERVER_SECTIONS.includes(segs[0]) && segs.length > 1 ? segs.slice(1).join('.') : key
    if (stepOf(path)) { mapped[path] = message; fields.push(path) } else orphans.push(message)
  }
  if (fields.length) vf.setErrors(mapped as never)
  return { fields, orphans }
}
/** Failed save / submit: locked-field message first, field errors under the fields, anything else as a toast. */
/** Rate limit (429) on save / submit → form-level message above the action bar (FormAlert), not a toast. */
const rateLimited = ref<string | null>(null)
const reportError = (e: unknown, jump: boolean) => {
  const limited = parseApiError(e)
  if (limited.status === 429) { rateLimited.value = limited.message || t('notify.tooMany'); return }
  const locked = (e as { errors?: Record<string, string[]> }).errors?.locked_fields?.[0]
  if (locked) return toastError(locked)
  const { fields, orphans } = placeServerErrors(e)
  if (!fields.length || orphans.length) return toastApiError(e)
  const target = stepOf(fields[0])
  if (target && target !== stepKey.value) {
    if (jump) step.value = Math.max(0, allSteps.value.findIndex((s) => s.key === target))
    toastError(t('validation.fixErrors'))
  }
}

// ---------------------------------------------------------------- save / submit
const save = async (silent = false) => {
  if (!opp.value || !editableStatus.value) return
  saving.value = true
  rateLimited.value = null
  try {
    const body = payload()
    const r = Object.keys(body).length ? await api.patch<{ data: OpportunityFull }>(`/entrepreneur/opportunities/${props.uuid}`, body) : null
    const indicatorsChanged = await syncIndicators()
    const fresh = indicatorsChanged || !r ? (await api.get<{ data: OpportunityFull }>(`/entrepreneur/opportunities/${props.uuid}`)).data : r.data
    hydrate(fresh, true)
    await loadChecklist()
    lastSaved.value = new Date().toISOString()
    if (!silent) toastSuccess(t('notify.savedItem', { item: t('notify.entities.opportunity') }))
  } catch (e) {
    reportError(e, !silent)
  } finally { saving.value = false }
}
// autosave (debounced) on changes — never blocked by validation (a draft may be incomplete)
let timer: ReturnType<typeof setTimeout> | null = null
watch(form, () => { if (hydrating || !opp.value || !editableStatus.value) return; dirty.value = true; if (timer) clearTimeout(timer); timer = setTimeout(() => save(true), 1500) }, { deep: true })
onBeforeUnmount(() => timer && clearTimeout(timer))

/** Save the draft (as before), then only move on when the current step's editable fields are valid. */
const next = async () => {
  if (timer) clearTimeout(timer)
  if (dirty.value) await save(true)
  if (!(await validateStep(stepKey.value))) return toastError(t('validation.fixErrors'))
  step.value = Math.min(step.value + 1, allSteps.value.length - 1)
}
const prev = () => (step.value = Math.max(0, step.value - 1))

const addIndicator = () => (form.indicators as FormIndicator[]).push({ id: -Date.now(), _new: true, name: '', definition: '', unit: '', baseline: null, target: null, data_source: '', frequency: 'quarterly', verification_method: '', iris_code: '', sdg_id: null, is_primary: (form.indicators as FormIndicator[]).length === 0, is_disclosed: true })
/** A named row is already persisted by autosave → confirm with its name; a blank row is removed without asking. */
const removeIndicator = async (i: number) => {
  const name = String((form.indicators as FormIndicator[])[i]?.name ?? '').trim()
  if (name && !(await confirmDialog(t('notify.deleteTitle'), t('notify.deleteText', { name }), t('notify.deleteConfirm')))) return
  ;(form.indicators as FormIndicator[]).splice(i, 1)
  vf.clearErrors(['indicators'])
}
const setPrimary = (i: number) => (form.indicators as FormIndicator[]).forEach((x, idx) => (x.is_primary = idx === i))
const useTotal = computed(() => (form.use_of_funds as FundRow[] | undefined)?.reduce((a, b) => a + (Number(b.percent) || 0), 0) ?? 0)
const addFund = () => (form.use_of_funds as FundRow[]).push({ item: '', percent: 0 })
const removeFund = async (i: number) => {
  const row = (form.use_of_funds as FundRow[])[i]
  const name = String(row?.item ?? '').trim() || (Number(row?.percent) > 0 ? `${number(Number(row?.percent))}%` : '')
  if (name && !(await confirmDialog(t('notify.removeTitle'), t('notify.removeText', { name }), t('notify.removeConfirm')))) return
  ;(form.use_of_funds as FundRow[]).splice(i, 1)
  vf.clearErrors(['use_of_funds'])
}
const LINKEDIN_PLACEHOLDER = 'https://www.linkedin.com/in/'
let founderSeq = 1000
const addFounder = () => { if ((form.founders as FormFounder[]).length < MAX_FOUNDERS) (form.founders as FormFounder[]).push({ _key: ++founderSeq, name: '', role: '', linkedin_url: '', is_full_time: true }) }
const removeFounder = async (i: number) => {
  const name = String((form.founders as FormFounder[])[i]?.name ?? '').trim()
  if (name && !(await confirmDialog(t('notify.deleteTitle'), t('notify.deleteText', { name }), t('notify.deleteConfirm')))) return
  ;(form.founders as FormFounder[]).splice(i, 1)
  vf.clearErrors(['founders'])
}

const uploadKind = ref<string>('pitch_deck')
const uploading = ref(false)
const upload = async (f: File) => {
  uploading.value = true
  try {
    // Real API: multipart {kind, file}. Mock: metadata only.
    const body = api.mock ? { kind: uploadKind.value, file_name: f.name, mime: f.type, size_bytes: f.size, title: f.name.replace(/\.[^.]+$/, '') } : (() => { const fd = new FormData(); fd.append('kind', uploadKind.value); fd.append('file', f); return fd })()
    await api.post(`/entrepreneur/opportunities/${props.uuid}/documents`, body)
    toastSuccess(t('notify.uploaded', { item: t('notify.entities.document') }))
    await load()
  } catch (e) {
    const p = parseApiError(e); if (p.status === 422 || (p.status ?? 0) >= 500) toastError(Object.values(p.errors)[0] ?? t('notify.uploadFailed')); else toastApiError(e)
  } finally { uploading.value = false }
}
/** Index of the first editable step with invalid fields (every step is validated so all errors show), or -1. */
const firstInvalidStep = async () => {
  let first = -1
  for (const [i, s] of allSteps.value.entries()) if (!(await validateStep(s.key)) && first < 0) first = i
  return first
}
const submit = async () => {
  if (submitting.value) return
  if (timer) clearTimeout(timer)
  await save(true)
  const bad = await firstInvalidStep()
  if (bad >= 0) {
    step.value = bad
    return toastError(t('validation.fixErrors'))
  }
  if (!(await confirmDialog(t('notify.submitTitle'), t('notify.submitText', { name: form.name }), t('notify.submitConfirm')))) return
  submitting.value = true
  try {
    // Laravel: {data: {reference_no, status, …}} (#14); older mock builds put reference_no at the top level.
    const r = await api.post<{ data?: { reference_no?: string | null }; reference_no?: string | null }>(`/entrepreneur/opportunities/${props.uuid}/submit`)
    toastSuccess(t('entrepreneur.form.submitted', { ref: r.data?.reference_no ?? r.reference_no ?? '' }))
    router.push(localePath('/app/entrepreneur/dashboard'))
  } catch (e) {
    const checklistErrors = (e as { errors?: Record<string, string[]> }).errors?.checklist
    if (checklistErrors) toastError(`${t('entrepreneur.form.checklistMissing', { n: checklistErrors.length })}: ${checklistErrors.join('، ')}`)
    else reportError(e, true)
  } finally { submitting.value = false }
}

const sectorOpts = computed(() => tax.list('sectors').map((x) => ({ value: String(x.id), label: term(x) })))
const regionOpts = computed(() => tax.list('regions').map((x) => ({ value: x.id, label: term(x) })))
const benOpts = computed(() => tax.list('beneficiary_groups').map((x) => ({ value: x.id, label: term(x) })))
const sdgOpts = computed(() => tax.list('sdgs').map((x) => ({ value: x.id, label: term(x) })))
// `last_year_revenue_band_id` is a revenue_bands term on the API (not a typed web TaxonomyGroup); fall back to ranges in mock mode.
const rangeOpts = computed(() => {
  const bands = (tax.groups as Record<string, TaxonomyTerm[] | undefined>).revenue_bands?.filter((x) => x.is_active) ?? []
  return (bands.length ? bands : tax.list('investment_ranges')).map((x) => ({ value: String(x.id), label: term(x) }))
})
const stageOpts = PROJECT_STAGES.map((s) => ({ value: s, label: label('stage', s) }))
// Investment types are taxonomy-backed: prefer the API list (keys), fall back to the static enum.
const typeOpts = computed(() => (tax.list('investment_types').length ? tax.list('investment_types').map((x) => ({ value: x.key, label: term(x) })) : INVESTMENT_TYPES.map((s) => ({ value: s, label: label('investmentType', s) }))))
const revOpts = REVENUE_STATES.map((s) => ({ value: s, label: label('revenueState', s) }))
const legalOpts = LEGAL_FORMS.map((s) => ({ value: s, label: label('legalForm', s) }))
const kindOpts = DOCUMENT_KINDS.filter((k) => !['agreement', 'progress_evidence'].includes(k)).map((k) => ({ value: k, label: label('documentKind', k) }))
const freqOpts = INDICATOR_FREQUENCIES.map((f) => ({ value: f, label: label('frequency', f) }))
const commitmentOpts = FOUNDERS_COMMITMENTS.map((s) => ({ value: s, label: label('foundersCommitment', s) }))
const knownYearsOpts = FOUNDERS_KNOWN_YEARS.map((s) => ({ value: s, label: label('foundersKnownYears', s) }))
const devTeamOpts = DEV_TEAM_STRUCTURES.map((s) => ({ value: s, label: label('devTeamStructure', s) }))
const productStageOpts = PRODUCT_STAGES.map((s) => ({ value: s, label: label('productStage', s) }))
const supportOpts = SUPPORT_NEEDS.map((s) => ({ value: s, label: label('supportNeed', s) }))
const howHeardOpts = computed(() => tax.list('how_heard').map((x) => ({ value: String(x.id), label: term(x) })))
/** Help text + "shown to approved members" for the public market subset. */
const publicHelp = (key: string) => `${t(key)} · ${t('entrepreneur.form.market.publicBadge')}`
const maxMb = computed(() => tax.settings?.documents?.max_size_mb ?? 25)
const checklistItems = computed(() => checklist.value?.items ?? [])
const checklistReady = computed(() => checklist.value?.ready ?? checklist.value?.complete ?? false)
const checklistPercent = computed(() => checklist.value?.completion_percent ?? checklist.value?.percent ?? 0)
const missingBySection = computed(() => checklistItems.value.filter((i) => !i.done))
const documents = computed(() => (opp.value?.documents ?? []) as DocumentResource[])
</script>

<template>
  <div v-if="!opp" class="text-muted">{{ $t('common.loading') }}</div>
  <div v-else class="grid lg:grid-cols-[minmax(0,1fr)_300px] gap-6 items-start">
    <div class="min-w-0">
      <div class="card card-pad">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
          <Stepper :steps="allSteps" :current="step" free @go="step = $event" />
        </div>
        <div class="flex items-center justify-between text-xs text-muted mb-4" aria-live="polite">
          <span><AppBadge kind="opportunity" :value="opp.status" /></span>
          <span v-if="saving">{{ $t('common.saving') }}</span>
          <span v-else-if="lastSaved">{{ $t('common.autosaved', { time: relative(lastSaved) }) }}</span>
          <span v-else-if="dirty">{{ $t('common.unsavedChanges') }}</span>
        </div>
        <InlineAlert v-if="locked" tone="warning" class="mb-4">{{ $t('entrepreneur.form.lockedSection') }}</InlineAlert>
        <InlineAlert v-else-if="reopened" tone="info" class="mb-4">{{ $t('entrepreneur.form.lockedSection') }}</InlineAlert>
        <fieldset :disabled="locked" class="space-y-5 min-w-0">
          <!-- BASICS -->
          <template v-if="stepKey === 'basics'">
            <FormField v-model="form.name" name="name" :label="$t('entrepreneur.form.basics.name')" :error="fieldError('name')" :maxlength="RULES.titleMax" required :disabled="fieldLocked('name')" />
            <FormField v-model="form.short_description" name="short_description" type="textarea" :label="$t('entrepreneur.form.basics.short')" :help="$t('entrepreneur.form.basics.shortHelp')" :error="fieldError('short_description')" :maxlength="RULES.shortDescMax" :rows="3" required :disabled="fieldLocked('short_description')" />
            <!-- OPP-11 — optional English version of the public card (shown to investors browsing in English) -->
            <fieldset class="rounded-xl border border-border p-4 space-y-4 min-w-0">
              <legend class="px-1 text-sm font-medium text-ink">{{ $t('entrepreneur.form.basics.englishTitle') }}</legend>
              <p class="help !mt-0">{{ $t('entrepreneur.form.basics.englishHelp') }}</p>
              <FormField v-model="form.name_en" name="name_en" :label="$t('entrepreneur.form.basics.nameEn')" :error="fieldError('name_en')" :maxlength="RULES.titleMax" ltr :disabled="fieldLocked('name_en')" />
              <FormField v-model="form.short_description_en" name="short_description_en" type="textarea" :label="$t('entrepreneur.form.basics.shortEn')" :error="fieldError('short_description_en')" :maxlength="RULES.shortDescMax" :rows="2" ltr :disabled="fieldLocked('short_description_en')" />
            </fieldset>
            <div class="grid sm:grid-cols-2 gap-4">
              <FormField v-model="form.sector_id" name="sector_id" type="select" :label="$t('entrepreneur.form.basics.sector')" :options="sectorOpts" :error="fieldError('sector_id')" required :disabled="fieldLocked('sector_id')" />
              <FormField v-model="form.stage" name="stage" type="select" :label="$t('entrepreneur.form.basics.stage')" :options="stageOpts" :error="fieldError('stage')" required :disabled="fieldLocked('stage')" />
            </div>
            <FormField v-model="form.region_ids" name="region_ids" type="chips" :label="$t('entrepreneur.form.basics.regions')" :options="regionOpts" :error="fieldError('region_ids')" required :disabled="fieldLocked('region_ids')" />
            <FormField v-model="form.investment_type_keys" name="investment_type_keys" type="chips" :label="$t('entrepreneur.form.basics.investmentTypes')" :options="typeOpts" :error="fieldError('investment_type_keys')" required :disabled="fieldLocked('investment_type_keys')" />
            <FormField v-model="form.website" name="website" type="url" :label="$t('entrepreneur.form.basics.website')" :error="fieldError('website')" :maxlength="RULES.urlMax" placeholder="https://" ltr :disabled="fieldLocked('website')" />
          </template>
          <!-- ORGANIZATION -->
          <template v-else-if="stepKey === 'organization'">
            <fieldset :disabled="fieldLocked('organization')" class="grid sm:grid-cols-2 gap-4 min-w-0">
              <FormField v-model="form.organization.name" name="organization.name" :label="$t('entrepreneur.form.organization.name')" :error="fieldError('organization.name')" :maxlength="RULES.titleMax" required class="sm:col-span-2" />
              <FormField v-model="form.organization.legal_form" name="organization.legal_form" type="select" :label="$t('entrepreneur.form.organization.legalForm')" :options="legalOpts" :error="fieldError('organization.legal_form')" required />
              <FormField v-model="form.organization.cr_number" name="organization.cr_number" :label="$t('entrepreneur.form.organization.cr')" :error="fieldError('organization.cr_number')" :maxlength="RULES.orgNumberMax" ltr />
              <FormField v-model="form.organization.unified_number" name="organization.unified_number" :label="$t('entrepreneur.form.organization.unified')" :error="fieldError('organization.unified_number')" :maxlength="RULES.orgNumberMax" ltr />
              <FormField v-model="form.organization.license_number" name="organization.license_number" :label="$t('entrepreneur.form.organization.license')" :error="fieldError('organization.license_number')" :maxlength="RULES.licenseNumberMax" ltr />
              <FormField v-model="form.organization.founded_on" name="organization.founded_on" type="date" :label="$t('entrepreneur.form.organization.founded')" :error="fieldError('organization.founded_on')" ltr />
              <FormField v-model="form.organization.employees_count" name="organization.employees_count" type="number" :label="$t('entrepreneur.form.organization.employees')" :error="fieldError('organization.employees_count')" :min="0" :max="RULES.employeesMax" />
              <FormField v-model="form.organization.city" name="organization.city" :label="$t('entrepreneur.form.organization.city')" :error="fieldError('organization.city')" :maxlength="RULES.cityMax" />
            </fieldset>
          </template>
          <!-- TEAM & FOUNDERS (confidential) -->
          <template v-else-if="stepKey === 'team'">
            <div><h3 class="font-semibold">{{ $t('entrepreneur.form.team.title') }} <span class="text-xs font-normal text-muted">({{ label('sensitivity', 'confidential') }})</span></h3><p class="text-sm text-muted">{{ $t('entrepreneur.form.team.help') }}</p></div>
            <div class="grid sm:grid-cols-2 gap-4">
              <FormField v-model="form.founders_count" name="founders_count" type="number" :label="$t('entrepreneur.form.team.foundersCount')" :help="$t('entrepreneur.form.team.foundersCountHelp')" :error="fieldError('founders_count')" :min="1" :max="RULES.foundersCountMax" required :disabled="fieldLocked('founders_count')" />
              <FormField v-model="form.team_size" name="team_size" type="number" :label="$t('entrepreneur.form.team.teamSize')" :help="$t('entrepreneur.form.team.teamSizeHelp')" :error="fieldError('team_size')" :min="1" :max="RULES.teamSizeMax" required :disabled="fieldLocked('team_size')" />
              <FormField v-model="form.founders_commitment" name="founders_commitment" type="select" :label="$t('entrepreneur.form.team.commitment')" :help="$t('entrepreneur.form.team.commitmentHelp')" :options="commitmentOpts" :error="fieldError('founders_commitment')" required :disabled="fieldLocked('founders_commitment')" />
              <FormField v-model="form.founders_known_years" name="founders_known_years" type="select" :label="$t('entrepreneur.form.team.knownYears')" :help="$t('entrepreneur.form.team.knownYearsHelp')" :options="knownYearsOpts" :disabled="fieldLocked('founders_known_years')" />
            </div>
            <FormField v-model="form.founders_commitment_note" name="founders_commitment_note" :label="$t('entrepreneur.form.team.commitmentNote')" :help="$t('entrepreneur.form.team.commitmentNoteHelp')" :error="fieldError('founders_commitment_note')" :maxlength="RULES.commitmentNoteMax" :disabled="fieldLocked('founders_commitment_note')" />
            <div class="grid sm:grid-cols-2 gap-4">
              <FormField v-model="form.dev_team_structure" name="dev_team_structure" type="select" :label="$t('entrepreneur.form.team.devTeam')" :help="$t('entrepreneur.form.team.devTeamHelp')" :options="devTeamOpts" :disabled="fieldLocked('dev_team_structure')" />
            </div>
            <div class="space-y-3">
              <FormField v-model="form.prior_startup_experience" name="prior_startup_experience" type="checkbox" :label="$t('entrepreneur.form.team.priorStartup')" :disabled="fieldLocked('prior_startup_experience')" />
              <FormField v-if="form.prior_startup_experience" v-model="form.prior_startup_experience_desc" name="prior_startup_experience_desc" type="textarea" :label="$t('entrepreneur.form.team.priorStartupDesc')" :rows="2" :error="fieldError('prior_startup_experience_desc')" :maxlength="RULES.noteMax" :disabled="fieldLocked('prior_startup_experience_desc')" />
              <FormField v-model="form.industry_experience" name="industry_experience" type="checkbox" :label="$t('entrepreneur.form.team.industryExperience')" :disabled="fieldLocked('industry_experience')" />
              <FormField v-if="form.industry_experience" v-model="form.industry_experience_desc" name="industry_experience_desc" type="textarea" :label="$t('entrepreneur.form.team.industryExperienceDesc')" :rows="2" :error="fieldError('industry_experience_desc')" :maxlength="RULES.noteMax" :disabled="fieldLocked('industry_experience_desc')" />
              <FormField v-model="form.has_advisory_board" name="has_advisory_board" type="checkbox" :label="$t('entrepreneur.form.team.advisoryBoard')" :disabled="fieldLocked('has_advisory_board')" />
              <FormField v-if="form.has_advisory_board" v-model="form.advisory_board_desc" name="advisory_board_desc" type="textarea" :label="$t('entrepreneur.form.team.advisoryBoardDesc')" :rows="2" :error="fieldError('advisory_board_desc')" :maxlength="RULES.noteMax" :disabled="fieldLocked('advisory_board_desc')" />
              <FormField v-model="form.founders_agreement_signed" name="founders_agreement_signed" type="checkbox" :label="$t('entrepreneur.form.team.foundersAgreement')" :disabled="fieldLocked('founders_agreement_signed')" />
            </div>
            <fieldset :disabled="fieldLocked('founders')" class="min-w-0 space-y-3">
              <legend class="label">{{ $t('entrepreneur.form.team.foundersList') }} <span class="text-xs font-normal text-muted num">({{ number(form.founders.length) }}/{{ number(MAX_FOUNDERS) }})</span></legend>
              <p class="help !mt-0">{{ $t('entrepreneur.form.team.foundersListHelp', { n: MAX_FOUNDERS }) }}</p>
              <div v-for="(f, i) in (form.founders as FormFounder[])" :key="f._key" class="rounded-xl border border-border p-3 grid sm:grid-cols-2 gap-3">
                <FormField v-model="f.name" :name="`founders.${i}.name`" :label="$t('entrepreneur.form.team.founderName')" :error="fieldError(`founders.${i}.name`)" :maxlength="RULES.founderNameMax" required />
                <FormField v-model="f.role" :name="`founders.${i}.role`" :label="$t('entrepreneur.form.team.founderRole')" :placeholder="$t('entrepreneur.form.team.founderRolePlaceholder')" :error="fieldError(`founders.${i}.role`)" :maxlength="RULES.founderRoleMax" />
                <FormField v-model="f.linkedin_url" :name="`founders.${i}.linkedin_url`" type="url" :label="$t('entrepreneur.form.team.founderLinkedin')" :placeholder="LINKEDIN_PLACEHOLDER" :error="fieldError(`founders.${i}.linkedin_url`)" ltr :maxlength="RULES.urlMax" />
                <div class="flex items-end justify-between gap-3">
                  <FormField v-model="f.is_full_time" :name="`founders.${i}.is_full_time`" type="checkbox" :label="$t('entrepreneur.form.team.founderFullTime')" />
                  <AppButton variant="ghost" size="sm" :aria-label="`${$t('entrepreneur.form.team.removeFounder')} ${f.name}`" @click="removeFounder(i)">× {{ $t('entrepreneur.form.team.removeFounder') }}</AppButton>
                </div>
              </div>
              <p v-if="!form.founders.length" class="text-sm text-muted">{{ $t('entrepreneur.form.team.noFounders') }}</p>
              <AppButton variant="outline" size="sm" :disabled="form.founders.length >= MAX_FOUNDERS" @click="addFounder">+ {{ $t('entrepreneur.form.team.addFounder') }}</AppButton>
              <p v-if="form.founders.length >= MAX_FOUNDERS" class="help">{{ $t('entrepreneur.form.team.maxFounders', { n: MAX_FOUNDERS }) }}</p>
            </fieldset>
          </template>
          <!-- PROJECT -->
          <template v-else-if="stepKey === 'project'">
            <FormField v-model="form.problem_statement" name="problem_statement" type="textarea" :label="$t('entrepreneur.form.project.problem')" :rows="4" :error="fieldError('problem_statement')" required :disabled="fieldLocked('problem_statement')" />
            <FormField v-model="form.solution" name="solution" type="textarea" :label="$t('entrepreneur.form.project.solution')" :rows="4" :error="fieldError('solution')" required :disabled="fieldLocked('solution')" />
            <FormField v-model="form.expected_change" name="expected_change" type="textarea" :label="$t('entrepreneur.form.project.expectedChange')" :rows="3" :error="fieldError('expected_change')" required :disabled="fieldLocked('expected_change')" />
            <FormField v-model="form.beneficiaries_desc" name="beneficiaries_desc" type="textarea" :label="$t('entrepreneur.form.project.beneficiariesDesc')" :rows="3" :error="fieldError('beneficiaries_desc')" required :disabled="fieldLocked('beneficiaries_desc')" />
            <FormField v-model="form.beneficiary_group_ids" name="beneficiary_group_ids" type="chips" :label="$t('entrepreneur.form.project.beneficiaryGroups')" :options="benOpts" :disabled="fieldLocked('beneficiary_group_ids')" />
            <div class="grid sm:grid-cols-2 gap-4">
              <FormField v-model="form.beneficiaries_current" name="beneficiaries_current" type="number" :label="$t('entrepreneur.form.project.beneficiariesCurrent')" :error="fieldError('beneficiaries_current')" :min="0" :disabled="fieldLocked('beneficiaries_current')" />
              <FormField v-model="form.beneficiaries_target" name="beneficiaries_target" type="number" :label="$t('entrepreneur.form.project.beneficiariesTarget')" :error="fieldError('beneficiaries_target')" :min="0" required :disabled="fieldLocked('beneficiaries_target')" />
              <FormField v-model="form.revenue_model_type" name="revenue_model_type" :label="$t('entrepreneur.form.project.revenueModelType')" :error="fieldError('revenue_model_type')" :maxlength="RULES.revenueModelTypeMax" :disabled="fieldLocked('revenue_model_type')" />
              <FormField v-model="form.revenue_state" name="revenue_state" type="select" :label="$t('entrepreneur.form.project.revenueState')" :options="revOpts" :error="fieldError('revenue_state')" required :disabled="fieldLocked('revenue_state')" />
            </div>
            <FormField v-model="form.revenue_model_desc" name="revenue_model_desc" type="textarea" :label="$t('entrepreneur.form.project.revenueModelDesc')" :rows="2" :error="fieldError('revenue_model_desc')" :disabled="fieldLocked('revenue_model_desc')" />
            <FormField v-model="form.sdg_ids" name="sdg_ids" type="chips" :label="$t('entrepreneur.form.project.sdgs')" :options="sdgOpts" :disabled="fieldLocked('sdg_ids')" />
          </template>
          <!-- IMPACT -->
          <template v-else-if="stepKey === 'impact'">
            <div><h3 class="font-semibold">{{ $t('entrepreneur.form.impact.title') }}</h3><p class="text-sm text-muted">{{ $t('entrepreneur.form.impact.help') }}</p></div>
            <fieldset :disabled="fieldLocked('indicators')" class="space-y-3 min-w-0">
              <div class="card overflow-x-auto scrollbar-thin">
                <table class="table-base min-w-[1080px]">
                  <thead><tr><th>{{ $t('entrepreneur.form.impact.primary') }}</th><th>{{ $t('entrepreneur.form.impact.indicator') }}</th><th>{{ $t('entrepreneur.form.impact.unit') }}</th><th>{{ $t('entrepreneur.form.impact.baseline') }}</th><th>{{ $t('entrepreneur.form.impact.target') }}</th><th>{{ $t('entrepreneur.form.impact.source') }}</th><th>{{ $t('entrepreneur.form.impact.frequency') }}</th><th><abbr :title="$t('entrepreneur.form.impact.irisHelp')">{{ $t('entrepreneur.form.impact.irisCode') }}</abbr></th><th>{{ $t('entrepreneur.form.impact.disclosed') }}</th><th></th></tr></thead>
                  <tbody>
                    <tr v-for="(ind, i) in (form.indicators as FormIndicator[])" :key="ind.id ?? i" class="align-top">
                      <td><input type="radio" name="primary" :checked="ind.is_primary" :aria-label="$t('entrepreneur.form.impact.primary')" class="h-4 w-4 text-blue" @change="setPrimary(i)" /></td>
                      <td class="min-w-[200px]"><input v-model="ind.name" class="input" :class="fieldError(`indicators.${i}.name`) && 'input-error'" :maxlength="RULES.titleMax" :aria-label="$t('entrepreneur.form.impact.indicator')" :aria-invalid="!!fieldError(`indicators.${i}.name`)" @blur="vf.validateField(`indicators.${i}.name`)" /><p v-if="fieldError(`indicators.${i}.name`)" class="error-text" role="alert">{{ fieldError(`indicators.${i}.name`) }}</p></td>
                      <td><input v-model="ind.unit" class="input w-24" :maxlength="RULES.indicatorUnitMax" :aria-label="$t('entrepreneur.form.impact.unit')" /></td>
                      <td><input v-model.number="ind.baseline" type="number" class="input w-24 num" :aria-label="$t('entrepreneur.form.impact.baseline')" /></td>
                      <td><input v-model.number="ind.target" type="number" class="input w-24 num" :aria-label="$t('entrepreneur.form.impact.target')" /></td>
                      <td class="min-w-[160px]"><input v-model="ind.data_source" class="input" :maxlength="RULES.titleMax" :aria-label="$t('entrepreneur.form.impact.source')" /></td>
                      <td><select v-model="ind.frequency" class="input" :aria-label="$t('entrepreneur.form.impact.frequency')"><option v-for="f in freqOpts" :key="f.value" :value="f.value">{{ f.label }}</option></select></td>
                      <!-- IMP-03 — optional IRIS+ catalogue code -->
                      <td><input v-model="ind.iris_code" class="input w-28 num ltr uppercase" :class="fieldError(`indicators.${i}.iris_code`) && 'input-error'" :maxlength="RULES.irisCodeLength + 2" placeholder="PI1234" dir="ltr" autocomplete="off" :aria-label="$t('entrepreneur.form.impact.irisCode')" :aria-invalid="!!fieldError(`indicators.${i}.iris_code`)" @blur="vf.validateField(`indicators.${i}.iris_code`)" /><p v-if="fieldError(`indicators.${i}.iris_code`)" class="error-text" role="alert">{{ fieldError(`indicators.${i}.iris_code`) }}</p></td>
                      <td><input v-model="ind.is_disclosed" type="checkbox" class="h-4 w-4 rounded text-blue" :aria-label="$t('entrepreneur.form.impact.disclosed')" /></td>
                      <td><AppButton variant="ghost" size="sm" icon :aria-label="`${$t('entrepreneur.form.impact.removeRow')} ${ind.name ?? ''}`" @click="removeIndicator(i)">×</AppButton></td>
                    </tr>
                    <tr v-if="!form.indicators?.length"><td colspan="10" class="text-center text-muted py-6">{{ $t('entrepreneur.form.impact.empty') }}</td></tr>
                  </tbody>
                </table>
              </div>
              <AppButton variant="outline" size="sm" @click="addIndicator">+ {{ $t('entrepreneur.form.impact.addRow') }}</AppButton>
            </fieldset>
          </template>
          <!-- MARKET & PRODUCT -->
          <template v-else-if="stepKey === 'market'">
            <div><h3 class="font-semibold">{{ $t('entrepreneur.form.market.title') }}</h3><p class="text-sm text-muted">{{ $t('entrepreneur.form.market.help') }}</p></div>
            <div class="grid sm:grid-cols-2 gap-4">
              <FormField v-model="form.product_stage" name="product_stage" type="select" :label="$t('entrepreneur.form.market.productStage')" :help="publicHelp('entrepreneur.form.market.productStageHelp')" :options="productStageOpts" :error="fieldError('product_stage')" required :disabled="fieldLocked('product_stage')" />
            </div>
            <FormField v-model="form.target_customers" name="target_customers" type="textarea" :label="$t('entrepreneur.form.market.targetCustomers')" :help="publicHelp('entrepreneur.form.market.targetCustomersHelp')" :rows="3" :error="fieldError('target_customers')" :maxlength="RULES.descMax" required :disabled="fieldLocked('target_customers')" />
            <FormField v-model="form.competitive_advantage" name="competitive_advantage" type="textarea" :label="$t('entrepreneur.form.market.competitiveAdvantage')" :help="publicHelp('entrepreneur.form.market.competitiveAdvantageHelp')" :rows="3" :error="fieldError('competitive_advantage')" :maxlength="RULES.descMax" required :disabled="fieldLocked('competitive_advantage')" />
            <div class="grid md:grid-cols-2 gap-4">
              <FormField v-model="form.market_size_desc" name="market_size_desc" type="textarea" :label="$t('entrepreneur.form.market.marketSize')" :help="$t('entrepreneur.form.market.marketSizeHelp')" :rows="3" :error="fieldError('market_size_desc')" :maxlength="RULES.descMax" :disabled="fieldLocked('market_size_desc')" />
              <FormField v-model="form.competitors" name="competitors" type="textarea" :label="$t('entrepreneur.form.market.competitors')" :help="$t('entrepreneur.form.market.competitorsHelp')" :rows="3" :error="fieldError('competitors')" :maxlength="RULES.descMax" :disabled="fieldLocked('competitors')" />
              <FormField v-model="form.go_to_market" name="go_to_market" type="textarea" :label="$t('entrepreneur.form.market.goToMarket')" :help="$t('entrepreneur.form.market.goToMarketHelp')" :rows="3" :error="fieldError('go_to_market')" :maxlength="RULES.descMax" :disabled="fieldLocked('go_to_market')" />
              <FormField v-model="form.technology_desc" name="technology_desc" type="textarea" :label="$t('entrepreneur.form.market.technology')" :help="$t('entrepreneur.form.market.technologyHelp')" :rows="3" :error="fieldError('technology_desc')" :maxlength="RULES.descMax" :disabled="fieldLocked('technology_desc')" />
              <FormField v-model="form.traction_desc" name="traction_desc" type="textarea" :label="$t('entrepreneur.form.market.traction')" :help="publicHelp('entrepreneur.form.market.tractionHelp')" :rows="3" :error="fieldError('traction_desc')" :maxlength="RULES.descMax" :disabled="fieldLocked('traction_desc')" />
              <FormField v-model="form.scalability_desc" name="scalability_desc" type="textarea" :label="$t('entrepreneur.form.market.scalability')" :help="$t('entrepreneur.form.market.scalabilityHelp')" :rows="3" :error="fieldError('scalability_desc')" :maxlength="RULES.descMax" :disabled="fieldLocked('scalability_desc')" />
            </div>
          </template>
          <!-- FINANCIALS -->
          <template v-else-if="stepKey === 'financials'">
            <div class="grid sm:grid-cols-2 gap-4">
              <FormField v-model="form.funding_amount" name="funding_amount" type="number" :label="$t('entrepreneur.form.financials.fundingAmount')" :help="$t('entrepreneur.form.financials.fundingHelp')" :error="fieldError('funding_amount')" :min="0" :step="RULES.fundingStep" required :disabled="fieldLocked('funding_amount')" />
              <FormField v-model="form.last_year_revenue_band_id" name="last_year_revenue_band_id" type="select" :label="$t('entrepreneur.form.financials.lastYearRevenue')" :options="rangeOpts" :disabled="fieldLocked('last_year_revenue_band_id')" />
            </div>
            <fieldset :disabled="fieldLocked('use_of_funds')" class="min-w-0">
              <p class="label">{{ $t('entrepreneur.form.financials.useOfFunds') }} <span class="text-xs font-normal num" :class="useTotal === RULES.percentTotal ? 'text-success' : 'text-warning'">({{ number(useTotal) }}%)</span></p>
              <div class="space-y-2">
                <div v-for="(u, i) in (form.use_of_funds as FundRow[])" :key="i" class="flex gap-2 items-start">
                  <div class="flex-1 min-w-0">
                    <input v-model="u.item" class="input" :class="fieldError(`use_of_funds.${i}.item`) && 'input-error'" :maxlength="RULES.titleMax" :placeholder="$t('entrepreneur.form.financials.useItem')" :aria-label="$t('entrepreneur.form.financials.useItem')" :aria-invalid="!!fieldError(`use_of_funds.${i}.item`)" />
                    <p v-if="fieldError(`use_of_funds.${i}.item`)" class="error-text" role="alert">{{ fieldError(`use_of_funds.${i}.item`) }}</p>
                  </div>
                  <input v-model.number="u.percent" type="number" min="0" :max="RULES.percentTotal" class="input w-24 num" :class="fieldError(`use_of_funds.${i}.percent`) && 'input-error'" :aria-label="$t('entrepreneur.form.financials.usePercent')" />
                  <AppButton variant="ghost" size="sm" icon :aria-label="`${$t('common.delete')} ${u.item}`" @click="removeFund(i)">×</AppButton>
                </div>
              </div>
              <p v-if="fieldError('use_of_funds')" class="error-text" role="alert">{{ fieldError('use_of_funds') }}</p>
              <p v-else-if="useTotal !== RULES.percentTotal" class="help text-warning">{{ $t('validation.percentTotal') }}</p>
              <AppButton variant="outline" size="sm" class="mt-2" @click="addFund">+ {{ $t('entrepreneur.form.financials.addUse') }}</AppButton>
            </fieldset>
            <FormField v-model="form.prior_funding" name="prior_funding" type="checkbox" :label="$t('entrepreneur.form.financials.priorFunding')" :disabled="fieldLocked('prior_funding')" />
            <FormField v-if="form.prior_funding" v-model="form.prior_funding_desc" name="prior_funding_desc" type="textarea" :label="$t('entrepreneur.form.financials.priorFundingDesc')" :rows="2" :error="fieldError('prior_funding_desc')" :maxlength="RULES.descMax" :disabled="fieldLocked('prior_funding_desc')" />
          </template>
          <!-- DOCUMENTS -->
          <template v-else-if="stepKey === 'documents'">
            <div><h3 class="font-semibold">{{ $t('entrepreneur.form.documents.title') }}</h3><p class="text-sm text-muted">{{ $t('entrepreneur.form.documents.help', { size: maxMb }) }}</p></div>
            <div class="grid sm:grid-cols-[220px_1fr] gap-4 items-start">
              <FormField v-model="uploadKind" name="kind" type="select" :label="$t('entrepreneur.form.documents.kind')" :options="kindOpts" />
              <FileUpload :label="$t('entrepreneur.form.documents.file')" :max-mb="maxMb" :disabled="uploading || docKindLocked(uploadKind)" @file="upload" @error="toastError($event)" />
            </div>
            <InlineAlert v-if="docKindLocked(uploadKind) && !locked" tone="warning">{{ $t('entrepreneur.form.lockedSection') }}</InlineAlert>
            <!-- DOC-03 — latest version per document, older versions expandable; any version opens via a signed URL -->
            <div class="card overflow-hidden"><DocumentVersions :documents="documents" :download-base="api.mock ? null : '/me/documents'" show-notes :caption="$t('entrepreneur.form.documents.title')" /></div>
          </template>
          <!-- MOTIVATION & COMMITMENT (internal) -->
          <template v-else-if="stepKey === 'motivation'">
            <div><h3 class="font-semibold">{{ $t('entrepreneur.form.motivation.title') }} <span class="text-xs font-normal text-muted">({{ label('sensitivity', 'internal') }})</span></h3><p class="text-sm text-muted">{{ $t('entrepreneur.form.motivation.help') }}</p></div>
            <FormField v-model="form.why_now" name="why_now" type="textarea" :label="$t('entrepreneur.form.motivation.whyNow')" :help="$t('entrepreneur.form.motivation.whyNowHelp')" :rows="3" :error="fieldError('why_now')" :maxlength="RULES.descMax" required :disabled="fieldLocked('why_now')" />
            <FormField v-model="form.support_needed" name="support_needed" type="chips" :label="$t('entrepreneur.form.motivation.supportNeeded')" :help="$t('entrepreneur.form.motivation.supportNeededHelp')" :options="supportOpts" :disabled="fieldLocked('support_needed')" />
            <FormField v-model="form.program_expectations" name="program_expectations" type="textarea" :label="$t('entrepreneur.form.motivation.expectations')" :help="$t('entrepreneur.form.motivation.expectationsHelp')" :rows="3" :error="fieldError('program_expectations')" :maxlength="RULES.descMax" :disabled="fieldLocked('program_expectations')" />
            <div class="grid sm:grid-cols-2 gap-4">
              <FormField v-model="form.how_heard_id" name="how_heard_id" type="select" :label="$t('entrepreneur.form.motivation.howHeard')" :options="howHeardOpts" :disabled="fieldLocked('how_heard_id')" />
            </div>
            <!-- FRM-01 — admin-defined additional questions (hidden while none are published) -->
            <DynamicQuestions v-model="form.extra_answers" form-key="opportunity" :errors="vf.errors.value" error-prefix="extra_answers." :disabled="fieldLocked('extra_answers')" />
          </template>
          <!-- DISCLOSURES -->
          <template v-else-if="stepKey === 'disclosures'">
            <FormField v-model="form.legal_disputes" name="legal_disputes" type="checkbox" :label="$t('entrepreneur.form.disclosures.legalDisputes')" :disabled="fieldLocked('legal_disputes')" />
            <FormField v-if="form.legal_disputes" v-model="form.legal_disputes_desc" name="legal_disputes_desc" type="textarea" :label="$t('entrepreneur.form.disclosures.legalDisputesDesc')" :rows="2" :error="fieldError('legal_disputes_desc')" :maxlength="RULES.descMax" :disabled="fieldLocked('legal_disputes_desc')" />
            <FormField v-model="form.license_required" name="license_required" type="checkbox" :label="$t('entrepreneur.form.disclosures.licenseRequired')" :disabled="fieldLocked('license_required')" />
            <FormField v-if="form.license_required" v-model="form.license_status" name="license_status" :label="$t('entrepreneur.form.disclosures.licenseStatus')" :error="fieldError('license_status')" :maxlength="RULES.licenseStatusMax" :disabled="fieldLocked('license_status')" />
            <FormField v-model="form.operational_risks" name="operational_risks" type="textarea" :label="$t('entrepreneur.form.disclosures.operationalRisks')" :rows="3" :error="fieldError('operational_risks')" :maxlength="RULES.longTextMax" :disabled="fieldLocked('operational_risks')" />
            <FormField v-model="form.council_relationship" name="council_relationship" type="checkbox" :label="$t('entrepreneur.form.disclosures.councilRelationship')" :disabled="fieldLocked('council_relationship')" />
            <FormField v-if="form.council_relationship" v-model="form.council_relationship_desc" name="council_relationship_desc" :label="$t('entrepreneur.form.disclosures.councilRelationshipDesc')" :error="fieldError('council_relationship_desc')" :maxlength="RULES.noteMax" :disabled="fieldLocked('council_relationship_desc')" />
            <fieldset class="rounded-xl border border-border p-4 space-y-3">
              <legend class="px-1 text-sm font-medium">{{ $t('auth.register.declarations') }}</legend>
              <FormField v-model="form.data_accuracy_declared" name="data_accuracy_declared" type="checkbox" :label="$t('entrepreneur.form.disclosures.dataAccuracy')" :disabled="fieldLocked('data_accuracy_declared')" />
              <FormField v-model="form.teaser_publication_consent" name="teaser_publication_consent" type="checkbox" :label="$t('entrepreneur.form.disclosures.teaserConsent')" :disabled="fieldLocked('teaser_publication_consent')" />
              <p class="text-xs text-muted">{{ $t('entrepreneur.form.disclosures.fullFileNote') }}</p>
            </fieldset>
          </template>
        </fieldset>
        <FormAlert :message="rateLimited" class="mt-5" />
        <div class="flex items-center justify-between gap-3 pt-5 mt-5 border-t border-border">
          <AppButton v-if="step > 0" variant="ghost" @click="prev">{{ $t('common.previous') }}</AppButton><span v-else />
          <div class="flex gap-2">
            <AppButton variant="secondary" :loading="saving" :disabled="!editableStatus" @click="save()">{{ $t('entrepreneur.form.saveDraft') }}</AppButton>
            <AppButton v-if="step < allSteps.length - 1" @click="next">{{ $t('common.next') }}</AppButton>
            <AppButton v-else-if="editableStatus" :loading="submitting" :disabled="!checklistReady" @click="submit">{{ $t('entrepreneur.form.submit') }}</AppButton>
          </div>
        </div>
      </div>
    </div>
    <!-- checklist side -->
    <aside class="lg:sticky lg:top-6 space-y-4">
      <div class="card card-pad">
        <div class="flex items-center justify-between gap-3"><h3 class="font-semibold">{{ $t('entrepreneur.form.checklistTitle') }}</h3><CompletionRing :percent="checklistPercent" :size="56" :stroke="6" /></div>
        <p class="mt-2 text-sm" :class="checklistReady ? 'text-success' : 'text-warning'">{{ checklistReady ? $t('entrepreneur.form.checklistReady') : $t('entrepreneur.form.checklistMissing', { n: missingBySection.length }) }}</p>
        <ul class="mt-3 space-y-1.5 text-sm max-h-80 overflow-y-auto">
          <li v-for="it in checklistItems" :key="it.key" class="flex items-start gap-2">
            <svg v-if="it.done" class="h-4 w-4 text-success shrink-0 mt-0.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
            <span v-else class="h-4 w-4 rounded-full border-2 border-warning shrink-0 mt-0.5" aria-hidden="true" />
            <button type="button" class="text-start hover:text-blue kbd-focus rounded" :class="it.done ? 'text-muted' : ''" @click="step = Math.max(0, allSteps.findIndex((s) => s.key === it.section))">{{ it.label }}</button>
          </li>
        </ul>
        <AppButton v-if="editableStatus" block class="mt-4" :loading="submitting" :disabled="!checklistReady" @click="submit">{{ $t('entrepreneur.form.submit') }}</AppButton>
        <AppButton block variant="secondary" size="sm" class="mt-2" :to="`/app/entrepreneur/opportunities/${uuid}/preview`">{{ $t('entrepreneur.dashboard.fullPreview') }}</AppButton>
      </div>
      <Disclaimer variant="box" />
    </aside>
  </div>
</template>

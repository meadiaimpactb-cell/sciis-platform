<script setup lang="ts">
/**
 * Multi-step opportunity form (ENT-01..03, ENT-05): basics, organization (conditional on entity), project, impact
 * (repeatable indicators), financials, documents, disclosures & declarations. Autosaves drafts; checklist before submit.
 */
import type { OpportunityFull, Checklist, ImpactIndicator, DocumentResource } from '~/types/api'
import { INVESTMENT_TYPES, PROJECT_STAGES, REVENUE_STATES, LEGAL_FORMS, DOCUMENT_KINDS, INDICATOR_FREQUENCIES } from '~/types/enums'

const props = defineProps<{ uuid: string }>()
const { t } = useI18n()
const api = useApi()
const auth = useAuthStore()
const tax = useTaxonomyStore()
const toast = useToast()
const route = useRoute()
const router = useRouter()
const localePath = useLocalePath()
const { term, label } = useEnumLabel()
const { number, relative, date } = useFormat()

const opp = ref<OpportunityFull | null>(null)
const checklist = ref<Checklist | null>(null)
const form = reactive<Record<string, any>>({})
const dirty = ref(false)
const lastSaved = ref<string | null>(null)
const saving = ref(false)
const submitting = ref(false)
const errors = ref<Record<string, string>>({})
const isEntity = computed(() => auth.me?.type === 'entrepreneur_entity')

const allSteps = computed(() => [
  { key: 'basics', label: t('entrepreneur.form.steps.basics') },
  ...(isEntity.value ? [{ key: 'organization', label: t('entrepreneur.form.steps.organization') }] : []),
  { key: 'project', label: t('entrepreneur.form.steps.project') },
  { key: 'impact', label: t('entrepreneur.form.steps.impact') },
  { key: 'financials', label: t('entrepreneur.form.steps.financials') },
  { key: 'documents', label: t('entrepreneur.form.steps.documents') },
  { key: 'disclosures', label: t('entrepreneur.form.steps.disclosures') },
])
const step = ref(Math.max(0, allSteps.value.findIndex((s) => s.key === route.query.step)))
const stepKey = computed(() => allSteps.value[step.value]?.key)
const locked = computed(() => !!opp.value && !['draft', 'needs_completion'].includes(opp.value.status) && (opp.value.locked_sections ?? []).includes(stepKey.value))

const hydrate = (o: OpportunityFull) => {
  opp.value = o
  Object.assign(form, {
    name: o.name.replace(/\s*\(افتراضي\)$/, ''),
    short_description: o.short_description,
    sector_id: o.sector_id ? String(o.sector_id) : '',
    stage: o.stage ?? '',
    website: o.website ?? '',
    region_ids: [...o.region_ids],
    investment_type_keys: [...o.investment_type_keys],
    beneficiary_group_ids: [...o.beneficiary_group_ids],
    sdg_ids: [...o.sdg_ids],
    organization: { name: '', legal_form: '', cr_number: '', unified_number: '', license_number: '', founded_on: '', employees_count: null, city: '', ...(o.organization ?? {}) },
    problem_statement: o.problem_statement,
    solution: o.solution,
    expected_change: o.expected_change,
    beneficiaries_desc: o.beneficiaries_desc,
    beneficiaries_current: o.beneficiaries_current,
    beneficiaries_target: o.beneficiaries_target,
    revenue_model_type: o.revenue_model_type ?? '',
    revenue_model_desc: o.revenue_model_desc ?? '',
    revenue_state: o.revenue_state ?? '',
    last_year_revenue_band_id: o.last_year_revenue_band_id ? String(o.last_year_revenue_band_id) : '',
    funding_amount: o.funding_amount,
    use_of_funds: o.use_of_funds.length ? o.use_of_funds.map((u) => ({ ...u })) : [{ item: '', percent: 0 }],
    prior_funding: o.prior_funding,
    prior_funding_desc: o.prior_funding_desc ?? '',
    legal_disputes: o.legal_disputes,
    legal_disputes_desc: o.legal_disputes_desc ?? '',
    license_required: o.license_required,
    license_status: o.license_status ?? '',
    operational_risks: o.operational_risks ?? '',
    council_relationship: o.council_relationship,
    council_relationship_desc: o.council_relationship_desc ?? '',
    data_accuracy_declared: !!o.data_accuracy_declared_at,
    teaser_publication_consent: o.teaser_publication_consent,
    indicators: o.indicators.map((i) => ({ ...i })),
  })
  dirty.value = false
}
const load = async () => {
  const [o, c] = await Promise.all([api.get<{ data: OpportunityFull }>(`/entrepreneur/opportunities/${props.uuid}`), api.get<{ data: Checklist }>(`/entrepreneur/opportunities/${props.uuid}/checklist`)])
  hydrate(o.data)
  checklist.value = c.data
}
onMounted(load)

const payload = () => ({
  section: stepKey.value,
  ...form,
  sector_id: Number(form.sector_id) || null,
  stage: form.stage || null,
  revenue_state: form.revenue_state || null,
  last_year_revenue_band_id: Number(form.last_year_revenue_band_id) || null,
  use_of_funds: (form.use_of_funds as { item: string; percent: number }[]).filter((u) => u.item),
  organization: isEntity.value ? form.organization : undefined,
})
const save = async (silent = false) => {
  if (!opp.value || locked.value) return
  saving.value = true
  try {
    const r = await api.patch<{ data: OpportunityFull }>(`/entrepreneur/opportunities/${props.uuid}`, payload())
    opp.value = r.data
    checklist.value = (await api.get<{ data: Checklist }>(`/entrepreneur/opportunities/${props.uuid}/checklist`)).data
    dirty.value = false
    lastSaved.value = new Date().toISOString()
    if (!silent) toast.success(t('common.saved'))
  } catch (e) { toast.error((e as Error).message) } finally { saving.value = false }
}
// autosave (debounced) on changes
let timer: ReturnType<typeof setTimeout> | null = null
watch(form, () => { if (!opp.value) return; dirty.value = true; if (timer) clearTimeout(timer); timer = setTimeout(() => save(true), 1500) }, { deep: true })
onBeforeUnmount(() => timer && clearTimeout(timer))

const next = async () => { await save(true); step.value = Math.min(step.value + 1, allSteps.value.length - 1) }
const prev = () => (step.value = Math.max(0, step.value - 1))

const addIndicator = () => (form.indicators as ImpactIndicator[]).push({ id: Date.now(), name: '', definition: '', unit: '', baseline: null, target: null, data_source: '', frequency: 'quarterly', verification_method: '', is_primary: (form.indicators as ImpactIndicator[]).length === 0, is_disclosed: true })
const removeIndicator = (i: number) => (form.indicators as ImpactIndicator[]).splice(i, 1)
const setPrimary = (i: number) => (form.indicators as ImpactIndicator[]).forEach((x, idx) => (x.is_primary = idx === i))
const useTotal = computed(() => (form.use_of_funds as { percent: number }[] | undefined)?.reduce((a, b) => a + (Number(b.percent) || 0), 0) ?? 0)

const uploadKind = ref<string>('pitch_deck')
const uploading = ref(false)
const upload = async (f: File) => {
  uploading.value = true
  try {
    // Real API: multipart {kind, file}. Mock: metadata only.
    const body = api.mock ? { kind: uploadKind.value, file_name: f.name, mime: f.type, size_bytes: f.size, title: f.name.replace(/\.[^.]+$/, '') } : (() => { const fd = new FormData(); fd.append('kind', uploadKind.value); fd.append('file', f); return fd })()
    await api.post(`/entrepreneur/opportunities/${props.uuid}/documents`, body)
    toast.success(t('entrepreneur.form.documents.uploaded'))
    await load()
  } catch (e) { toast.error((e as Error).message) } finally { uploading.value = false }
}
const submit = async () => {
  await save(true)
  submitting.value = true
  try {
    const r = await api.post<{ data: OpportunityFull; reference_no: string }>(`/entrepreneur/opportunities/${props.uuid}/submit`)
    toast.success(t('entrepreneur.form.submitted', { ref: r.reference_no }))
    router.push(localePath('/app/entrepreneur/dashboard'))
  } catch (e) {
    const err = e as { errors?: Record<string, string[]>; message: string }
    toast.error(err.errors?.checklist ? `${t('entrepreneur.form.checklistMissing', { n: err.errors.checklist.length })}: ${err.errors.checklist.join('، ')}` : err.message)
  } finally { submitting.value = false }
}

const sectorOpts = computed(() => tax.list('sectors').map((x) => ({ value: String(x.id), label: term(x) })))
const regionOpts = computed(() => tax.list('regions').map((x) => ({ value: x.id, label: term(x) })))
const benOpts = computed(() => tax.list('beneficiary_groups').map((x) => ({ value: x.id, label: term(x) })))
const sdgOpts = computed(() => tax.list('sdgs').map((x) => ({ value: x.id, label: term(x) })))
const rangeOpts = computed(() => tax.list('investment_ranges').map((x) => ({ value: String(x.id), label: term(x) })))
const stageOpts = PROJECT_STAGES.map((s) => ({ value: s, label: label('stage', s) }))
const typeOpts = INVESTMENT_TYPES.map((s) => ({ value: s, label: label('investmentType', s) }))
const revOpts = REVENUE_STATES.map((s) => ({ value: s, label: label('revenueState', s) }))
const legalOpts = LEGAL_FORMS.map((s) => ({ value: s, label: label('legalForm', s) }))
const kindOpts = DOCUMENT_KINDS.filter((k) => !['agreement', 'progress_evidence'].includes(k)).map((k) => ({ value: k, label: label('documentKind', k) }))
const freqOpts = INDICATOR_FREQUENCIES.map((f) => ({ value: f, label: label('frequency', f) }))
const maxMb = computed(() => 25)
const missingBySection = computed(() => (checklist.value?.items ?? []).filter((i) => !i.done))
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
        <fieldset :disabled="locked" class="space-y-5 min-w-0">
          <!-- BASICS -->
          <template v-if="stepKey === 'basics'">
            <FormField v-model="form.name" name="name" :label="$t('entrepreneur.form.basics.name')" required />
            <FormField v-model="form.short_description" name="short_description" type="textarea" :label="$t('entrepreneur.form.basics.short')" :help="$t('entrepreneur.form.basics.shortHelp')" :maxlength="280" :rows="3" required />
            <div class="grid sm:grid-cols-2 gap-4">
              <FormField v-model="form.sector_id" name="sector_id" type="select" :label="$t('entrepreneur.form.basics.sector')" :options="sectorOpts" required />
              <FormField v-model="form.stage" name="stage" type="select" :label="$t('entrepreneur.form.basics.stage')" :options="stageOpts" required />
            </div>
            <FormField v-model="form.region_ids" name="region_ids" type="chips" :label="$t('entrepreneur.form.basics.regions')" :options="regionOpts" required />
            <FormField v-model="form.investment_type_keys" name="investment_type_keys" type="chips" :label="$t('entrepreneur.form.basics.investmentTypes')" :options="typeOpts" required />
            <FormField v-model="form.website" name="website" type="url" :label="$t('entrepreneur.form.basics.website')" ltr />
          </template>
          <!-- ORGANIZATION -->
          <template v-else-if="stepKey === 'organization'">
            <div class="grid sm:grid-cols-2 gap-4">
              <FormField v-model="form.organization.name" name="org_name" :label="$t('entrepreneur.form.organization.name')" required class="sm:col-span-2" />
              <FormField v-model="form.organization.legal_form" name="legal_form" type="select" :label="$t('entrepreneur.form.organization.legalForm')" :options="legalOpts" required />
              <FormField v-model="form.organization.cr_number" name="cr_number" :label="$t('entrepreneur.form.organization.cr')" ltr />
              <FormField v-model="form.organization.unified_number" name="unified_number" :label="$t('entrepreneur.form.organization.unified')" ltr />
              <FormField v-model="form.organization.license_number" name="license_number" :label="$t('entrepreneur.form.organization.license')" ltr />
              <FormField v-model="form.organization.founded_on" name="founded_on" type="date" :label="$t('entrepreneur.form.organization.founded')" ltr />
              <FormField v-model="form.organization.employees_count" name="employees_count" type="number" :label="$t('entrepreneur.form.organization.employees')" :min="0" />
              <FormField v-model="form.organization.city" name="org_city" :label="$t('entrepreneur.form.organization.city')" />
            </div>
          </template>
          <!-- PROJECT -->
          <template v-else-if="stepKey === 'project'">
            <FormField v-model="form.problem_statement" name="problem_statement" type="textarea" :label="$t('entrepreneur.form.project.problem')" :rows="4" required />
            <FormField v-model="form.solution" name="solution" type="textarea" :label="$t('entrepreneur.form.project.solution')" :rows="4" required />
            <FormField v-model="form.expected_change" name="expected_change" type="textarea" :label="$t('entrepreneur.form.project.expectedChange')" :rows="3" required />
            <FormField v-model="form.beneficiaries_desc" name="beneficiaries_desc" type="textarea" :label="$t('entrepreneur.form.project.beneficiariesDesc')" :rows="3" required />
            <FormField v-model="form.beneficiary_group_ids" name="beneficiary_group_ids" type="chips" :label="$t('entrepreneur.form.project.beneficiaryGroups')" :options="benOpts" />
            <div class="grid sm:grid-cols-2 gap-4">
              <FormField v-model="form.beneficiaries_current" name="beneficiaries_current" type="number" :label="$t('entrepreneur.form.project.beneficiariesCurrent')" :min="0" />
              <FormField v-model="form.beneficiaries_target" name="beneficiaries_target" type="number" :label="$t('entrepreneur.form.project.beneficiariesTarget')" :min="0" required />
              <FormField v-model="form.revenue_model_type" name="revenue_model_type" :label="$t('entrepreneur.form.project.revenueModelType')" />
              <FormField v-model="form.revenue_state" name="revenue_state" type="select" :label="$t('entrepreneur.form.project.revenueState')" :options="revOpts" />
            </div>
            <FormField v-model="form.revenue_model_desc" name="revenue_model_desc" type="textarea" :label="$t('entrepreneur.form.project.revenueModelDesc')" :rows="2" />
            <FormField v-model="form.sdg_ids" name="sdg_ids" type="chips" :label="$t('entrepreneur.form.project.sdgs')" :options="sdgOpts" />
          </template>
          <!-- IMPACT -->
          <template v-else-if="stepKey === 'impact'">
            <div><h3 class="font-semibold">{{ $t('entrepreneur.form.impact.title') }}</h3><p class="text-sm text-muted">{{ $t('entrepreneur.form.impact.help') }}</p></div>
            <div class="card overflow-x-auto scrollbar-thin">
              <table class="table-base min-w-[960px]">
                <thead><tr><th>{{ $t('entrepreneur.form.impact.primary') }}</th><th>{{ $t('entrepreneur.form.impact.indicator') }}</th><th>{{ $t('entrepreneur.form.impact.unit') }}</th><th>{{ $t('entrepreneur.form.impact.baseline') }}</th><th>{{ $t('entrepreneur.form.impact.target') }}</th><th>{{ $t('entrepreneur.form.impact.source') }}</th><th>{{ $t('entrepreneur.form.impact.frequency') }}</th><th>{{ $t('entrepreneur.form.impact.disclosed') }}</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="(ind, i) in (form.indicators as ImpactIndicator[])" :key="ind.id ?? i">
                    <td><input type="radio" name="primary" :checked="ind.is_primary" :aria-label="$t('entrepreneur.form.impact.primary')" class="h-4 w-4 text-blue" @change="setPrimary(i)" /></td>
                    <td class="min-w-[200px]"><input v-model="ind.name" class="input" :aria-label="$t('entrepreneur.form.impact.indicator')" /></td>
                    <td><input v-model="ind.unit" class="input w-24" :aria-label="$t('entrepreneur.form.impact.unit')" /></td>
                    <td><input v-model.number="ind.baseline" type="number" class="input w-24 num" :aria-label="$t('entrepreneur.form.impact.baseline')" /></td>
                    <td><input v-model.number="ind.target" type="number" class="input w-24 num" :aria-label="$t('entrepreneur.form.impact.target')" /></td>
                    <td class="min-w-[160px]"><input v-model="ind.data_source" class="input" :aria-label="$t('entrepreneur.form.impact.source')" /></td>
                    <td><select v-model="ind.frequency" class="input" :aria-label="$t('entrepreneur.form.impact.frequency')"><option v-for="f in freqOpts" :key="f.value" :value="f.value">{{ f.label }}</option></select></td>
                    <td><input v-model="ind.is_disclosed" type="checkbox" class="h-4 w-4 rounded text-blue" :aria-label="$t('entrepreneur.form.impact.disclosed')" /></td>
                    <td><AppButton variant="ghost" size="sm" icon :aria-label="$t('entrepreneur.form.impact.removeRow')" @click="removeIndicator(i)">×</AppButton></td>
                  </tr>
                  <tr v-if="!form.indicators?.length"><td colspan="9" class="text-center text-muted py-6">{{ $t('entrepreneur.form.impact.empty') }}</td></tr>
                </tbody>
              </table>
            </div>
            <AppButton variant="outline" size="sm" @click="addIndicator">+ {{ $t('entrepreneur.form.impact.addRow') }}</AppButton>
          </template>
          <!-- FINANCIALS -->
          <template v-else-if="stepKey === 'financials'">
            <div class="grid sm:grid-cols-2 gap-4">
              <FormField v-model="form.funding_amount" name="funding_amount" type="number" :label="$t('entrepreneur.form.financials.fundingAmount')" :help="$t('entrepreneur.form.financials.fundingHelp')" :min="0" :step="1000" required />
              <FormField v-model="form.last_year_revenue_band_id" name="last_year_revenue_band_id" type="select" :label="$t('entrepreneur.form.financials.lastYearRevenue')" :options="rangeOpts" />
            </div>
            <div>
              <p class="label">{{ $t('entrepreneur.form.financials.useOfFunds') }} <span class="text-xs font-normal num" :class="useTotal === 100 ? 'text-success' : 'text-warning'">({{ number(useTotal) }}%)</span></p>
              <div class="space-y-2">
                <div v-for="(u, i) in (form.use_of_funds as { item: string; percent: number }[])" :key="i" class="flex gap-2">
                  <input v-model="u.item" class="input flex-1" :placeholder="$t('entrepreneur.form.financials.useItem')" :aria-label="$t('entrepreneur.form.financials.useItem')" />
                  <input v-model.number="u.percent" type="number" min="0" max="100" class="input w-24 num" :aria-label="$t('entrepreneur.form.financials.usePercent')" />
                  <AppButton variant="ghost" size="sm" icon :aria-label="$t('common.delete')" @click="form.use_of_funds.splice(i, 1)">×</AppButton>
                </div>
              </div>
              <p v-if="useTotal !== 100" class="help text-warning">{{ $t('validation.percentTotal') }}</p>
              <AppButton variant="outline" size="sm" class="mt-2" @click="form.use_of_funds.push({ item: '', percent: 0 })">+ {{ $t('entrepreneur.form.financials.addUse') }}</AppButton>
            </div>
            <FormField v-model="form.prior_funding" name="prior_funding" type="checkbox" :label="$t('entrepreneur.form.financials.priorFunding')" />
            <FormField v-if="form.prior_funding" v-model="form.prior_funding_desc" name="prior_funding_desc" type="textarea" :label="$t('entrepreneur.form.financials.priorFundingDesc')" :rows="2" />
          </template>
          <!-- DOCUMENTS -->
          <template v-else-if="stepKey === 'documents'">
            <div><h3 class="font-semibold">{{ $t('entrepreneur.form.documents.title') }}</h3><p class="text-sm text-muted">{{ $t('entrepreneur.form.documents.help', { size: maxMb }) }}</p></div>
            <div class="grid sm:grid-cols-[220px_1fr] gap-4 items-start">
              <FormField v-model="uploadKind" name="kind" type="select" :label="$t('entrepreneur.form.documents.kind')" :options="kindOpts" />
              <FileUpload :label="$t('entrepreneur.form.documents.file')" :max-mb="maxMb" :disabled="uploading || locked" @file="upload" @error="toast.error($event)" />
            </div>
            <div class="card overflow-hidden"><table class="table-base">
              <thead><tr><th>{{ $t('entrepreneur.form.documents.kind') }}</th><th>{{ $t('common.name') }}</th><th>{{ $t('common.status') }}</th><th>{{ $t('common.notes') }}</th></tr></thead>
              <tbody>
                <tr v-for="d in (opp.documents as DocumentResource[])" :key="d.uuid"><td>{{ label('documentKind', d.kind) }}</td><td class="font-medium">{{ d.title }}<span class="block text-xs text-muted num">v{{ d.version }} · {{ date(d.created_at) }}<span v-if="d.scan_result === 'pending'"> · {{ $t('entrepreneur.form.documents.scanPending') }}</span></span></td><td><AppBadge kind="document" :value="d.status" /></td><td class="text-xs text-muted">{{ d.reviewer_note ?? '—' }}</td></tr>
                <tr v-if="!opp.documents.length"><td colspan="4" class="text-center text-muted py-6">{{ $t('common.noData') }}</td></tr>
              </tbody></table></div>
          </template>
          <!-- DISCLOSURES -->
          <template v-else-if="stepKey === 'disclosures'">
            <FormField v-model="form.legal_disputes" name="legal_disputes" type="checkbox" :label="$t('entrepreneur.form.disclosures.legalDisputes')" />
            <FormField v-if="form.legal_disputes" v-model="form.legal_disputes_desc" name="legal_disputes_desc" type="textarea" :label="$t('entrepreneur.form.disclosures.legalDisputesDesc')" :rows="2" />
            <FormField v-model="form.license_required" name="license_required" type="checkbox" :label="$t('entrepreneur.form.disclosures.licenseRequired')" />
            <FormField v-if="form.license_required" v-model="form.license_status" name="license_status" :label="$t('entrepreneur.form.disclosures.licenseStatus')" />
            <FormField v-model="form.operational_risks" name="operational_risks" type="textarea" :label="$t('entrepreneur.form.disclosures.operationalRisks')" :rows="3" />
            <FormField v-model="form.council_relationship" name="council_relationship" type="checkbox" :label="$t('entrepreneur.form.disclosures.councilRelationship')" />
            <FormField v-if="form.council_relationship" v-model="form.council_relationship_desc" name="council_relationship_desc" :label="$t('entrepreneur.form.disclosures.councilRelationshipDesc')" />
            <fieldset class="rounded-xl border border-border p-4 space-y-3">
              <legend class="px-1 text-sm font-medium">{{ $t('auth.register.declarations') }}</legend>
              <FormField v-model="form.data_accuracy_declared" name="data_accuracy" type="checkbox" :label="$t('entrepreneur.form.disclosures.dataAccuracy')" />
              <FormField v-model="form.teaser_publication_consent" name="teaser_consent" type="checkbox" :label="$t('entrepreneur.form.disclosures.teaserConsent')" />
              <p class="text-xs text-muted">{{ $t('entrepreneur.form.disclosures.fullFileNote') }}</p>
            </fieldset>
          </template>
        </fieldset>
        <div class="flex items-center justify-between gap-3 pt-5 mt-5 border-t border-border">
          <AppButton v-if="step > 0" variant="ghost" @click="prev">{{ $t('common.previous') }}</AppButton><span v-else />
          <div class="flex gap-2">
            <AppButton variant="secondary" :loading="saving" :disabled="locked" @click="save()">{{ $t('entrepreneur.form.saveDraft') }}</AppButton>
            <AppButton v-if="step < allSteps.length - 1" @click="next">{{ $t('common.next') }}</AppButton>
            <AppButton v-else-if="['draft', 'needs_completion'].includes(opp.status)" :loading="submitting" :disabled="!checklist?.ready" @click="submit">{{ $t('entrepreneur.form.submit') }}</AppButton>
          </div>
        </div>
      </div>
    </div>
    <!-- checklist side -->
    <aside class="lg:sticky lg:top-6 space-y-4">
      <div class="card card-pad">
        <div class="flex items-center justify-between gap-3"><h3 class="font-semibold">{{ $t('entrepreneur.form.checklistTitle') }}</h3><CompletionRing :percent="checklist?.completion_percent ?? 0" :size="56" :stroke="6" /></div>
        <p class="mt-2 text-sm" :class="checklist?.ready ? 'text-success' : 'text-warning'">{{ checklist?.ready ? $t('entrepreneur.form.checklistReady') : $t('entrepreneur.form.checklistMissing', { n: missingBySection.length }) }}</p>
        <ul class="mt-3 space-y-1.5 text-sm max-h-80 overflow-y-auto">
          <li v-for="it in checklist?.items ?? []" :key="it.key" class="flex items-start gap-2">
            <svg v-if="it.done" class="h-4 w-4 text-success shrink-0 mt-0.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
            <span v-else class="h-4 w-4 rounded-full border-2 border-warning shrink-0 mt-0.5" aria-hidden="true" />
            <button type="button" class="text-start hover:text-blue kbd-focus rounded" :class="it.done ? 'text-muted' : ''" @click="step = Math.max(0, allSteps.findIndex((s) => s.key === it.section))">{{ it.label }}</button>
          </li>
        </ul>
        <AppButton v-if="['draft', 'needs_completion'].includes(opp.status)" block class="mt-4" :loading="submitting" :disabled="!checklist?.ready" @click="submit">{{ $t('entrepreneur.form.submit') }}</AppButton>
        <AppButton block variant="secondary" size="sm" class="mt-2" :to="`/app/entrepreneur/opportunities/${uuid}/preview`">{{ $t('entrepreneur.dashboard.fullPreview') }}</AppButton>
      </div>
      <Disclaimer variant="box" />
    </aside>
  </div>
</template>

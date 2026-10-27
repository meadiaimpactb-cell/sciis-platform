<script setup lang="ts">
/**
 * Investor profile — editable sections (ACC-02, PRV-03). Accredited declaration intentionally not shown (doc §7.5).
 * The API is sectioned (basics/interests/experience/declarations with taxonomy term objects); the forms are flat —
 * `useInvestorProfile()` converts in both directions. One validated form per section (UpdateProfileRequest limits,
 * plus the same minimums as the registration wizard); a 422 on `basics.city` etc. lands under the field.
 * Security (2FA) lives on /app/security.
 * INV-01 — membership file: CV (individual) or CR + authorization letter + impact / ESG policy (institution), uploaded
 * through POST /investor/profile/documents (secure document pipeline); impact policy text for institutions;
 * data-accuracy declaration date. INV-02 — "آخر تحديث" from the API's `updated_at`.
 */
import { z } from 'zod'
import type { InvestorProfile, InvestorProfileApi } from '~/types/api'
import { INVESTMENT_TYPES, INVESTOR_DOCUMENT_KINDS, PROJECT_STAGES, RISK_APPETITES } from '~/types/enums'
import { RULES, atLeastOne, optionalNumber, optionalText, optionalUrl, parseApiError, requiredSelect, requiredString } from '~/utils/validation'
import { confirmDialog, toastApiError, toastError, toastSuccess } from '~/utils/notify'
definePageMeta({ layout: 'portal', middleware: ['investor'] })
const { t } = useI18n()
useHead({ title: t('investor.profile.metaTitle') })
const api = useApi()
const auth = useAuthStore()
const tax = useTaxonomyStore()
await tax.load().catch(() => {})
const { term, label } = useEnumLabel()
const { dateTime } = useFormat()
const { fromApi, toPayload } = useInvestorProfile()
const isInstitutional = computed(() => auth.me?.type === 'investor_institutional')
type Section = 'basics' | 'interests' | 'experience' | 'declarations'
const profile = ref<InvestorProfile | null>(null)
const editing = ref<Section | null>(null)
const load = async () => { profile.value = fromApi((await api.get<{ data: InvestorProfileApi | InvestorProfile }>('/investor/profile')).data) }
onMounted(load)

// ---- one form per section (field keys are disjoint, so blur validation never crosses sections)
const contactValue = z.enum(['email', 'phone', 'whatsapp']).nullish()
const idValue = z.number().nullish()
const prefixed = (section: string, keys: string[]) => Object.fromEntries(keys.map((k) => [`${section}.${k}`, k]))
const basicsForm = useZodForm(
  z.object({ city: optionalText(RULES.cityMax), country: optionalText(RULES.investorCountryMax), job_title: optionalText(RULES.titleMax), employer: optionalText(RULES.titleMax), linkedin_url: optionalUrl(RULES.urlMax), preferred_contact: contactValue, how_heard_id: idValue }),
  { city: '', country: '', job_title: '', employer: '', linkedin_url: '', preferred_contact: null as 'email' | 'phone' | 'whatsapp' | null, how_heard_id: null as number | null },
  { aliases: prefixed('basics', ['city', 'country', 'job_title', 'employer', 'linkedin_url', 'preferred_contact', 'how_heard_id']) },
)
const interestsForm = useZodForm(
  z.object({ sectors: atLeastOne(z.number()), stages: atLeastOne(z.string()), investment_types: atLeastOne(z.string()), regions: z.array(z.number()), causes: z.array(z.number()), investment_range_id: requiredSelect('auth.register.investmentRange'), annual_investment_band_id: idValue, risk_appetite: requiredSelect('auth.register.riskAppetite') }),
  { sectors: [] as number[], stages: [] as string[], investment_types: [] as string[], regions: [] as number[], causes: [] as number[], investment_range_id: null as number | null, annual_investment_band_id: null as number | null, risk_appetite: null as string | null },
  { aliases: { 'interests.sector_ids': 'sectors', 'interests.stage_ids': 'stages', 'interests.investment_type_ids': 'investment_types', 'interests.region_ids': 'regions', 'interests.cause_ids': 'causes', ...prefixed('interests', ['investment_range_id', 'annual_investment_band_id', 'risk_appetite']) } },
)
const experienceForm = useZodForm(
  z.object({
    experience_years: optionalNumber({ min: 0, max: RULES.experienceYearsMax, int: true }),
    prior_investments_count: optionalNumber({ min: 0, max: RULES.priorInvestmentsMax, int: true }),
    experience_desc: requiredString('auth.register.experienceDesc', RULES.investorExperienceDescMin, RULES.descMax),
    prior_investments_desc: optionalText(RULES.descMax),
    has_social_investments: z.boolean(),
    social_investments_desc: optionalText(RULES.descMax),
    mentoring_available: z.boolean(),
    mentoring_areas: optionalText(RULES.investorMentoringMax),
    impact_policy: optionalText(RULES.descMax),
  }),
  { experience_years: null as number | null, prior_investments_count: null as number | null, experience_desc: '', prior_investments_desc: '', has_social_investments: false, social_investments_desc: '', mentoring_available: false, mentoring_areas: '', impact_policy: '' },
  { aliases: prefixed('experience', ['experience_years', 'prior_investments_count', 'experience_desc', 'prior_investments_desc', 'has_social_investments', 'social_investments_desc', 'mentoring_available', 'mentoring_areas', 'impact_policy']) },
)
const declarationsForm = useZodForm(
  z.object({ conflicts_disclosure: requiredString('auth.register.conflicts', RULES.investorConflictsMin, RULES.descMax), commit_meetings: z.boolean() }),
  { conflicts_disclosure: '', commit_meetings: false },
  { aliases: prefixed('declarations', ['conflicts_disclosure', 'commit_meetings']) },
)
const forms = { basics: basicsForm, interests: interestsForm, experience: experienceForm, declarations: declarationsForm }
const bf = basicsForm.values
const inf = interestsForm.values
const ef = experienceForm.values
const df = declarationsForm.values

/** Copy the section's current values into its form (null text → '' so the inputs stay controlled). */
const start = (section: Section) => {
  const p = profile.value as unknown as Record<string, unknown> | null
  if (!p) return
  const f = forms[section]
  const blank = f.values as Record<string, unknown>
  const next = Object.fromEntries(Object.keys(blank).map((k) => {
    const v = p[k]
    return [k, Array.isArray(v) ? [...v] : v ?? (typeof blank[k] === 'string' ? '' : typeof blank[k] === 'boolean' ? false : null)]
  }))
  f.reset(next as never)
  editing.value = section
}
const save = (section: Section) => forms[section].submit(async (v) => {
  const body = toPayload(v as unknown as Partial<InvestorProfile>, [section])
  profile.value = fromApi((await api.put<{ data: InvestorProfileApi | InvestorProfile }>('/investor/profile', body)).data)
  editing.value = null
  toastSuccess(t('notify.updated', { item: t(`investor.profile.sections.${section}`) }))
  await auth.fetchMe(true)
})

const sectorOpts = computed(() => tax.list('sectors').map((x) => ({ value: x.id, label: term(x) })))
const regionOpts = computed(() => tax.list('regions').map((x) => ({ value: x.id, label: term(x) })))
const causeOpts = computed(() => tax.list('beneficiary_groups').map((x) => ({ value: x.id, label: term(x) })))
const rangeOpts = computed(() => tax.list('investment_ranges').map((x) => ({ value: String(x.id), label: term(x) })))
// The API has a dedicated `annual_investment_bands` group; the mock reuses the ranges.
const bandOpts = computed(() => { const l = tax.list('annual_investment_bands' as never); return (l.length ? l : tax.list('investment_ranges')).map((x) => ({ value: String(x.id), label: term(x) })) })
const howHeardOpts = computed(() => tax.list('how_heard').map((x) => ({ value: String(x.id), label: term(x) })))
const stageOpts = PROJECT_STAGES.map((s) => ({ value: s, label: label('stage', s) }))
const typeOpts = INVESTMENT_TYPES.map((s) => ({ value: s, label: label('investmentType', s) }))
const riskOpts = RISK_APPETITES.map((s) => ({ value: s, label: label('riskAppetite', s) }))
const contactOpts = [{ value: 'email', label: t('common.email') }, { value: 'phone', label: t('common.phone') }, { value: 'whatsapp', label: t('contact.whatsapp') }]
/** select ↔ numeric id */
const idStr = (v: unknown) => (v === null || v === undefined ? '' : String(v))
const toId = (v: unknown) => Number(v) || null

// ---- INV-01 membership file → POST /investor/profile/documents (size / type limits, scan, encryption, versions)
const docKinds = computed<readonly string[]>(() => (isInstitutional.value ? INVESTOR_DOCUMENT_KINDS.institutional : INVESTOR_DOCUMENT_KINDS.individual))
const docKind = ref<string>(INVESTOR_DOCUMENT_KINDS.individual[0])
watch(docKinds, (kinds) => { if (!kinds.includes(docKind.value)) docKind.value = kinds[0] }, { immediate: true })
const docKindOpts = computed(() => docKinds.value.map((k) => ({ value: k, label: label('documentKind', k) })))
const maxMb = computed(() => tax.settings?.documents?.max_size_mb ?? 25)
const docs = computed(() => profile.value?.documents ?? [])
const missingDocs = computed(() => docKinds.value.filter((k) => !docs.value.some((d) => d.kind === k)))
const uploading = ref(false)
const uploadDoc = async (f: File) => {
  uploading.value = true
  const kind = docKind.value
  try {
    const body = new FormData()
    body.append('kind', kind)
    body.append('file', f)
    await api.post('/investor/profile/documents', body)
    toastSuccess(t('notify.uploaded', { item: `${label('documentKind', kind)} «${f.name}»` }))
    await load()
  } catch (e) {
    const first = Object.values(parseApiError(e).errors)[0]
    if (first) toastError(first)
    else toastApiError(e)
  } finally { uploading.value = false }
}

/** PRV-03 — POST /me/data-requests → { data: { id, type, status }, message }. Erasure is irreversible → confirm first. */
const dataBusy = ref<'access' | 'erase' | null>(null)
const dataRequest = async (type: 'access' | 'erase') => {
  if (type === 'erase' && !(await confirmDialog(t('investor.profile.eraseTitle'), t('investor.profile.eraseText', { name: auth.me?.name ?? '' }), t('investor.profile.eraseConfirm')))) return
  dataBusy.value = type
  try {
    const r = await api.post<{ data: { id: number; type: string; status: string }; message?: string }>('/me/data-requests', { type })
    toastSuccess(t('investor.profile.dataRequestDone', { id: r.data.id }))
  } catch (e) { toastApiError(e) } finally { dataBusy.value = null }
}
</script>

<template>
  <div>
    <PageHeader :title="$t('investor.profile.title')" :sub="$t('investor.profile.sub')">
      <template #actions>
        <div v-if="profile" class="flex items-center gap-4">
          <p v-if="profile.updated_at" class="text-xs text-muted text-end">{{ $t('investor.profile.lastUpdated') }}<span class="block text-sm font-medium text-ink num">{{ dateTime(profile.updated_at) }}</span></p>
          <CompletionRing :percent="profile.completion_percent" :size="64" :stroke="6" />
        </div>
      </template>
    </PageHeader>
    <div v-if="!profile" class="text-muted">{{ $t('common.loading') }}</div>
    <div v-else class="grid lg:grid-cols-2 gap-6">
      <!-- basics -->
      <SectionCard :title="$t('investor.profile.sections.basics')">
        <template #actions><AppButton v-if="editing !== 'basics'" size="sm" variant="secondary" @click="start('basics')">{{ $t('common.edit') }}</AppButton></template>
        <form v-if="editing === 'basics'" class="space-y-4" novalidate @submit.prevent="save('basics')">
          <div class="grid sm:grid-cols-2 gap-4">
            <FormField v-model="bf.city" name="city" :label="$t('auth.register.city')" :error="basicsForm.errors.value.city" :maxlength="RULES.cityMax" />
            <FormField v-model="bf.country" name="country" :label="$t('auth.register.country')" :error="basicsForm.errors.value.country" :maxlength="RULES.investorCountryMax" />
            <FormField v-model="bf.job_title" name="job_title" :label="$t('auth.register.jobTitle')" :error="basicsForm.errors.value.job_title" :maxlength="RULES.titleMax" />
            <FormField v-model="bf.employer" name="employer" :label="$t('auth.register.employer')" :error="basicsForm.errors.value.employer" :maxlength="RULES.titleMax" />
            <FormField v-model="bf.linkedin_url" name="linkedin_url" type="url" :label="$t('auth.register.linkedin')" :error="basicsForm.errors.value.linkedin_url" ltr class="sm:col-span-2" />
            <FormField v-model="bf.preferred_contact" name="preferred_contact" type="select" :label="$t('auth.register.preferredContact')" :options="contactOpts" :error="basicsForm.errors.value.preferred_contact" />
            <FormField :model-value="idStr(bf.how_heard_id)" name="how_heard_id" type="select" :label="$t('auth.register.howHeard')" :options="howHeardOpts" :error="basicsForm.errors.value.how_heard_id" @update:model-value="bf.how_heard_id = toId($event)" />
          </div>
          <FormAlert :message="basicsForm.formError.value" /><div class="flex gap-2 justify-end"><AppButton variant="ghost" @click="editing = null">{{ $t('common.cancel') }}</AppButton><AppButton type="submit" :loading="basicsForm.submitting.value">{{ $t('common.save') }}</AppButton></div>
        </form>
        <KeyValue v-else :items="[{ label: $t('common.name'), value: auth.me?.name }, { label: $t('common.email'), value: auth.me?.email, ltr: true }, { label: $t('common.phone'), value: auth.me?.phone, ltr: true, num: true }, { label: $t('auth.register.city'), value: profile.city }, { label: $t('auth.register.country'), value: profile.country }, { label: $t('auth.register.jobTitle'), value: profile.job_title }, { label: $t('auth.register.employer'), value: profile.employer }, { label: $t('auth.register.preferredContact'), value: contactOpts.find((o) => o.value === profile?.preferred_contact)?.label }]" />
      </SectionCard>
      <!-- interests -->
      <SectionCard :title="$t('investor.profile.sections.interests')">
        <template #actions><AppButton v-if="editing !== 'interests'" size="sm" variant="secondary" @click="start('interests')">{{ $t('common.edit') }}</AppButton></template>
        <form v-if="editing === 'interests'" class="space-y-4" novalidate @submit.prevent="save('interests')">
          <FormField v-model="inf.sectors" name="sectors" type="chips" :label="$t('auth.register.sectors')" :options="sectorOpts" :error="interestsForm.errors.value.sectors" required />
          <FormField v-model="inf.stages" name="stages" type="chips" :label="$t('auth.register.stages')" :options="stageOpts" :error="interestsForm.errors.value.stages" required />
          <FormField v-model="inf.investment_types" name="investment_types" type="chips" :label="$t('auth.register.investmentTypes')" :options="typeOpts" :error="interestsForm.errors.value.investment_types" required />
          <FormField v-model="inf.regions" name="regions" type="chips" :label="$t('auth.register.regions')" :options="regionOpts" :error="interestsForm.errors.value.regions" />
          <FormField v-model="inf.causes" name="causes" type="chips" :label="$t('auth.register.causes')" :options="causeOpts" :error="interestsForm.errors.value.causes" />
          <div class="grid sm:grid-cols-2 gap-4">
            <FormField :model-value="idStr(inf.investment_range_id)" name="investment_range_id" type="select" :label="$t('auth.register.investmentRange')" :options="rangeOpts" :help="$t('auth.register.investmentRangeHelp')" :error="interestsForm.errors.value.investment_range_id" required @update:model-value="inf.investment_range_id = toId($event)" />
            <FormField :model-value="idStr(inf.annual_investment_band_id)" name="annual_investment_band_id" type="select" :label="$t('auth.register.annualBand')" :options="bandOpts" :help="$t('auth.register.annualBandHelp')" :error="interestsForm.errors.value.annual_investment_band_id" @update:model-value="inf.annual_investment_band_id = toId($event)" />
            <FormField v-model="inf.risk_appetite" name="risk_appetite" type="select" :label="$t('auth.register.riskAppetite')" :options="riskOpts" :error="interestsForm.errors.value.risk_appetite" required />
          </div>
          <FormAlert :message="interestsForm.formError.value" /><div class="flex gap-2 justify-end"><AppButton variant="ghost" @click="editing = null">{{ $t('common.cancel') }}</AppButton><AppButton type="submit" :loading="interestsForm.submitting.value">{{ $t('common.save') }}</AppButton></div>
        </form>
        <div v-else class="space-y-3 text-sm">
          <div><p class="text-xs text-muted">{{ $t('auth.register.sectors') }}</p><ul class="mt-1 flex flex-wrap gap-1.5"><li v-for="id in profile.sectors" :key="id" class="chip text-xs !py-0.5">{{ term(tax.byId('sectors', id)) }}</li></ul></div>
          <div><p class="text-xs text-muted">{{ $t('auth.register.stages') }}</p><ul class="mt-1 flex flex-wrap gap-1.5"><li v-for="s in profile.stages" :key="s" class="chip text-xs !py-0.5">{{ label('stage', s) }}</li></ul></div>
          <div><p class="text-xs text-muted">{{ $t('auth.register.investmentTypes') }}</p><ul class="mt-1 flex flex-wrap gap-1.5"><li v-for="s in profile.investment_types" :key="s" class="chip text-xs !py-0.5">{{ label('investmentType', s) }}</li></ul></div>
          <div><p class="text-xs text-muted">{{ $t('auth.register.regions') }}</p><ul class="mt-1 flex flex-wrap gap-1.5"><li v-for="id in profile.regions" :key="id" class="chip text-xs !py-0.5">{{ term(tax.byId('regions', id)) }}</li></ul></div>
          <KeyValue :items="[{ label: $t('auth.register.investmentRange'), value: term(tax.byId('investment_ranges', profile.investment_range_id)) }, { label: $t('auth.register.riskAppetite'), value: label('riskAppetite', profile.risk_appetite) }]" />
        </div>
      </SectionCard>
      <!-- experience -->
      <SectionCard :title="$t('investor.profile.sections.experience')">
        <template #actions><AppButton v-if="editing !== 'experience'" size="sm" variant="secondary" @click="start('experience')">{{ $t('common.edit') }}</AppButton></template>
        <form v-if="editing === 'experience'" class="space-y-4" novalidate @submit.prevent="save('experience')">
          <div class="grid sm:grid-cols-2 gap-4">
            <FormField v-model="ef.experience_years" name="experience_years" type="number" :label="$t('auth.register.experienceYears')" :error="experienceForm.errors.value.experience_years" :min="0" :max="RULES.experienceYearsMax" />
            <FormField v-model="ef.prior_investments_count" name="prior_investments_count" type="number" :label="$t('auth.register.priorInvestmentsCount')" :error="experienceForm.errors.value.prior_investments_count" :min="0" :max="RULES.priorInvestmentsMax" />
          </div>
          <FormField v-model="ef.experience_desc" name="experience_desc" type="textarea" :label="$t('auth.register.experienceDesc')" :error="experienceForm.errors.value.experience_desc" :rows="3" :maxlength="RULES.descMax" required />
          <FormField v-model="ef.prior_investments_desc" name="prior_investments_desc" type="textarea" :label="$t('auth.register.priorInvestmentsDesc')" :error="experienceForm.errors.value.prior_investments_desc" :rows="2" :maxlength="RULES.descMax" />
          <FormField v-model="ef.has_social_investments" name="has_social_investments" type="checkbox" :label="$t('auth.register.hasSocialInvestments')" />
          <FormField v-if="ef.has_social_investments" v-model="ef.social_investments_desc" name="social_investments_desc" type="textarea" :label="$t('auth.register.socialInvestmentsDesc')" :error="experienceForm.errors.value.social_investments_desc" :rows="2" :maxlength="RULES.descMax" />
          <FormField v-model="ef.mentoring_available" name="mentoring_available" type="checkbox" :label="$t('auth.register.mentoring')" />
          <FormField v-if="ef.mentoring_available" v-model="ef.mentoring_areas" name="mentoring_areas" :label="$t('auth.register.mentoringAreas')" :error="experienceForm.errors.value.mentoring_areas" :maxlength="RULES.investorMentoringMax" />
          <FormField v-if="isInstitutional" v-model="ef.impact_policy" name="impact_policy" type="textarea" :label="$t('investor.profile.impactPolicy')" :help="$t('investor.profile.impactPolicyHelp')" :error="experienceForm.errors.value.impact_policy" :rows="3" :maxlength="RULES.descMax" />
          <FormAlert :message="experienceForm.formError.value" /><div class="flex gap-2 justify-end"><AppButton variant="ghost" @click="editing = null">{{ $t('common.cancel') }}</AppButton><AppButton type="submit" :loading="experienceForm.submitting.value">{{ $t('common.save') }}</AppButton></div>
        </form>
        <KeyValue v-else :cols="1" :items="[{ label: $t('auth.register.experienceYears'), value: profile.experience_years, num: true }, { label: $t('auth.register.experienceDesc'), value: profile.experience_desc }, { label: $t('auth.register.priorInvestmentsCount'), value: profile.prior_investments_count, num: true }, { label: $t('auth.register.hasSocialInvestments'), value: profile.has_social_investments ? `${$t('common.yes')} — ${profile.social_investments_desc ?? ''}` : $t('common.no') }, { label: $t('auth.register.mentoring'), value: profile.mentoring_available ? `${$t('common.yes')} — ${profile.mentoring_areas ?? ''}` : $t('common.no') }, ...(isInstitutional ? [{ label: $t('investor.profile.impactPolicy'), value: profile.impact_policy }] : [])]" />
      </SectionCard>
      <!-- declarations -->
      <SectionCard :title="$t('investor.profile.sections.declarations')">
        <template #actions><AppButton v-if="editing !== 'declarations'" size="sm" variant="secondary" @click="start('declarations')">{{ $t('common.edit') }}</AppButton></template>
        <form v-if="editing === 'declarations'" class="space-y-4" novalidate @submit.prevent="save('declarations')">
          <FormField v-model="df.conflicts_disclosure" name="conflicts_disclosure" type="textarea" :label="$t('auth.register.conflicts')" :help="$t('auth.register.conflictsHelp')" :error="declarationsForm.errors.value.conflicts_disclosure" :rows="3" :maxlength="RULES.descMax" required />
          <FormField v-model="df.commit_meetings" name="commit_meetings" type="checkbox" :label="$t('auth.register.commitMeetings')" />
          <!-- accredited_declaration intentionally not rendered — pending legal review (doc §7.5) -->
          <FormAlert :message="declarationsForm.formError.value" /><div class="flex gap-2 justify-end"><AppButton variant="ghost" @click="editing = null">{{ $t('common.cancel') }}</AppButton><AppButton type="submit" :loading="declarationsForm.submitting.value">{{ $t('common.save') }}</AppButton></div>
        </form>
        <KeyValue v-else :cols="1" :items="[{ label: $t('auth.register.conflicts'), value: profile.conflicts_disclosure }, { label: $t('auth.register.commitMeetings'), value: profile.commit_meetings ? $t('common.yes') : $t('common.no') }, { label: $t('investor.profile.dataAccuracy'), value: profile.data_accuracy_declared_at ? $t('investor.profile.declaredOn', { date: dateTime(profile.data_accuracy_declared_at) }) : null }]" />
      </SectionCard>
      <!-- FRM-01 — admin-defined additional questions (hidden while none are published) -->
      <ExtraQuestionsCard form-key="investor_profile" :data="profile.extra_answers" endpoint="/investor/profile" class="lg:col-span-2" @saved="load" />
      <!-- INV-01 membership file (CV / CR / authorization letter / impact policy) -->
      <SectionCard :title="$t('investor.profile.documents.title')" :sub="$t(isInstitutional ? 'investor.profile.documents.subInstitution' : 'investor.profile.documents.subIndividual')" class="lg:col-span-2">
        <div class="grid sm:grid-cols-[240px_1fr] gap-4 items-start">
          <FormField v-model="docKind" name="doc_kind" type="select" :label="$t('entrepreneur.form.documents.kind')" :options="docKindOpts" />
          <FileUpload :label="$t('entrepreneur.form.documents.file')" :max-mb="maxMb" :disabled="uploading" @file="uploadDoc" @error="toastError($event)" />
        </div>
        <p class="mt-2 text-xs text-muted">{{ $t('investor.profile.documents.privacy') }}</p>
        <ul v-if="missingDocs.length" class="mt-3 flex flex-wrap items-center gap-1.5 text-xs" :aria-label="$t('investor.profile.documents.missing')">
          <li class="text-muted">{{ $t('investor.profile.documents.missing') }}:</li>
          <li v-for="k in missingDocs" :key="k" class="rounded-full border border-warning/30 bg-warning/10 text-warning px-2.5 py-0.5">{{ label('documentKind', k) }}</li>
        </ul>
        <div class="mt-4 card overflow-hidden">
          <DocumentVersions :documents="docs" download-base="/me/documents" show-notes :caption="$t('investor.profile.documents.title')" />
        </div>
      </SectionCard>
      <!-- security → dedicated page (ACC-05) -->
      <SectionCard :title="$t('investor.profile.sections.security')">
        <div class="flex items-center justify-between gap-3">
          <div><p class="font-medium text-ink">{{ $t('investor.profile.twoFactor') }}</p><AppBadge kind="generic" :value="auth.me?.two_factor_enabled ? 'active' : 'suspended'" :label="auth.me?.two_factor_enabled ? $t('security.on') : $t('security.off')" class="mt-1" /></div>
          <AppButton size="sm" variant="secondary" to="/app/security">{{ $t('investor.profile.securityLink') }}</AppButton>
        </div>
      </SectionCard>
      <!-- data requests -->
      <SectionCard :title="$t('investor.profile.dataRequest')" :sub="$t('investor.profile.dataRequestText')">
        <div class="flex flex-wrap gap-2"><AppButton size="sm" variant="secondary" :loading="dataBusy === 'access'" :disabled="!!dataBusy" @click="dataRequest('access')">{{ $t('investor.profile.requestAccess') }}</AppButton><AppButton size="sm" variant="danger" :loading="dataBusy === 'erase'" :disabled="!!dataBusy" @click="dataRequest('erase')">{{ $t('investor.profile.requestErase') }}</AppButton></div>
      </SectionCard>
    </div>
  </div>
</template>

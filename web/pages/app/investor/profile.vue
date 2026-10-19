<script setup lang="ts">
/** Investor profile — editable sections (ACC-02, PRV-03). Accredited declaration intentionally not shown (doc §7.5). */
import type { InvestorProfile } from '~/types/api'
import { INVESTMENT_TYPES, PROJECT_STAGES, RISK_APPETITES } from '~/types/enums'
definePageMeta({ layout: 'portal', middleware: ['investor'] })
const { t } = useI18n()
useHead({ title: t('investor.profile.metaTitle') })
const api = useApi()
const auth = useAuthStore()
const tax = useTaxonomyStore()
await tax.load().catch(() => {})
const toast = useToast()
const { term, label } = useEnumLabel()
const profile = ref<InvestorProfile | null>(null)
const editing = ref<string | null>(null)
const busy = ref(false)
const draft = reactive<Record<string, unknown>>({})
const load = async () => { profile.value = (await api.get<{ data: InvestorProfile }>('/investor/profile')).data }
onMounted(load)
const start = (section: string) => { Object.assign(draft, JSON.parse(JSON.stringify(profile.value))); editing.value = section }
const save = async () => {
  busy.value = true
  try {
    const { completion_percent: _c, missing_items: _m, ...body } = draft as unknown as InvestorProfile
    profile.value = (await api.put<{ data: InvestorProfile }>('/investor/profile', body)).data
    editing.value = null
    toast.success(t('investor.profile.saved'))
    await auth.fetchMe(true)
  } catch (e) { toast.error((e as Error).message) } finally { busy.value = false }
}
const sectorOpts = computed(() => tax.list('sectors').map((x) => ({ value: x.id, label: term(x) })))
const regionOpts = computed(() => tax.list('regions').map((x) => ({ value: x.id, label: term(x) })))
const causeOpts = computed(() => tax.list('beneficiary_groups').map((x) => ({ value: x.id, label: term(x) })))
const rangeOpts = computed(() => tax.list('investment_ranges').map((x) => ({ value: String(x.id), label: term(x) })))
const stageOpts = PROJECT_STAGES.map((s) => ({ value: s, label: label('stage', s) }))
const typeOpts = INVESTMENT_TYPES.map((s) => ({ value: s, label: label('investmentType', s) }))
const riskOpts = RISK_APPETITES.map((s) => ({ value: s, label: label('riskAppetite', s) }))
const twoFa = async () => {
  if (auth.me?.two_factor_enabled) { await api.del('/auth/2fa'); } else { await api.post('/auth/2fa/enable'); await api.post('/auth/2fa/confirm', { code: '123456' }) }
  await auth.fetchMe(true); toast.success(t('common.success'))
}
const dataRequest = async (type: 'access' | 'erase') => { const r = await api.post<{ reference_no: string }>('/me/data-requests', { type }); toast.success(`${t('common.success')} — ${r.reference_no}`) }
</script>

<template>
  <div>
    <PageHeader :title="$t('investor.profile.title')" :sub="$t('investor.profile.sub')">
      <template #actions><CompletionRing v-if="profile" :percent="profile.completion_percent" :size="64" :stroke="6" /></template>
    </PageHeader>
    <div v-if="!profile" class="text-muted">{{ $t('common.loading') }}</div>
    <div v-else class="grid lg:grid-cols-2 gap-6">
      <!-- basics -->
      <SectionCard :title="$t('investor.profile.sections.basics')">
        <template #actions><AppButton v-if="editing !== 'basics'" size="sm" variant="secondary" @click="start('basics')">{{ $t('common.edit') }}</AppButton></template>
        <div v-if="editing === 'basics'" class="space-y-4">
          <div class="grid sm:grid-cols-2 gap-4">
            <FormField v-model="draft.city" name="city" :label="$t('auth.register.city')" />
            <FormField v-model="draft.country" name="country" :label="$t('auth.register.country')" />
            <FormField v-model="draft.job_title" name="job_title" :label="$t('auth.register.jobTitle')" />
            <FormField v-model="draft.employer" name="employer" :label="$t('auth.register.employer')" />
            <FormField v-model="draft.linkedin_url" name="linkedin_url" type="url" :label="$t('auth.register.linkedin')" ltr class="sm:col-span-2" />
          </div>
          <div class="flex gap-2 justify-end"><AppButton variant="ghost" @click="editing = null">{{ $t('common.cancel') }}</AppButton><AppButton :loading="busy" @click="save">{{ $t('common.save') }}</AppButton></div>
        </div>
        <KeyValue v-else :items="[{ label: $t('common.name'), value: auth.me?.name }, { label: $t('common.email'), value: auth.me?.email, ltr: true }, { label: $t('common.phone'), value: auth.me?.phone, ltr: true, num: true }, { label: $t('auth.register.city'), value: profile.city }, { label: $t('auth.register.jobTitle'), value: profile.job_title }, { label: $t('auth.register.employer'), value: profile.employer }]" />
      </SectionCard>
      <!-- interests -->
      <SectionCard :title="$t('investor.profile.sections.interests')">
        <template #actions><AppButton v-if="editing !== 'interests'" size="sm" variant="secondary" @click="start('interests')">{{ $t('common.edit') }}</AppButton></template>
        <div v-if="editing === 'interests'" class="space-y-4">
          <FormField v-model="draft.sectors" name="sectors" type="chips" :label="$t('auth.register.sectors')" :options="sectorOpts" />
          <FormField v-model="draft.stages" name="stages" type="chips" :label="$t('auth.register.stages')" :options="stageOpts" />
          <FormField v-model="draft.investment_types" name="investment_types" type="chips" :label="$t('auth.register.investmentTypes')" :options="typeOpts" />
          <FormField v-model="draft.regions" name="regions" type="chips" :label="$t('auth.register.regions')" :options="regionOpts" />
          <FormField v-model="draft.causes" name="causes" type="chips" :label="$t('auth.register.causes')" :options="causeOpts" />
          <div class="grid sm:grid-cols-2 gap-4">
            <FormField :model-value="String(draft.investment_range_id ?? '')" name="investment_range_id" type="select" :label="$t('auth.register.investmentRange')" :options="rangeOpts" :help="$t('auth.register.investmentRangeHelp')" @update:model-value="draft.investment_range_id = Number($event) || null" />
            <FormField v-model="draft.risk_appetite" name="risk_appetite" type="select" :label="$t('auth.register.riskAppetite')" :options="riskOpts" />
          </div>
          <div class="flex gap-2 justify-end"><AppButton variant="ghost" @click="editing = null">{{ $t('common.cancel') }}</AppButton><AppButton :loading="busy" @click="save">{{ $t('common.save') }}</AppButton></div>
        </div>
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
        <div v-if="editing === 'experience'" class="space-y-4">
          <div class="grid sm:grid-cols-2 gap-4">
            <FormField v-model="draft.experience_years" name="experience_years" type="number" :label="$t('auth.register.experienceYears')" />
            <FormField v-model="draft.prior_investments_count" name="prior_investments_count" type="number" :label="$t('auth.register.priorInvestmentsCount')" />
          </div>
          <FormField v-model="draft.experience_desc" name="experience_desc" type="textarea" :label="$t('auth.register.experienceDesc')" :rows="3" />
          <FormField v-model="draft.prior_investments_desc" name="prior_investments_desc" type="textarea" :label="$t('auth.register.priorInvestmentsDesc')" :rows="2" />
          <FormField v-model="draft.has_social_investments" name="has_social_investments" type="checkbox" :label="$t('auth.register.hasSocialInvestments')" />
          <FormField v-model="draft.social_investments_desc" name="social_investments_desc" type="textarea" :label="$t('auth.register.socialInvestmentsDesc')" :rows="2" />
          <FormField v-model="draft.mentoring_available" name="mentoring_available" type="checkbox" :label="$t('auth.register.mentoring')" />
          <FormField v-model="draft.mentoring_areas" name="mentoring_areas" :label="$t('auth.register.mentoringAreas')" />
          <div class="flex gap-2 justify-end"><AppButton variant="ghost" @click="editing = null">{{ $t('common.cancel') }}</AppButton><AppButton :loading="busy" @click="save">{{ $t('common.save') }}</AppButton></div>
        </div>
        <KeyValue v-else :cols="1" :items="[{ label: $t('auth.register.experienceYears'), value: profile.experience_years, num: true }, { label: $t('auth.register.experienceDesc'), value: profile.experience_desc }, { label: $t('auth.register.priorInvestmentsCount'), value: profile.prior_investments_count, num: true }, { label: $t('auth.register.hasSocialInvestments'), value: profile.has_social_investments ? `${$t('common.yes')} — ${profile.social_investments_desc ?? ''}` : $t('common.no') }, { label: $t('auth.register.mentoring'), value: profile.mentoring_available ? `${$t('common.yes')} — ${profile.mentoring_areas ?? ''}` : $t('common.no') }]" />
      </SectionCard>
      <!-- declarations -->
      <SectionCard :title="$t('investor.profile.sections.declarations')">
        <template #actions><AppButton v-if="editing !== 'declarations'" size="sm" variant="secondary" @click="start('declarations')">{{ $t('common.edit') }}</AppButton></template>
        <div v-if="editing === 'declarations'" class="space-y-4">
          <FormField v-model="draft.conflicts_disclosure" name="conflicts_disclosure" type="textarea" :label="$t('auth.register.conflicts')" :help="$t('auth.register.conflictsHelp')" :rows="3" />
          <FormField v-model="draft.commit_meetings" name="commit_meetings" type="checkbox" :label="$t('auth.register.commitMeetings')" />
          <!-- accredited_declaration intentionally not rendered — pending legal review (doc §7.5) -->
          <div class="flex gap-2 justify-end"><AppButton variant="ghost" @click="editing = null">{{ $t('common.cancel') }}</AppButton><AppButton :loading="busy" @click="save">{{ $t('common.save') }}</AppButton></div>
        </div>
        <KeyValue v-else :cols="1" :items="[{ label: $t('auth.register.conflicts'), value: profile.conflicts_disclosure }, { label: $t('auth.register.commitMeetings'), value: profile.commit_meetings ? $t('common.yes') : $t('common.no') }]" />
      </SectionCard>
      <!-- security -->
      <SectionCard :title="$t('investor.profile.sections.security')">
        <div class="flex items-center justify-between gap-3">
          <div><p class="font-medium">{{ $t('investor.profile.twoFactor') }}</p><AppBadge kind="generic" :value="auth.me?.two_factor_enabled ? 'active' : 'suspended'" :label="auth.me?.two_factor_enabled ? $t('common.yes') : $t('common.no')" class="mt-1" /></div>
          <AppButton size="sm" variant="secondary" @click="twoFa">{{ auth.me?.two_factor_enabled ? $t('investor.profile.disable2fa') : $t('investor.profile.enable2fa') }}</AppButton>
        </div>
      </SectionCard>
      <!-- data requests -->
      <SectionCard :title="$t('investor.profile.dataRequest')" :sub="$t('investor.profile.dataRequestText')">
        <div class="flex flex-wrap gap-2"><AppButton size="sm" variant="secondary" @click="dataRequest('access')">{{ $t('investor.profile.requestAccess') }}</AppButton><AppButton size="sm" variant="danger" @click="dataRequest('erase')">{{ $t('investor.profile.requestErase') }}</AppButton></div>
      </SectionCard>
    </div>
  </div>
</template>

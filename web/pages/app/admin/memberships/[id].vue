<script setup lang="ts">
import type { Membership } from '~/types/api'
import type { MembershipStatus } from '~/types/enums'
definePageMeta({ layout: 'portal', middleware: ['role'], roles: ['membership_officer'] })
const { t } = useI18n()
useHead({ title: t('admin.memberships.detailTitle') })
const api = useApi()
const route = useRoute()
const toast = useToast()
const tax = useTaxonomyStore()
await tax.load().catch(() => {})
const { label, term } = useEnumLabel()
const { date } = useFormat()
const id = route.params.id as string
const { data: m, loading, refresh } = useAsyncResource(() => api.get<{ data: Membership & { allowed_transitions: MembershipStatus[] } }>(`/admin/memberships/${id}`).then((r) => r.data))
const note = ref(''); const busy = ref<string | null>(null)
const transition = async (to: MembershipStatus) => { busy.value = to; try { await api.post(`/admin/memberships/${id}/transition`, { to, note: note.value || undefined }); toast.success(t('common.success')); note.value = ''; await refresh() } catch (e) { toast.error((e as Error).message) } finally { busy.value = null } }
const btn: Record<string, { label: string; variant: 'primary' | 'secondary' | 'danger' | 'ghost' }> = { approved: { label: t('admin.memberships.approve'), variant: 'primary' }, needs_completion: { label: t('admin.memberships.needsCompletion'), variant: 'secondary' }, rejected: { label: t('admin.memberships.reject'), variant: 'danger' }, suspended: { label: t('admin.memberships.suspend'), variant: 'danger' }, under_review: { label: label('membershipStatus', 'under_review'), variant: 'secondary' } }
</script>
<template>
  <div>
    <nav class="text-xs text-muted mb-3"><NuxtLink :to="$localePath('/app/admin/memberships')" class="hover:text-blue">{{ $t('admin.memberships.title') }}</NuxtLink> / #{{ id }}</nav>
    <div v-if="loading && !m" class="text-muted">{{ $t('common.loading') }}</div>
    <template v-else-if="m">
      <PageHeader :title="m.user?.name ?? ''" :sub="`${label('userType', m.user?.type)} · ${m.user?.organization?.name ?? ''}`"><template #actions><AppBadge kind="membership" :value="m.status" size="md" :soft="false" /></template></PageHeader>
      <div class="grid lg:grid-cols-[1fr_340px] gap-6 items-start">
        <div class="space-y-5">
          <SectionCard :title="$t('investor.profile.sections.basics')"><KeyValue :items="[{ label: $t('common.email'), value: m.user?.email, ltr: true }, { label: $t('common.phone'), value: m.user?.phone, ltr: true, num: true }, { label: $t('auth.register.city'), value: m.profile?.city }, { label: $t('auth.register.jobTitle'), value: m.profile?.job_title }, { label: $t('auth.register.employer'), value: m.profile?.employer }, { label: $t('admin.memberships.columns.submitted'), value: date(m.submitted_at) }]" /></SectionCard>
          <SectionCard :title="$t('investor.profile.sections.interests')">
            <div class="space-y-3 text-sm">
              <div><p class="text-xs text-muted">{{ $t('auth.register.sectors') }}</p><ul class="mt-1 flex flex-wrap gap-1.5"><li v-for="s in m.profile?.sectors ?? []" :key="s" class="chip text-xs !py-0.5">{{ term(tax.byId('sectors', s)) }}</li></ul></div>
              <div><p class="text-xs text-muted">{{ $t('auth.register.stages') }}</p><ul class="mt-1 flex flex-wrap gap-1.5"><li v-for="s in m.profile?.stages ?? []" :key="s" class="chip text-xs !py-0.5">{{ label('stage', s) }}</li></ul></div>
              <div><p class="text-xs text-muted">{{ $t('auth.register.investmentTypes') }}</p><ul class="mt-1 flex flex-wrap gap-1.5"><li v-for="s in m.profile?.investment_types ?? []" :key="s" class="chip text-xs !py-0.5">{{ label('investmentType', s) }}</li></ul></div>
              <div><p class="text-xs text-muted">{{ $t('auth.register.regions') }}</p><ul class="mt-1 flex flex-wrap gap-1.5"><li v-for="s in m.profile?.regions ?? []" :key="s" class="chip text-xs !py-0.5">{{ term(tax.byId('regions', s)) }}</li></ul></div>
              <InlineAlert tone="warning">{{ $t('admin.memberships.confidentialHint') }}</InlineAlert>
              <KeyValue :items="[{ label: $t('auth.register.investmentRange'), value: term(tax.byId('investment_ranges', m.profile?.investment_range_id)) }, { label: $t('auth.register.annualBand'), value: term(tax.byId('investment_ranges', m.profile?.annual_investment_band_id)) }, { label: $t('auth.register.riskAppetite'), value: label('riskAppetite', m.profile?.risk_appetite) }]" />
            </div>
          </SectionCard>
          <SectionCard :title="$t('investor.profile.sections.experience')"><KeyValue :cols="1" :items="[{ label: $t('auth.register.experienceYears'), value: m.profile?.experience_years, num: true }, { label: $t('auth.register.experienceDesc'), value: m.profile?.experience_desc }, { label: $t('auth.register.priorInvestmentsCount'), value: m.profile?.prior_investments_count, num: true }, { label: $t('auth.register.priorInvestmentsDesc'), value: m.profile?.prior_investments_desc }, { label: $t('auth.register.hasSocialInvestments'), value: m.profile?.has_social_investments ? `${$t('common.yes')} — ${m.profile?.social_investments_desc ?? ''}` : $t('common.no') }, { label: $t('auth.register.conflicts'), value: m.profile?.conflicts_disclosure }, { label: $t('auth.register.commitMeetings'), value: m.profile?.commit_meetings ? $t('common.yes') : $t('common.no') }]" /></SectionCard>
        </div>
        <aside class="space-y-4 lg:sticky lg:top-6">
          <div class="card card-pad">
            <div class="flex items-center justify-between"><h3 class="font-semibold">{{ $t('admin.memberships.columns.completion') }}</h3><CompletionRing :percent="m.profile?.completion_percent ?? 0" :size="56" :stroke="6" /></div>
            <ul v-if="m.missing_items?.length" class="mt-3 flex flex-wrap gap-1.5"><li v-for="x in m.missing_items" :key="x" class="text-xs rounded-full bg-warning/10 text-warning px-2 py-0.5">{{ x }}</li></ul>
          </div>
          <div class="card card-pad">
            <h3 class="font-semibold">{{ $t('admin.memberships.decision') }}</h3>
            <p v-if="m.decision_note" class="mt-2 text-sm text-muted rounded-lg bg-bg p-2">{{ m.decision_note }}</p>
            <FormField v-model="note" name="note" type="textarea" :label="$t('admin.memberships.decisionNote')" :rows="3" class="mt-3" />
            <div class="mt-3 flex flex-col gap-2">
              <AppButton v-for="s in m.allowed_transitions" :key="s" :variant="btn[s]?.variant ?? 'secondary'" :loading="busy === s" @click="transition(s)">{{ s === 'approved' && m.status === 'suspended' ? $t('admin.memberships.reactivate') : btn[s]?.label ?? label('membershipStatus', s) }}</AppButton>
            </div>
          </div>
        </aside>
      </div>
    </template>
  </div>
</template>

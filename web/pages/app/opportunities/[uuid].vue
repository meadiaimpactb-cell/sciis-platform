<script setup lang="ts">
/** Opportunity detail — doc §7.3 (OPP-05/06, INV-03/04, MSG-01). Fields shown per access level (SPEC §5). */
import type { OpportunityDetail, DocumentResource } from '~/types/api'
definePageMeta({ layout: 'portal', middleware: ['member'] })
const { t } = useI18n()
const api = useApi()
const route = useRoute()
const toast = useToast()
const { term, label } = useEnumLabel()
const { sar, number, date, fileSize } = useFormat()
const uuid = route.params.uuid as string
const { data: o, loading, refresh, error } = useAsyncResource(() => api.get<{ data: OpportunityDetail }>(`/opportunities/${uuid}`).then((r) => r.data))
watch(o, (v) => v && useHead({ title: v.name }))

const tab = ref('overview')
const tabs = computed(() => [
  { key: 'overview', label: t('investor.detail.tabs.overview') },
  { key: 'impact', label: t('investor.detail.tabs.impact') },
  { key: 'indicators', label: t('investor.detail.tabs.indicators'), count: (o.value?.disclosed_indicators.length ?? 0) + (o.value?.primary_indicator ? 1 : 0) },
  { key: 'risks', label: t('investor.detail.tabs.risks'), locked: !o.value?.risks_full },
  { key: 'documents', label: t('investor.detail.tabs.documents'), locked: o.value?.documents.locked, count: o.value?.documents.items.length || undefined },
])
const funding = computed(() => {
  const f = o.value?.funding_display
  if (!f) return '—'
  if (f.mode === 'hidden') return t('investor.opportunities.hidden')
  if (f.mode === 'exact') return sar(f.amount)
  return f.band ?? '—'
})
const interestOpen = ref(false)
const infoOpen = ref(false)
const interestNote = ref('')
const info = reactive({ subject: '', body: '' })
const infoErr = ref<Record<string, string>>({})
const busy = ref(false)

const toggleSave = async () => {
  if (!o.value) return
  try {
    if (o.value.is_saved) await api.del(`/opportunities/${uuid}/save`)
    else await api.post(`/opportunities/${uuid}/save`)
    o.value.is_saved = !o.value.is_saved
    toast.success(o.value.is_saved ? t('common.saved') : t('investor.opportunities.unsaveBtn'))
  } catch (e) { toast.error((e as Error).message) }
}
const sendInterest = async () => {
  busy.value = true
  try {
    await api.post(`/opportunities/${uuid}/interest`, { note: interestNote.value || undefined })
    interestOpen.value = false
    toast.success(t('investor.detail.interestSent'))
    await refresh()
  } catch (e) { toast.error((e as { message: string }).message) } finally { busy.value = false }
}
const sendInfo = async () => {
  busy.value = true
  infoErr.value = {}
  try {
    await api.post(`/opportunities/${uuid}/info-requests`, info)
    infoOpen.value = false
    info.subject = ''; info.body = ''
    toast.success(t('common.success'))
  } catch (e) {
    const err = e as { errors?: Record<string, string[]>; message: string }
    if (err.errors) for (const [k, v] of Object.entries(err.errors)) infoErr.value[k] = v[0]
    else toast.error(err.message)
  } finally { busy.value = false }
}
const acceptNda = async () => {
  busy.value = true
  try { await api.post(`/opportunities/${uuid}/nda-accept`, { version: '0.3-draft' }); toast.success(t('investor.detail.ndaAccepted')); await refresh() } catch (e) { toast.error((e as Error).message) } finally { busy.value = false }
}
const download = async (d: DocumentResource) => {
  try { const r = await api.get<{ url: string }>(`/documents/${d.uuid}/download-url`); toast.info(`${t('investor.detail.downloadLogged')} — ${r.url}`) } catch (e) { toast.error((e as Error).message) }
}
const indicators = computed(() => [o.value?.primary_indicator, ...(o.value?.disclosed_indicators ?? [])].filter(Boolean) as NonNullable<OpportunityDetail['primary_indicator']>[])
</script>

<template>
  <div>
    <div v-if="loading && !o" class="text-muted">{{ $t('common.loading') }}</div>
    <EmptyState v-else-if="error" :title="$t('errors.404.title')" :text="error" />
    <template v-else-if="o">
      <nav class="text-xs text-muted mb-3" aria-label="breadcrumb"><NuxtLink :to="$localePath('/app/opportunities')" class="hover:text-blue">{{ $t('portal.nav.opportunities') }}</NuxtLink> / <span class="num">{{ o.reference_no }}</span></nav>
      <!-- badges header -->
      <header class="card card-pad">
        <div class="flex flex-wrap items-center gap-2">
          <AppBadge kind="opportunity" :value="o.status" :soft="false" />
          <span class="chip chip-active text-xs !py-0.5">{{ term(o.sector) }}</span>
          <span class="chip text-xs !py-0.5">{{ label('stage', o.stage) }}</span>
          <span v-for="r in o.regions" :key="r.id" class="chip text-xs !py-0.5">{{ term(r) }}</span>
          <span v-if="o.my_nomination" class="ms-auto text-xs rounded-full bg-blue-soft text-blue px-2.5 py-1 font-medium">{{ $t('investor.detail.nominatedFor') }} · {{ $t('investor.detail.matchScore', { n: o.my_nomination.match_score }) }}</span>
        </div>
        <h1 class="mt-3 text-2xl sm:text-3xl font-semibold">{{ o.name }}</h1>
        <p v-if="o.organization_name" class="text-muted">{{ o.organization_name }}<span v-if="o.founded_year" class="num"> · {{ $t('investor.detail.founded') }} {{ o.founded_year }}</span></p>
        <p class="mt-3 leading-7 text-text/85 max-w-3xl">{{ o.teaser_summary }}</p>
        <ul v-if="o.teaser_highlights?.length" class="mt-3 flex flex-wrap gap-2"><li v-for="h in o.teaser_highlights" :key="h" class="text-xs rounded-lg bg-bg border border-border px-2.5 py-1">{{ h }}</li></ul>
      </header>

      <div class="mt-6 grid lg:grid-cols-[minmax(0,1fr)_320px] gap-6 items-start">
        <div class="min-w-0">
          <Tabs v-model="tab" :tabs="tabs" />
          <div class="mt-5 space-y-5">
            <!-- overview -->
            <div v-show="tab === 'overview'" id="panel-overview" role="tabpanel" aria-labelledby="tab-overview" class="space-y-5">
              <SectionCard :title="$t('investor.detail.problem')"><p class="leading-7 text-text/85">{{ o.problem_statement }}</p></SectionCard>
              <SectionCard :title="$t('investor.detail.solution')"><p class="leading-7 text-text/85">{{ o.solution }}</p></SectionCard>
              <SectionCard :title="$t('investor.detail.expectedChange')"><p class="leading-7 text-text/85">{{ o.expected_change }}</p></SectionCard>
              <SectionCard :title="$t('investor.detail.revenueModel')">
                <KeyValue :items="[{ label: $t('entrepreneur.form.project.revenueModelType'), value: o.revenue_model_type }, { label: $t('entrepreneur.form.project.revenueState'), value: label('revenueState', o.revenue_state) }]" />
                <p v-if="o.revenue_model_desc" class="mt-3 text-sm text-text/85 leading-6">{{ o.revenue_model_desc }}</p>
              </SectionCard>
            </div>
            <!-- impact -->
            <div v-show="tab === 'impact'" id="panel-impact" role="tabpanel" aria-labelledby="tab-impact" class="space-y-5">
              <SectionCard :title="$t('investor.detail.beneficiariesDesc')">
                <p class="leading-7 text-text/85">{{ o.beneficiaries_desc }}</p>
                <KeyValue class="mt-4" :items="[{ label: $t('entrepreneur.form.project.beneficiariesCurrent'), value: number(o.beneficiaries_current), num: true }, { label: $t('entrepreneur.form.project.beneficiariesTarget'), value: number(o.beneficiaries_target), num: true }]" />
                <ul class="mt-3 flex flex-wrap gap-1.5"><li v-for="g in o.beneficiary_groups" :key="g.id" class="chip text-xs !py-0.5">{{ term(g) }}</li></ul>
              </SectionCard>
              <SectionCard v-if="o.primary_indicator" :title="$t('investor.detail.primaryIndicator')">
                <p class="font-medium">{{ o.primary_indicator.name }}</p>
                <KeyValue class="mt-3" :cols="3" :items="[{ label: $t('investor.detail.baseline'), value: `${number(o.primary_indicator.baseline)} ${o.primary_indicator.unit}`, num: true }, { label: $t('investor.detail.target'), value: `${number(o.primary_indicator.target)} ${o.primary_indicator.unit}`, num: true }, { label: $t('investor.detail.source'), value: o.primary_indicator.data_source }]" />
                <div v-if="o.primary_indicator.latest_value != null && o.primary_indicator.target != null" class="mt-4">
                  <div class="h-2 rounded-full bg-bg overflow-hidden"><div class="h-full bg-success rounded-full" :style="{ width: `${Math.min(100, ((o.primary_indicator.latest_value - (o.primary_indicator.baseline ?? 0)) / Math.max(1, Math.abs(o.primary_indicator.target - (o.primary_indicator.baseline ?? 0)))) * 100)}%` }" /></div>
                  <p class="text-xs text-muted mt-1 num">{{ number(o.primary_indicator.latest_value) }} / {{ number(o.primary_indicator.target) }} {{ o.primary_indicator.unit }}</p>
                </div>
              </SectionCard>
              <SectionCard :title="$t('investor.detail.sdgs')"><ul class="flex flex-wrap gap-1.5"><li v-for="s in o.sdgs" :key="s.id" class="chip text-xs !py-0.5">{{ term(s) }}</li><li v-if="!o.sdgs.length" class="text-sm text-muted">—</li></ul></SectionCard>
            </div>
            <!-- indicators -->
            <div v-show="tab === 'indicators'" id="panel-indicators" role="tabpanel" aria-labelledby="tab-indicators">
              <div class="card overflow-hidden"><div class="overflow-x-auto"><table class="table-base">
                <thead><tr><th>{{ $t('entrepreneur.form.impact.indicator') }}</th><th>{{ $t('investor.detail.unit') }}</th><th>{{ $t('investor.detail.baseline') }}</th><th>{{ $t('investor.detail.target') }}</th><th>{{ $t('investor.detail.source') }}</th><th>{{ $t('investor.detail.frequency') }}</th></tr></thead>
                <tbody><tr v-for="i in indicators" :key="i.name"><td class="font-medium">{{ i.name }} <span v-if="i.is_primary" class="text-[10px] rounded-full bg-blue-soft text-blue px-1.5">{{ $t('entrepreneur.form.impact.primary') }}</span><span class="text-[10px] rounded-full bg-bg text-muted px-1.5 ms-1">{{ $t('investor.detail.disclosed') }}</span></td><td>{{ i.unit }}</td><td class="num">{{ number(i.baseline) }}</td><td class="num">{{ number(i.target) }}</td><td class="text-muted">{{ i.data_source }}</td><td>{{ label('frequency', i.frequency) }}</td></tr></tbody>
              </table></div></div>
            </div>
            <!-- risks -->
            <div v-show="tab === 'risks'" id="panel-risks" role="tabpanel" aria-labelledby="tab-risks" class="space-y-5">
              <SectionCard :title="$t('investor.detail.useOfFunds')">
                <ul class="space-y-2"><li v-for="u in o.use_of_funds ?? []" :key="u.item" class="text-sm"><div class="flex justify-between gap-2"><span>{{ u.item }}</span><span class="num text-muted">{{ number(u.percent) }}%</span></div><div class="h-1.5 rounded-full bg-bg mt-1"><div class="h-full rounded-full bg-blue" :style="{ width: `${u.percent}%` }" /></div></li></ul>
              </SectionCard>
              <SectionCard :title="$t('investor.detail.risksSummary')">
                <p class="text-sm leading-7 text-text/85">{{ o.risks_summary ?? '—' }}</p>
                <template v-if="o.risks_full">
                  <KeyValue class="mt-4" :items="[
                    { label: $t('entrepreneur.form.financials.priorFunding'), value: o.risks_full.prior_funding?.has ? `${$t('common.yes')} — ${o.risks_full.prior_funding.desc ?? ''}` : $t('common.no') },
                    { label: $t('entrepreneur.form.disclosures.legalDisputes'), value: o.risks_full.legal_disputes?.has ? `${$t('common.yes')} — ${o.risks_full.legal_disputes.desc ?? ''}` : $t('common.no') },
                    { label: $t('entrepreneur.form.disclosures.licenseRequired'), value: o.risks_full.license?.required ? `${$t('common.yes')} — ${o.risks_full.license.status ?? ''}` : $t('common.no') },
                    { label: $t('entrepreneur.form.disclosures.operationalRisks'), value: o.risks_full.operational_risks },
                  ]" :cols="1" />
                </template>
                <InlineAlert v-else tone="info" class="mt-4">{{ $t('investor.detail.risksLocked') }}</InlineAlert>
              </SectionCard>
            </div>
            <!-- documents -->
            <div v-show="tab === 'documents'" id="panel-documents" role="tabpanel" aria-labelledby="tab-documents">
              <div v-if="o.documents.locked" class="card card-pad text-center">
                <span class="mx-auto h-12 w-12 rounded-full bg-bg flex items-center justify-center text-muted" aria-hidden="true"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="11" width="14" height="10" rx="2" /><path d="M8 11V7a4 4 0 118 0v4" /></svg></span>
                <h3 class="mt-3 font-semibold">{{ $t('investor.detail.docsLocked') }}</h3>
                <p class="text-sm text-muted mt-1 max-w-md mx-auto">{{ $t('investor.detail.docsLockedText') }}</p>
                <AppButton v-if="o.my_interest?.owner_decision === 'approved' && !o.my_interest?.nda_accepted_at" class="mt-4" :loading="busy" @click="acceptNda">{{ $t('investor.detail.ndaAccept') }}</AppButton>
              </div>
              <div v-else class="card overflow-hidden">
                <InlineAlert tone="info" class="m-4">{{ $t('investor.detail.downloadLogged') }}</InlineAlert>
                <table class="table-base"><thead><tr><th>{{ $t('entrepreneur.form.documents.kind') }}</th><th>{{ $t('common.name') }}</th><th>{{ $t('common.date') }}</th><th></th></tr></thead>
                  <tbody><tr v-for="d in o.documents.items" :key="d.uuid"><td>{{ label('documentKind', d.kind) }}</td><td class="font-medium">{{ d.title }} <span class="text-xs text-muted num">({{ fileSize(d.size_bytes) }})</span></td><td class="text-muted">{{ date(d.created_at) }}</td><td class="text-end"><AppButton size="sm" variant="outline" @click="download(d)">{{ $t('common.download') }}</AppButton></td></tr></tbody></table>
              </div>
            </div>
          </div>
        </div>

        <!-- sticky side column -->
        <aside class="lg:sticky lg:top-6 space-y-4">
          <div class="card card-pad">
            <dl class="space-y-3 text-sm">
              <div><dt class="text-xs text-muted">{{ $t('investor.detail.need') }}</dt><dd class="text-xl font-semibold num">{{ funding }}</dd></div>
              <div><dt class="text-xs text-muted">{{ $t('investor.detail.type') }}</dt><dd class="font-medium">{{ o.investment_types.map((k) => label('investmentType', k)).join('، ') }}</dd></div>
              <div><dt class="text-xs text-muted">{{ $t('investor.detail.stage') }}</dt><dd class="font-medium">{{ label('stage', o.stage) }}</dd></div>
              <div><dt class="text-xs text-muted">{{ $t('investor.detail.beneficiaries') }}</dt><dd class="font-medium num">{{ number(o.beneficiaries_target) }}</dd></div>
            </dl>
            <div class="mt-5 space-y-2">
              <template v-if="o.my_interest">
                <p class="text-xs text-muted">{{ $t('investor.detail.myInterest') }}</p>
                <AppBadge kind="interest" :value="o.my_interest.status" size="md" />
                <p v-if="o.my_interest.next_step" class="text-xs text-muted">{{ o.my_interest.next_step }}</p>
              </template>
              <AppButton v-else block @click="interestOpen = true">{{ $t('investor.detail.expressInterest') }}</AppButton>
              <AppButton block variant="secondary" @click="infoOpen = true">{{ $t('investor.detail.requestInfo') }}</AppButton>
              <AppButton block variant="ghost" :aria-pressed="o.is_saved" @click="toggleSave">
                <svg class="h-4 w-4" viewBox="0 0 24 24" :fill="o.is_saved ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 4h12v17l-6-4-6 4V4z" /></svg>
                {{ o.is_saved ? $t('investor.detail.saved') : $t('investor.detail.save') }}
              </AppButton>
            </div>
          </div>
          <div v-if="o.owner_contact" class="card card-pad border-success/40">
            <p class="text-xs font-medium text-success">{{ $t('investor.detail.ownerContact') }}</p>
            <p class="text-xs text-muted">{{ $t('investor.detail.ownerContactHint') }}</p>
            <KeyValue class="mt-3" :cols="1" :items="[{ label: $t('common.name'), value: o.owner_contact.name }, { label: $t('common.email'), value: o.owner_contact.email, ltr: true }, { label: $t('common.phone'), value: o.owner_contact.phone, ltr: true, num: true }]" />
          </div>
          <InlineAlert tone="warning" :title="$t('investor.detail.noticeTitle')">{{ $t('investor.detail.notice') }}</InlineAlert>
        </aside>
      </div>

      <Modal :open="interestOpen" :title="$t('investor.detail.interestModal.title')" @close="interestOpen = false">
        <p class="text-sm text-muted leading-6">{{ $t('investor.detail.interestModal.text') }}</p>
        <FormField v-model="interestNote" name="note" type="textarea" :label="$t('investor.detail.interestModal.note')" class="mt-4" :rows="3" />
        <template #footer><AppButton variant="ghost" @click="interestOpen = false">{{ $t('common.cancel') }}</AppButton><AppButton :loading="busy" @click="sendInterest">{{ $t('investor.detail.interestModal.confirm') }}</AppButton></template>
      </Modal>
      <Modal :open="infoOpen" :title="$t('investor.detail.infoModal.title')" @close="infoOpen = false">
        <p class="text-sm text-muted leading-6">{{ $t('investor.detail.infoModal.text') }}</p>
        <div class="mt-4 space-y-4">
          <FormField v-model="info.subject" name="subject" :label="$t('investor.detail.infoModal.subject')" :error="infoErr.subject" required />
          <FormField v-model="info.body" name="body" type="textarea" :label="$t('investor.detail.infoModal.body')" :error="infoErr.body" required :rows="4" />
        </div>
        <template #footer><AppButton variant="ghost" @click="infoOpen = false">{{ $t('common.cancel') }}</AppButton><AppButton :loading="busy" @click="sendInfo">{{ $t('investor.detail.infoModal.confirm') }}</AppButton></template>
      </Modal>
    </template>
  </div>
</template>

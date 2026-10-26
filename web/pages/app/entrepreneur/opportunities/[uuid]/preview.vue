<script setup lang="ts">
/**
 * ENT-09: card + detail exactly as investors will see (public fields only).
 * The API answers `{card, detail_before_grant, detail_after_grant}` (approved member vs granted level) — the two
 * levels are shown as tabs "قبل الموافقة / بعد الموافقة". Older mock builds answered `{card, detail}`.
 */
import type { OpportunityCard, OpportunityDetail, Teaser } from '~/types/api'
import { confirmDialog, toastApiError, toastSuccess } from '~/utils/notify'
definePageMeta({ layout: 'portal', middleware: ['entrepreneur'] })
const { t } = useI18n()
useHead({ title: t('entrepreneur.preview.metaTitle') })
const api = useApi()
const route = useRoute()
const { label, term } = useEnumLabel()
const { number, sar, date, fileSize } = useFormat()
const uuid = route.params.uuid as string

type PreviewPayload = { card: OpportunityCard; detail_before_grant?: OpportunityDetail; detail_after_grant?: OpportunityDetail; detail?: OpportunityDetail }
const { data, loading, refresh } = useAsyncResource(() => api.get<{ data: PreviewPayload }>(`/entrepreneur/opportunities/${uuid}/preview`).then((r) => r.data))
const { data: full, refresh: refreshFull } = useAsyncResource(() => api.get<{ data: { teaser?: Teaser | null } }>(`/entrepreneur/opportunities/${uuid}`).then((r) => r.data))

const level = ref<'before' | 'after'>('before')
const levelTabs = computed(() => [
  { key: 'before', label: t('entrepreneur.preview.beforeGrant') },
  { key: 'after', label: t('entrepreneur.preview.afterGrant'), locked: !data.value?.detail_after_grant },
])
const detail = computed<OpportunityDetail | null>(() => {
  const d = data.value
  if (!d) return null
  if (level.value === 'after' && d.detail_after_grant) return d.detail_after_grant
  return d.detail_before_grant ?? d.detail ?? d.detail_after_grant ?? null
})
const funding = computed(() => {
  const f = detail.value?.funding_display
  if (!f) return '—'
  const mode = typeof f === 'string' ? f : f.mode
  const band = typeof f === 'string' ? (detail.value?.funding_band ? term(detail.value.funding_band) : null) : f.band
  const amount = typeof f === 'string' ? detail.value?.funding_amount : f.amount
  if (mode === 'hidden') return t('investor.opportunities.hidden')
  if (mode === 'exact') return amount != null ? sar(amount) : (band ?? '—')
  return band ?? '—'
})
const types = computed(() => (detail.value?.investment_type_keys?.length ? detail.value.investment_type_keys : detail.value?.investment_types) ?? [])
const indicators = computed(() => {
  const d = detail.value
  if (!d) return []
  return [d.primary_indicator, ...(d.disclosed_indicators ?? d.indicators?.filter((i) => !i.is_primary) ?? [])].filter(Boolean) as NonNullable<OpportunityDetail['primary_indicator']>[]
})
/** Public market/product subset (flat on the detail resource, also nested under `market`). */
const market = computed(() => {
  const d = detail.value
  const m = d?.market ?? d
  return { product_stage: m?.product_stage ?? null, target_customers: m?.target_customers ?? null, competitive_advantage: m?.competitive_advantage ?? null, traction_desc: m?.traction_desc ?? null }
})
const hasMarket = computed(() => Object.values(market.value).some(Boolean))
/** Confidential team block — null before the owner's grant. */
const team = computed(() => detail.value?.team ?? null)
const approving = ref(false)
/** Owner approval of the teaser = consent to show it to approved members (cannot be withdrawn here) → confirm first. */
const approveTeaser = async () => {
  const name = `${t('notify.entities.teaser')} — ${data.value?.card.name ?? ''}`
  if (!(await confirmDialog(t('notify.finalTitle'), t('notify.finalText', { name }), t('notify.finalConfirm')))) return
  approving.value = true
  try {
    await api.post(`/entrepreneur/opportunities/${uuid}/teaser/approve`)
    toastSuccess(t('notify.approved', { item: t('notify.entities.teaser') }))
    await Promise.all([refresh(), refreshFull()])
  } catch (e) { toastApiError(e) } finally { approving.value = false }
}
</script>
<template>
  <div>
    <PageHeader :title="$t('entrepreneur.preview.title')" :sub="$t('entrepreneur.preview.sub')"><template #actions><AppButton variant="secondary" size="sm" :to="`/app/entrepreneur/opportunities/${uuid}/edit`">{{ $t('entrepreneur.preview.backToEdit') }}</AppButton></template></PageHeader>
    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <div v-else-if="data" class="grid lg:grid-cols-[340px_minmax(0,1fr)] gap-6 items-start">
      <div class="space-y-4">
        <h2 class="font-semibold">{{ $t('entrepreneur.preview.card') }}</h2>
        <OpportunityCard :opportunity="data.card" :to="null" :show-status="false" />
        <div v-if="full?.teaser" class="card card-pad">
          <h3 class="font-semibold">{{ $t('admin.opportunity.tabs.teaser') }} <span class="text-xs text-muted num">v{{ full.teaser.version }}</span></h3>
          <p class="text-sm mt-2 leading-6">{{ full.teaser.summary_ar }}</p>
          <ul class="mt-2 flex flex-wrap gap-1.5"><li v-for="h in full.teaser.highlights" :key="h" class="chip text-xs !py-0.5">{{ h }}</li></ul>
          <div class="mt-3 flex flex-wrap gap-2 text-xs"><AppBadge kind="generic" :value="full.teaser.approved_by_owner_at ? 'approved' : 'pending'" :label="$t('admin.opportunity.ownerApproval')" /><AppBadge kind="generic" :value="full.teaser.approved_by_council_at ? 'approved' : 'pending'" :label="$t('admin.opportunity.councilApproval')" /></div>
          <AppButton v-if="!full.teaser.approved_by_owner_at" size="sm" class="mt-3" :loading="approving" @click="approveTeaser">{{ $t('common.approve') }}</AppButton>
        </div>
      </div>
      <div v-if="detail">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
          <h2 class="font-semibold">{{ $t('entrepreneur.preview.detail') }}</h2>
          <Tabs v-model="level" :tabs="levelTabs" variant="pills" />
        </div>
        <InlineAlert :tone="level === 'after' ? 'success' : 'info'" class="mb-4">{{ level === 'after' ? $t('entrepreneur.preview.afterGrantHint') : $t('entrepreneur.preview.beforeGrantHint') }}</InlineAlert>
        <div class="card card-pad space-y-5">
          <div>
            <div class="flex flex-wrap gap-2"><span v-if="detail.sector" class="chip chip-active text-xs !py-0.5">{{ term(detail.sector) }}</span><span class="chip text-xs !py-0.5">{{ label('stage', detail.stage) }}</span><span v-for="r in detail.regions ?? []" :key="r.id" class="chip text-xs !py-0.5">{{ term(r) }}</span></div>
            <h3 class="mt-3 text-2xl font-semibold">{{ detail.name }}</h3>
            <p class="mt-2 leading-7">{{ detail.teaser_summary || detail.short_description }}</p>
            <ul v-if="detail.teaser_highlights?.length" class="mt-3 flex flex-wrap gap-2"><li v-for="h in detail.teaser_highlights" :key="h" class="text-xs rounded-lg bg-bg border border-border px-2.5 py-1">{{ h }}</li></ul>
          </div>
          <KeyValue :cols="3" :items="[{ label: $t('investor.detail.need'), value: funding, num: true }, { label: $t('investor.detail.type'), value: types.map((k) => label('investmentType', k)).join('، ') || '—' }, { label: $t('investor.detail.beneficiaries'), value: number(detail.beneficiaries_target), num: true }]" />
          <section><h4 class="font-semibold">{{ $t('investor.detail.problem') }}</h4><p class="text-sm leading-7 text-text/85">{{ detail.problem_statement || '—' }}</p></section>
          <section><h4 class="font-semibold">{{ $t('investor.detail.solution') }}</h4><p class="text-sm leading-7 text-text/85">{{ detail.solution || '—' }}</p></section>
          <section><h4 class="font-semibold">{{ $t('investor.detail.expectedChange') }}</h4><p class="text-sm leading-7 text-text/85">{{ detail.expected_change || '—' }}</p></section>
          <!-- public market & product subset -->
          <section v-if="hasMarket">
            <h4 class="font-semibold">{{ $t('entrepreneur.form.steps.market') }} <span v-if="market.product_stage" class="ms-1 chip text-xs !py-0.5 align-middle">{{ label('productStage', market.product_stage) }}</span></h4>
            <div class="mt-2 grid md:grid-cols-3 gap-4 text-sm">
              <div><p class="text-xs text-muted">{{ $t('entrepreneur.form.market.targetCustomers') }}</p><p class="leading-7 text-text/85 whitespace-pre-line">{{ market.target_customers || '—' }}</p></div>
              <div><p class="text-xs text-muted">{{ $t('entrepreneur.form.market.competitiveAdvantage') }}</p><p class="leading-7 text-text/85 whitespace-pre-line">{{ market.competitive_advantage || '—' }}</p></div>
              <div><p class="text-xs text-muted">{{ $t('entrepreneur.form.market.traction') }}</p><p class="leading-7 text-text/85 whitespace-pre-line">{{ market.traction_desc || '—' }}</p></div>
            </div>
          </section>
          <!-- team & founders: confidential, only in the after-grant view -->
          <section>
            <h4 class="font-semibold">{{ $t('entrepreneur.form.steps.team') }} <span class="text-xs font-normal text-muted">({{ label('sensitivity', 'confidential') }})</span></h4>
            <template v-if="team">
              <KeyValue class="mt-2" :cols="3" :items="[
                { label: $t('entrepreneur.form.team.foundersCount'), value: team.founders_count != null ? number(team.founders_count) : null, num: true },
                { label: $t('entrepreneur.form.team.teamSize'), value: team.team_size != null ? number(team.team_size) : null, num: true },
                { label: $t('entrepreneur.form.team.commitment'), value: team.founders_commitment ? label('foundersCommitment', team.founders_commitment) : null },
              ]" />
              <ul v-if="team.founders.length" class="mt-3 text-sm space-y-1">
                <li v-for="(f, i) in team.founders" :key="`${f.name}-${i}`" class="flex flex-wrap items-center gap-x-2"><span class="font-medium">{{ f.name }}</span><span v-if="f.role" class="text-muted">— {{ f.role }}</span><a v-if="f.linkedin_url" :href="f.linkedin_url" target="_blank" rel="noopener noreferrer nofollow" class="ltr text-xs text-blue hover:underline">LinkedIn</a></li>
              </ul>
            </template>
            <InlineAlert v-else tone="info" class="mt-2">{{ $t('entrepreneur.preview.teamAfterGrantHint') }}</InlineAlert>
          </section>
          <section v-if="indicators.length">
            <h4 class="font-semibold">{{ $t('investor.detail.tabs.indicators') }}</h4>
            <ul class="text-sm space-y-1 mt-1"><li v-for="i in indicators" :key="i.id ?? i.name">{{ i.name }} <span v-if="i.is_primary" class="text-[10px] rounded-full bg-blue-soft text-blue px-1.5">{{ $t('entrepreneur.form.impact.primary') }}</span> — <span class="num">{{ number(i.baseline) }} → {{ number(i.target) }} {{ i.unit }}</span></li></ul>
          </section>
          <section><h4 class="font-semibold">{{ $t('investor.detail.useOfFunds') }}</h4><ul class="text-sm space-y-1"><li v-for="u in detail.use_of_funds ?? []" :key="u.item" class="flex justify-between"><span>{{ u.item }}</span><span class="num text-muted">{{ number(u.percent) }}%</span></li></ul></section>
          <section>
            <h4 class="font-semibold">{{ $t('investor.detail.risksSummary') }}</h4>
            <p class="text-sm leading-7 text-text/85">{{ detail.risks_summary ?? '—' }}</p>
            <KeyValue v-if="detail.risks_full" class="mt-3" :cols="1" :items="[
              { label: $t('entrepreneur.form.financials.priorFunding'), value: detail.risks_full.prior_funding?.has ? `${$t('common.yes')} — ${detail.risks_full.prior_funding.desc ?? ''}` : $t('common.no') },
              { label: $t('entrepreneur.form.disclosures.legalDisputes'), value: detail.risks_full.legal_disputes?.has ? `${$t('common.yes')} — ${detail.risks_full.legal_disputes.desc ?? ''}` : $t('common.no') },
              { label: $t('entrepreneur.form.disclosures.licenseRequired'), value: detail.risks_full.license?.required ? `${$t('common.yes')} — ${detail.risks_full.license.status ?? ''}` : $t('common.no') },
              { label: $t('entrepreneur.form.disclosures.operationalRisks'), value: detail.risks_full.operational_risks },
            ]" />
            <InlineAlert v-else tone="info" class="mt-3">{{ $t('investor.detail.risksLocked') }}</InlineAlert>
          </section>
          <section>
            <h4 class="font-semibold">{{ $t('investor.detail.tabs.documents') }}</h4>
            <InlineAlert v-if="detail.documents?.locked ?? true" tone="info" class="mt-2">{{ $t('investor.detail.docsLockedText') }}</InlineAlert>
            <table v-else class="table-base mt-2"><thead><tr><th>{{ $t('entrepreneur.form.documents.kind') }}</th><th>{{ $t('common.name') }}</th><th>{{ $t('common.date') }}</th></tr></thead>
              <tbody>
                <tr v-for="d in detail.documents.items" :key="d.uuid"><td>{{ label('documentKind', d.kind) }}</td><td class="font-medium">{{ d.title }} <span class="text-xs text-muted num">({{ fileSize(d.size_bytes) }})</span></td><td class="text-muted">{{ date(d.created_at ?? d.uploaded_at) }}</td></tr>
                <tr v-if="!detail.documents.items.length"><td colspan="3" class="text-center text-muted py-4">{{ $t('common.noData') }}</td></tr>
              </tbody></table>
          </section>
        </div>
      </div>
    </div>
  </div>
</template>

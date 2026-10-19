<script setup lang="ts">
/** ENT-09: card + detail exactly as investors will see (public fields only). */
import type { OpportunityCard, OpportunityDetail, Teaser } from '~/types/api'
definePageMeta({ layout: 'portal', middleware: ['entrepreneur'] })
const { t } = useI18n()
useHead({ title: t('entrepreneur.preview.metaTitle') })
const api = useApi()
const route = useRoute()
const toast = useToast()
const { label, term } = useEnumLabel()
const { number } = useFormat()
const uuid = route.params.uuid as string
const { data, loading, refresh } = useAsyncResource(() => api.get<{ data: { card: OpportunityCard; detail: OpportunityDetail } }>(`/entrepreneur/opportunities/${uuid}/preview`).then((r) => r.data))
const { data: full } = useAsyncResource(() => api.get<{ data: { teaser?: Teaser | null } }>(`/entrepreneur/opportunities/${uuid}`).then((r) => r.data))
const approveTeaser = async () => { try { await api.post(`/entrepreneur/opportunities/${uuid}/teaser/approve`); toast.success(t('common.success')); await refresh() } catch (e) { toast.error((e as Error).message) } }
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
          <AppButton v-if="!full.teaser.approved_by_owner_at" size="sm" class="mt-3" @click="approveTeaser">{{ $t('common.approve') }}</AppButton>
        </div>
      </div>
      <div>
        <h2 class="font-semibold mb-4">{{ $t('entrepreneur.preview.detail') }}</h2>
        <div class="card card-pad space-y-5">
          <div><div class="flex flex-wrap gap-2"><span class="chip chip-active text-xs !py-0.5">{{ term(data.detail.sector) }}</span><span class="chip text-xs !py-0.5">{{ label('stage', data.detail.stage) }}</span><span v-for="r in data.detail.regions" :key="r.id" class="chip text-xs !py-0.5">{{ term(r) }}</span></div><h3 class="mt-3 text-2xl font-semibold">{{ data.detail.name }}</h3><p class="mt-2 leading-7">{{ data.detail.teaser_summary }}</p></div>
          <KeyValue :cols="3" :items="[{ label: $t('investor.detail.need'), value: data.detail.funding_display.mode === 'band' ? data.detail.funding_display.band : data.detail.funding_display.mode === 'hidden' ? $t('investor.opportunities.hidden') : number(data.detail.funding_display.amount) }, { label: $t('investor.detail.type'), value: data.detail.investment_types.map((k) => label('investmentType', k)).join('، ') }, { label: $t('investor.detail.beneficiaries'), value: number(data.detail.beneficiaries_target), num: true }]" />
          <section><h4 class="font-semibold">{{ $t('investor.detail.problem') }}</h4><p class="text-sm leading-7 text-text/85">{{ data.detail.problem_statement || '—' }}</p></section>
          <section><h4 class="font-semibold">{{ $t('investor.detail.solution') }}</h4><p class="text-sm leading-7 text-text/85">{{ data.detail.solution || '—' }}</p></section>
          <section><h4 class="font-semibold">{{ $t('investor.detail.expectedChange') }}</h4><p class="text-sm leading-7 text-text/85">{{ data.detail.expected_change || '—' }}</p></section>
          <section v-if="data.detail.primary_indicator"><h4 class="font-semibold">{{ $t('investor.detail.primaryIndicator') }}</h4><p class="text-sm">{{ data.detail.primary_indicator.name }} — <span class="num">{{ number(data.detail.primary_indicator.baseline) }} → {{ number(data.detail.primary_indicator.target) }} {{ data.detail.primary_indicator.unit }}</span></p></section>
          <section><h4 class="font-semibold">{{ $t('investor.detail.useOfFunds') }}</h4><ul class="text-sm space-y-1"><li v-for="u in data.detail.use_of_funds ?? []" :key="u.item" class="flex justify-between"><span>{{ u.item }}</span><span class="num text-muted">{{ number(u.percent) }}%</span></li></ul></section>
          <InlineAlert tone="info">{{ $t('investor.detail.docsLockedText') }}</InlineAlert>
        </div>
      </div>
    </div>
  </div>
</template>

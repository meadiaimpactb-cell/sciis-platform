<script setup lang="ts">
/**
 * OPP-12 — side-by-side comparison of 2–4 opportunities (`?ids=uuid,uuid`), public fields only
 * (GET /opportunities/compare — same sensitivity as the card / approved-member detail).
 */
import type { CompareItem } from '~/types/discovery'
import type { FundingDisplay } from '~/types/api'
import { toastApiError } from '~/utils/notify'
definePageMeta({ layout: 'portal', middleware: ['member'] })
const { t } = useI18n()
useHead({ title: t('investor.opportunities.compare.metaTitle') })
const api = useApi()
const route = useRoute()
const router = useRouter()
const localePath = useLocalePath()
const { term, label } = useEnumLabel()
const { sar, number, date } = useFormat()
const compare = useCompare()
const compareMin = COMPARE_MIN

const ids = computed(() => String(route.query.ids ?? '').split(',').map((s) => s.trim()).filter(Boolean).slice(0, COMPARE_MAX))
const items = ref<CompareItem[]>([])
const loading = ref(false)
const failed = ref(false)
const load = async () => {
  if (ids.value.length < compareMin) { items.value = []; return }
  loading.value = true
  failed.value = false
  try {
    items.value = (await api.get<{ data: { items: CompareItem[] } }>('/opportunities/compare', { ids: ids.value.join(',') })).data.items
    // Keep the catalogue's compare bar in step with what is shown here.
    compare.picks.value = items.value.map((o) => ({ uuid: o.uuid, name: o.name }))
  } catch (e) {
    failed.value = true
    toastApiError(e)
  } finally { loading.value = false }
}
onMounted(load)
watch(ids, load)

const removeColumn = (uuid: string) => {
  compare.remove(uuid)
  router.replace({ path: localePath('/app/opportunities/compare'), query: { ids: ids.value.filter((x) => x !== uuid).join(',') } })
}

const funding = (o: CompareItem) => {
  const f = o.funding_display as FundingDisplay
  if (!f || f.mode === 'hidden') return t('investor.opportunities.hidden')
  if (f.mode === 'exact') return f.amount != null ? sar(f.amount) : (f.band ?? '—')
  return f.band ?? '—'
}
const terms = (list: CompareItem['regions'] | undefined) => (list ?? []).map((x) => term(x)).join('، ') || '—'
const types = (o: CompareItem) => ((o.investment_type_keys?.length ? o.investment_type_keys : o.investment_types) ?? []).map((k) => label('investmentType', k as never)).join('، ') || '—'
const indicator = (o: CompareItem) => (o.primary_indicator ? `${o.primary_indicator.name} — ${number(o.primary_indicator.target)} ${o.primary_indicator.unit ?? ''}`.trim() : '—')

/** Rows: label + value per column (`num` = Latin-digit figure, `pre` = multi-line text). */
interface CompareRow { key: string; label: string; value: (o: CompareItem) => string; num?: boolean; pre?: boolean }
const rows = computed<CompareRow[]>(() => [
  { key: 'organization', label: t('investor.detail.organization'), value: (o: CompareItem) => o.organization_name || '—' },
  { key: 'sector', label: t('investor.detail.sector'), value: (o: CompareItem) => term(o.sector) || '—' },
  { key: 'stage', label: t('investor.detail.stage'), value: (o: CompareItem) => label('stage', o.stage) },
  { key: 'regions', label: t('investor.detail.regions'), value: (o: CompareItem) => terms(o.regions) },
  { key: 'type', label: t('investor.detail.type'), value: (o: CompareItem) => types(o) },
  { key: 'need', label: t('investor.detail.need'), value: (o: CompareItem) => funding(o), num: true },
  { key: 'beneficiaries', label: t('investor.detail.beneficiaries'), value: (o: CompareItem) => number(o.beneficiaries_target), num: true },
  { key: 'groups', label: t('investor.opportunities.filters.beneficiary'), value: (o: CompareItem) => terms(o.beneficiary_groups) },
  { key: 'sdgs', label: t('investor.detail.sdgs'), value: (o: CompareItem) => terms(o.sdgs) },
  { key: 'indicator', label: t('investor.detail.primaryIndicator'), value: (o: CompareItem) => indicator(o) },
  { key: 'revenue', label: t('entrepreneur.form.project.revenueState'), value: (o: CompareItem) => (o.revenue_state ? label('revenueState', o.revenue_state as never) : '—') },
  { key: 'product', label: t('entrepreneur.form.market.productStage'), value: (o: CompareItem) => (o.product_stage ? label('productStage', o.product_stage as never) : '—') },
  { key: 'founded', label: t('investor.detail.founded'), value: (o: CompareItem) => (o.founded_year ? String(o.founded_year) : '—'), num: true },
  { key: 'published', label: t('investor.opportunities.compare.published'), value: (o: CompareItem) => (o.published_at ? date(o.published_at) : '—') },
  { key: 'summary', label: t('investor.opportunities.compare.summary'), value: (o: CompareItem) => o.teaser_summary || o.short_description || '—', pre: true },
])
</script>

<template>
  <div>
    <nav class="text-xs text-muted mb-3" aria-label="breadcrumb"><NuxtLink :to="$localePath('/app/opportunities')" class="hover:text-blue">{{ $t('portal.nav.opportunities') }}</NuxtLink> / <span>{{ $t('investor.opportunities.compare.title') }}</span></nav>
    <PageHeader :title="$t('investor.opportunities.compare.title')" :sub="$t('investor.opportunities.compare.sub')" />

    <EmptyState v-if="ids.length < compareMin" :title="$t('investor.opportunities.compare.needTwo')" :text="$t('investor.opportunities.compare.needTwoText')">
      <AppButton to="/app/opportunities" size="sm">{{ $t('portal.nav.opportunities') }}</AppButton>
    </EmptyState>
    <div v-else-if="loading && !items.length" class="text-muted">{{ $t('common.loading') }}</div>
    <EmptyState v-else-if="failed" :title="$t('investor.opportunities.compare.unavailable')">
      <AppButton to="/app/opportunities" size="sm" variant="outline">{{ $t('portal.nav.opportunities') }}</AppButton>
    </EmptyState>
    <div v-else-if="items.length" class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="table-base min-w-[640px]">
          <caption class="sr-only">{{ $t('investor.opportunities.compare.title') }}</caption>
          <thead>
            <tr>
              <th scope="col" class="w-44">{{ $t('investor.opportunities.compare.field') }}</th>
              <th v-for="o in items" :key="o.uuid" scope="col" class="!normal-case !tracking-normal align-top min-w-[11rem]">
                <div class="flex items-start justify-between gap-2">
                  <div class="min-w-0">
                    <NuxtLink :to="$localePath(`/app/opportunities/${o.uuid}`)" class="text-sm font-semibold text-ink hover:text-navy">{{ o.name }}</NuxtLink>
                    <p v-if="o.reference_no" class="num text-[11px] font-normal text-muted mt-0.5">{{ o.reference_no }}</p>
                  </div>
                  <button v-if="items.length > compareMin" type="button" class="h-6 w-6 shrink-0 inline-flex items-center justify-center rounded-full text-muted hover:text-danger kbd-focus" :aria-label="$t('investor.opportunities.compare.remove', { name: o.name })" @click="removeColumn(o.uuid)">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
                  </button>
                </div>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in rows" :key="r.key">
              <th scope="row" class="!bg-transparent !normal-case !tracking-normal !text-xs !font-medium text-muted align-top">{{ r.label }}</th>
              <td v-for="o in items" :key="o.uuid" class="align-top text-sm" :class="[r.num && 'num', r.pre && 'leading-6 text-text/85 whitespace-pre-line']">{{ r.value(o) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <Disclaimer variant="box" class="mt-6" />
  </div>
</template>

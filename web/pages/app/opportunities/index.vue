<script setup lang="ts">
/** Opportunities catalogue — doc §7.2 (OPP-01..04). Approved members only. */
import type { OpportunityCard, Paginated } from '~/types/api'
import type { FilterGroup } from '~/components/FilterSidebar.vue'
definePageMeta({ layout: 'portal', middleware: ['member'] })
const { t } = useI18n()
useHead({ title: t('investor.opportunities.metaTitle') })
const api = useApi()
const tax = useTaxonomyStore()
await tax.load().catch(() => {})
const { term, label } = useEnumLabel()
const toast = useToast()
const route = useRoute()
const router = useRouter()

const filters = ref<Record<string, string | null>>({ sector: (route.query.sector as string) ?? null, region: null, stage: null, type: null, range: null })
const search = ref('')
const sort = ref<'newest' | 'impact' | 'need'>('newest')
const nominatedOnly = ref(false)
const page = ref(1)
const counts = ref<Record<string, Record<string, number>>>({})
const policy = ref<string>('nomination_only')
const list = ref<OpportunityCard[]>([])
const meta = ref({ total: 0, last_page: 1 })
const loading = ref(false)

const load = async () => {
  loading.value = true
  try {
    const q: Record<string, unknown> = { page: page.value, per_page: 12, sort: sort.value, search: search.value || undefined }
    for (const [k, v] of Object.entries(filters.value)) if (v) q[`filter[${k}]`] = v
    if (nominatedOnly.value) q['filter[nominated]'] = '1'
    const res = await api.get<Paginated<OpportunityCard> & { meta: { counts: Record<string, Record<string, number>>; access_policy: string } }>('/opportunities', q)
    list.value = res.data
    meta.value = { total: res.meta.total, last_page: res.meta.last_page }
    counts.value = res.meta.counts ?? {}
    policy.value = res.meta.access_policy ?? 'nomination_only'
  } catch (e) { toast.error((e as Error).message) } finally { loading.value = false }
}
onMounted(load)
watch([filters, sort, nominatedOnly], () => { page.value = 1; load() }, { deep: true })
watch(page, load)
let st: ReturnType<typeof setTimeout>
watch(search, () => { clearTimeout(st); st = setTimeout(() => { page.value = 1; load() }, 300) })

const groups = computed<FilterGroup[]>(() => [
  { key: 'sector', label: t('investor.opportunities.filters.sector'), options: tax.list('sectors').map((x) => ({ value: x.key, label: term(x), count: counts.value.sector?.[x.key] ?? 0 })).filter((o) => o.count > 0 || filters.value.sector === o.value) },
  { key: 'region', label: t('investor.opportunities.filters.region'), options: tax.list('regions').map((x) => ({ value: x.key, label: term(x), count: counts.value.region?.[x.key] ?? 0 })).filter((o) => o.count > 0 || filters.value.region === o.value) },
  { key: 'stage', label: t('investor.opportunities.filters.stage'), options: tax.list('project_stages').map((x) => ({ value: x.key, label: label('stage', x.key), count: counts.value.stage?.[x.key] ?? 0 })) },
  { key: 'type', label: t('investor.opportunities.filters.type'), options: tax.list('investment_types').map((x) => ({ value: x.key, label: label('investmentType', x.key), count: counts.value.type?.[x.key] ?? 0 })) },
  { key: 'range', label: t('investor.opportunities.filters.range'), options: tax.list('investment_ranges').map((x) => ({ value: x.key, label: term(x), count: counts.value.range?.[x.key] ?? 0 })) },
])
const reset = () => { filters.value = { sector: null, region: null, stage: null, type: null, range: null }; search.value = ''; nominatedOnly.value = false }
const toggleSave = async (o: OpportunityCard) => {
  try {
    if (o.is_saved) await api.del(`/opportunities/${o.uuid}/save`)
    else await api.post(`/opportunities/${o.uuid}/save`)
    o.is_saved = !o.is_saved
    toast.success(o.is_saved ? t('common.saved') : t('investor.opportunities.unsaveBtn'))
  } catch (e) { toast.error((e as Error).message) }
}
const sortOpts = [{ value: 'newest', label: t('investor.opportunities.sort.newest') }, { value: 'impact', label: t('investor.opportunities.sort.impact') }, { value: 'need', label: t('investor.opportunities.sort.need') }]
</script>

<template>
  <div>
    <PageHeader :title="$t('investor.opportunities.title')" :sub="$t('investor.opportunities.sub')" />
    <InlineAlert tone="info" class="mb-5">{{ $t('investor.opportunities.policyNote', { policy: $t(`investor.opportunities.policy.${policy}`) }) }} <span class="text-xs text-muted">— {{ $t('app.pendingDecision') }}</span></InlineAlert>
    <div class="grid lg:grid-cols-[280px_minmax(0,1fr)] gap-6 items-start">
      <FilterSidebar v-model="filters" :groups="groups" class="lg:sticky lg:top-6" @reset="reset" />
      <div class="min-w-0">
        <div class="card p-3 flex flex-col sm:flex-row gap-3 sm:items-center">
          <label class="sr-only" for="opp-search">{{ $t('common.search') }}</label>
          <input id="opp-search" v-model="search" type="search" class="input flex-1" :placeholder="$t('common.searchPlaceholder')" />
          <div class="flex items-center gap-3">
            <label class="text-sm text-muted whitespace-nowrap" for="opp-sort">{{ $t('common.sortBy') }}</label>
            <select id="opp-sort" v-model="sort" class="input w-auto"><option v-for="o in sortOpts" :key="o.value" :value="o.value">{{ o.label }}</option></select>
            <label class="inline-flex items-center gap-2 text-sm whitespace-nowrap cursor-pointer"><input v-model="nominatedOnly" type="checkbox" class="h-4 w-4 rounded border-border text-blue focus:ring-blue" />{{ $t('investor.opportunities.nominatedOnly') }}</label>
          </div>
        </div>
        <div class="mt-4 flex items-center justify-between text-sm text-muted" aria-live="polite"><span>{{ $t('common.resultCount', { n: meta.total }) }}</span><span v-if="loading">{{ $t('common.loading') }}</span></div>
        <div v-if="list.length" class="mt-3 grid sm:grid-cols-2 xl:grid-cols-3 gap-5">
          <OpportunityCard v-for="o in list" :key="o.uuid" :opportunity="o" saveable @toggle-save="toggleSave" />
        </div>
        <EmptyState v-else-if="!loading" class="mt-3" :title="$t('investor.opportunities.empty')"><AppButton variant="outline" size="sm" @click="reset">{{ $t('common.clearAll') }}</AppButton></EmptyState>
        <Pagination v-model:page="page" :last-page="meta.last_page" :total="meta.total" class="mt-5" />
        <Disclaimer variant="box" class="mt-6" />
      </div>
    </div>
  </div>
</template>

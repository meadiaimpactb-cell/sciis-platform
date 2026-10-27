<script setup lang="ts">
/**
 * Opportunities catalogue — doc §7.2 (OPP-01..04). Approved members only.
 * OPP-10 impact facets (beneficiary group, SDG) with counts over the applied filters; OPP-07/08 saved searches and
 * alerts; OPP-12 pick 2–4 opportunities to compare.
 */
import type { OpportunityCard, Paginated } from '~/types/api'
import type { FilterGroup } from '~/components/FilterSidebar.vue'
import type { BrowseFacet, SavedSearchFilters } from '~/types/discovery'
import { toastApiError, toastSuccess, toastWarning } from '~/utils/notify'
definePageMeta({ layout: 'portal', middleware: ['member'] })
const { t } = useI18n()
useHead({ title: t('investor.opportunities.metaTitle') })
const api = useApi()
const tax = useTaxonomyStore()
await tax.load().catch(() => {})
const { term, label } = useEnumLabel()
const route = useRoute()

type SortKey = 'newest' | 'impact' | 'need'
const emptyFilters = (): Record<BrowseFacet, string | null> => ({ sector: null, region: null, stage: null, type: null, range: null, beneficiary: null, sdg: null })
const filters = ref<Record<string, string | null>>({ ...emptyFilters(), sector: (route.query.sector as string) ?? null })
const search = ref('')
const sort = ref<SortKey>('newest')
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
  } catch (e) { toastApiError(e) } finally { loading.value = false }
}
onMounted(load)
/** Set while a saved search is applied, so the watchers below don't each trigger a request. */
let applying = false
watch([filters, sort, nominatedOnly], () => { if (applying) return; page.value = 1; load() }, { deep: true })
watch(page, () => { if (!applying) load() })
let st: ReturnType<typeof setTimeout>
watch(search, () => { if (applying) return; clearTimeout(st); st = setTimeout(() => { page.value = 1; load() }, 300) })

// Facet counts come from `meta.counts.{facet}[key]` and follow the applied filters (OPP-10); when the API sends no
// counts for a facet the options stay visible and enabled (count undefined) instead of being hidden as "0 results".
const countFor = (facet: string, key: string): number | undefined => (counts.value[facet] ? counts.value[facet][key] ?? 0 : undefined)
const keepVisible = (facet: string, selected: string | null) => (o: { value: string; count?: number }) => o.count === undefined || o.count > 0 || selected === o.value || facet === ''
const groups = computed<FilterGroup[]>(() => [
  { key: 'sector', label: t('investor.opportunities.filters.sector'), options: tax.list('sectors').map((x) => ({ value: x.key, label: term(x), count: countFor('sector', x.key) })).filter(keepVisible('sector', filters.value.sector)) },
  { key: 'region', label: t('investor.opportunities.filters.region'), options: tax.list('regions').map((x) => ({ value: x.key, label: term(x), count: countFor('region', x.key) })).filter(keepVisible('region', filters.value.region)) },
  { key: 'stage', label: t('investor.opportunities.filters.stage'), options: tax.list('project_stages').map((x) => ({ value: x.key, label: label('stage', x.key), count: countFor('stage', x.key) })) },
  { key: 'type', label: t('investor.opportunities.filters.type'), options: tax.list('investment_types').map((x) => ({ value: x.key, label: label('investmentType', x.key), count: countFor('type', x.key) })) },
  { key: 'range', label: t('investor.opportunities.filters.range'), options: tax.list('investment_ranges').map((x) => ({ value: x.key, label: term(x), count: countFor('range', x.key) })) },
  // OPP-10 — impact filters
  { key: 'beneficiary', label: t('investor.opportunities.filters.beneficiary'), collapsed: true, options: tax.list('beneficiary_groups').map((x) => ({ value: x.key, label: term(x), count: countFor('beneficiary', x.key) })).filter(keepVisible('beneficiary', filters.value.beneficiary)) },
  { key: 'sdg', label: t('investor.opportunities.filters.sdg'), collapsed: true, options: tax.list('sdgs').map((x) => ({ value: x.key, label: term(x), count: countFor('sdg', x.key) })).filter(keepVisible('sdg', filters.value.sdg)) },
])
const reset = () => { filters.value = emptyFilters(); search.value = ''; nominatedOnly.value = false }
const toggleSave = async (o: OpportunityCard) => {
  try {
    if (o.is_saved) await api.del(`/opportunities/${o.uuid}/save`)
    else await api.post(`/opportunities/${o.uuid}/save`)
    o.is_saved = !o.is_saved
    toastSuccess(t(o.is_saved ? 'notify.savedToList' : 'notify.removedFromList', { name: o.name }))
  } catch (e) { toastApiError(e) }
}
const sortOpts = [{ value: 'newest', label: t('investor.opportunities.sort.newest') }, { value: 'impact', label: t('investor.opportunities.sort.impact') }, { value: 'need', label: t('investor.opportunities.sort.need') }]

// ---------------------------------------------------------------- OPP-08 saved searches
const current = computed<SavedSearchFilters>(() => {
  const picked: SavedSearchFilters = { search: search.value, nominated: nominatedOnly.value, sort: sort.value }
  for (const [k, v] of Object.entries(filters.value)) if (v) picked[k as BrowseFacet] = v
  return picked
})
const applySaved = async (f: SavedSearchFilters) => {
  applying = true
  clearTimeout(st)
  const next = emptyFilters()
  for (const k of Object.keys(next) as BrowseFacet[]) next[k] = f[k] ?? null
  filters.value = next
  search.value = f.search ?? ''
  nominatedOnly.value = !!f.nominated
  sort.value = f.sort ?? 'newest'
  page.value = 1
  await nextTick()
  applying = false
  await load()
}

// ---------------------------------------------------------------- OPP-12 compare
const compare = useCompare()
const compareMin = COMPARE_MIN
const compareMax = COMPARE_MAX
const toggleCompare = (o: OpportunityCard) => {
  if (!compare.toggle({ uuid: o.uuid, name: o.name })) toastWarning(t('investor.opportunities.compare.full', { n: compareMax }))
}
</script>

<template>
  <div>
    <PageHeader :title="$t('investor.opportunities.title')" :sub="$t('investor.opportunities.sub')" />
    <InlineAlert tone="info" class="mb-5">{{ $t('investor.opportunities.policyNote', { policy: $t(`investor.opportunities.policy.${policy}`) }) }} <span class="text-xs text-muted">— {{ $t('app.pendingDecision') }}</span></InlineAlert>
    <div class="grid lg:grid-cols-[280px_minmax(0,1fr)] gap-6 items-start">
      <FilterSidebar v-model="filters" :groups="groups" class="lg:sticky lg:top-20" @reset="reset" />
      <div class="min-w-0">
        <div class="card p-3 flex flex-col sm:flex-row gap-3 sm:items-center">
          <label class="sr-only" for="opp-search">{{ $t('common.search') }}</label>
          <input id="opp-search" v-model="search" type="search" class="input flex-1" :placeholder="$t('common.searchPlaceholder')" />
          <div class="flex items-center gap-3">
            <label class="text-sm text-muted whitespace-nowrap" for="opp-sort">{{ $t('common.sortBy') }}</label>
            <select id="opp-sort" v-model="sort" class="input w-auto"><option v-for="o in sortOpts" :key="o.value" :value="o.value">{{ o.label }}</option></select>
            <label class="inline-flex items-center gap-2 text-sm whitespace-nowrap cursor-pointer"><input v-model="nominatedOnly" type="checkbox" class="h-4 w-4 rounded accent-accent" />{{ $t('investor.opportunities.nominatedOnly') }}</label>
          </div>
        </div>
        <SavedSearches class="mt-3" :current="current" @apply="applySaved" />
        <div class="mt-4 flex items-center justify-between text-sm text-muted" aria-live="polite"><span>{{ $t('common.resultCount', { n: meta.total }) }}</span><span v-if="loading">{{ $t('common.loading') }}</span></div>
        <div v-if="list.length" class="mt-3 grid sm:grid-cols-2 xl:grid-cols-3 gap-5">
          <OpportunityCard v-for="o in list" :key="o.uuid" :opportunity="o" saveable @toggle-save="toggleSave">
            <template #footer>
              <label class="inline-flex items-center gap-2 text-xs text-muted cursor-pointer w-fit" :class="compare.full.value && !compare.has(o.uuid) && 'opacity-60'">
                <input type="checkbox" class="h-4 w-4 rounded accent-accent" :checked="compare.has(o.uuid)" @change="toggleCompare(o)" />
                {{ $t('investor.opportunities.compare.add') }}
              </label>
            </template>
          </OpportunityCard>
        </div>
        <EmptyState v-else-if="!loading" class="mt-3" :title="$t('investor.opportunities.empty')"><AppButton variant="outline" size="sm" @click="reset">{{ $t('common.clearAll') }}</AppButton></EmptyState>
        <!-- OPP-12 compare bar -->
        <div v-if="compare.picks.value.length" class="sticky bottom-4 z-20 mt-5 card p-3 shadow-pop border-accent/40 flex flex-col sm:flex-row sm:items-center gap-3" role="region" :aria-label="$t('investor.opportunities.compare.bar')">
          <p class="text-sm font-medium text-ink shrink-0">{{ $t('investor.opportunities.compare.selected', { n: compare.picks.value.length, max: compareMax }) }}</p>
          <ul class="flex flex-wrap gap-1.5 min-w-0 flex-1">
            <li v-for="p in compare.picks.value" :key="p.uuid" class="inline-flex items-center gap-1 rounded-full bg-glass border border-ink/15 ps-3 pe-1 py-0.5 text-xs text-ink">
              <span class="truncate max-w-[10rem]">{{ p.name }}</span>
              <button type="button" class="h-5 w-5 inline-flex items-center justify-center rounded-full text-muted hover:text-danger kbd-focus" :aria-label="$t('investor.opportunities.compare.remove', { name: p.name })" @click="compare.remove(p.uuid)"><svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg></button>
            </li>
          </ul>
          <div class="flex items-center gap-2 shrink-0">
            <AppButton size="sm" variant="ghost" @click="compare.clear()">{{ $t('common.clearAll') }}</AppButton>
            <AppButton size="sm" :to="compare.path.value" :disabled="compare.picks.value.length < compareMin">{{ $t('investor.opportunities.compare.go') }}</AppButton>
          </div>
        </div>
        <Pagination v-model:page="page" :last-page="meta.last_page" :total="meta.total" class="mt-5" />
        <Disclaimer variant="box" class="mt-6" />
      </div>
    </div>
  </div>
</template>

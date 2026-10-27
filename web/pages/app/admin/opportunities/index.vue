<script setup lang="ts">
/** Admin work queue — GET /admin/opportunities with `filter[status|sector|overdue|assignee|search]` (#17);
 *  MAT-01: assignee filter (incl. unassigned) and every filter kept in the URL query. */
import type { Paginated, QueueItem, TermRef, UserPick } from '~/types/api'
import { OPPORTUNITY_STATUSES } from '~/types/enums'
definePageMeta({ layout: 'portal', middleware: ['role'] })
const { t } = useI18n()
useHead({ title: t('admin.opportunities.metaTitle') })
const api = useApi()
const tax = useTaxonomyStore()
await tax.load().catch(() => {})
const route = useRoute()
const router = useRouter()
const { label, term } = useEnumLabel()
const { relative } = useFormat()
const qv = (k: string) => (typeof route.query[k] === 'string' ? (route.query[k] as string) : '')
const status = ref(qv('status'))
const sector = ref(qv('sector'))
const overdue = ref(route.query.overdue === '1')
const assignee = ref(qv('assignee'))
const search = ref(qv('search'))
const page = ref(Number(qv('page')) || 1)
// Reviewer picker (slim list — allowed for any staff with opportunities.view when `role=` is given).
const reviewers = ref<UserPick[]>([])
onMounted(() => api.get<Paginated<UserPick>>('/admin/users', { role: 'reviewer,super_admin', per_page: 100 }).then((r) => { reviewers.value = r.data ?? [] }).catch(() => {}))
const syncUrl = () => router.replace({ query: { ...(status.value ? { status: status.value } : {}), ...(sector.value ? { sector: sector.value } : {}), ...(overdue.value ? { overdue: '1' } : {}), ...(assignee.value ? { assignee: assignee.value } : {}), ...(search.value.trim() ? { search: search.value.trim() } : {}), ...(page.value > 1 ? { page: String(page.value) } : {}) } })
const rows = ref<QueueItem[]>([])
const meta = ref({ total: 0, last_page: 1 })
const loading = ref(false)
const sectorName = (s: TermRef | null | undefined) => s?.label ?? (s?.label_ar ? term(s as never) : '—')
const load = async () => {
  loading.value = true
  try {
    const q: Record<string, unknown> = { page: page.value, per_page: 20 }
    if (search.value) q['filter[search]'] = search.value
    if (status.value) q['filter[status]'] = status.value
    if (sector.value) q['filter[sector]'] = sector.value
    if (overdue.value) q['filter[overdue]'] = '1'
    if (assignee.value) q['filter[assignee]'] = assignee.value
    const r = await api.get<Paginated<QueueItem>>('/admin/opportunities', q)
    rows.value = (r.data ?? []).map((x) => ({ ...x, owner_name: x.owner_name ?? x.owner?.name ?? '—', assignee: x.assignee ?? x.assigned_reviewer ?? null }))
    meta.value = { total: r.meta?.total ?? rows.value.length, last_page: r.meta?.last_page ?? 1 }
  } finally { loading.value = false }
}
onMounted(load)
watch([status, sector, overdue, assignee], () => { page.value = 1; syncUrl(); load() })
watch(page, () => { syncUrl(); load() })
let st: ReturnType<typeof setTimeout>; watch(search, () => { clearTimeout(st); st = setTimeout(() => { page.value = 1; syncUrl(); load() }, 300) })
const columns = [{ key: 'reference_no', label: t('admin.opportunities.columns.ref'), sortable: true }, { key: 'name', label: t('admin.opportunities.columns.name'), sortable: true }, { key: 'status', label: t('admin.opportunities.columns.status') }, { key: 'owner_name', label: t('admin.opportunities.columns.owner') }, { key: 'assignee', label: t('admin.opportunities.columns.assignee') }, { key: 'sla_days_left', label: t('admin.opportunities.columns.sla'), sortable: true }, { key: 'updated_at', label: t('admin.opportunities.columns.updated'), sortable: true }]
</script>
<template>
  <div>
    <PageHeader :title="$t('admin.opportunities.title')" />
    <div class="card p-3 mb-4 grid sm:grid-cols-2 lg:grid-cols-5 gap-3 items-center">
      <input v-model="search" type="search" class="input" :placeholder="$t('common.searchPlaceholder')" :aria-label="$t('common.search')" />
      <select v-model="status" class="input" :aria-label="$t('admin.opportunities.filters.status')"><option value="">{{ $t('admin.opportunities.filters.status') }}: {{ $t('common.all') }}</option><option v-for="s in OPPORTUNITY_STATUSES" :key="s" :value="s">{{ label('opportunityStatus', s) }}</option></select>
      <select v-model="sector" class="input" :aria-label="$t('admin.opportunities.filters.sector')"><option value="">{{ $t('admin.opportunities.filters.sector') }}: {{ $t('common.all') }}</option><option v-for="s in tax.list('sectors')" :key="s.key" :value="s.key">{{ term(s) }}</option></select>
      <select v-model="assignee" class="input" :aria-label="$t('admin.opportunities.filters.assignee')"><option value="">{{ $t('admin.opportunities.filters.assignee') }}: {{ $t('common.all') }}</option><option value="none">{{ $t('admin.listFilters.unassigned') }}</option><option v-for="r in reviewers" :key="r.id" :value="String(r.id)">{{ r.name }}</option></select>
      <label class="inline-flex items-center gap-2 text-sm text-ink cursor-pointer"><input v-model="overdue" type="checkbox" class="h-4 w-4 rounded border-border accent-accent" />{{ $t('admin.opportunities.filters.overdue') }}</label>
    </div>
    <DataTable :columns="columns" :rows="rows" row-key="uuid" :loading="loading">
      <template #cell-reference_no="{ value }"><span class="num text-muted">{{ value ?? '—' }}</span></template>
      <template #cell-name="{ row }"><NuxtLink :to="$localePath(`/app/admin/opportunities/${row.uuid}`)" class="font-medium hover:text-blue">{{ row.name }}</NuxtLink><span class="block text-xs text-muted">{{ sectorName(row.sector) }}</span></template>
      <template #cell-status="{ value }"><AppBadge kind="opportunity" :value="value" /></template>
      <template #cell-assignee="{ value }">{{ value?.name ?? '—' }}</template>
      <template #cell-sla_days_left="{ row }"><span :class="row.is_overdue ? 'text-danger font-medium' : 'text-muted'">{{ row.sla_days_left == null ? '—' : row.is_overdue ? $t('common.overdueBy', { n: Math.abs(row.sla_days_left) }) : $t('common.daysLeft', { n: row.sla_days_left }) }}</span></template>
      <template #cell-updated_at="{ value }"><span class="text-muted whitespace-nowrap">{{ relative(value) }}</span></template>
      <template #footer><Pagination v-model:page="page" :last-page="meta.last_page" :total="meta.total" /></template>
    </DataTable>
  </div>
</template>

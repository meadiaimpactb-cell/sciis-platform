<script setup lang="ts">
import type { Paginated, QueueItem } from '~/types/api'
import { OPPORTUNITY_STATUSES } from '~/types/enums'
definePageMeta({ layout: 'portal', middleware: ['role'] })
const { t } = useI18n()
useHead({ title: t('admin.opportunities.metaTitle') })
const api = useApi()
const tax = useTaxonomyStore()
await tax.load().catch(() => {})
const route = useRoute()
const { label, term } = useEnumLabel()
const { relative } = useFormat()
const status = ref((route.query.status as string) ?? '')
const sector = ref('')
const overdue = ref(route.query.overdue === '1')
const search = ref('')
const page = ref(1)
const rows = ref<QueueItem[]>([])
const meta = ref({ total: 0, last_page: 1 })
const loading = ref(false)
const load = async () => {
  loading.value = true
  try {
    const q: Record<string, unknown> = { page: page.value, per_page: 20, search: search.value || undefined }
    if (status.value) q['filter[status]'] = status.value
    if (sector.value) q['filter[sector]'] = sector.value
    if (overdue.value) q['filter[overdue]'] = '1'
    const r = await api.get<Paginated<QueueItem>>('/admin/opportunities', q)
    rows.value = r.data; meta.value = { total: r.meta.total, last_page: r.meta.last_page }
  } finally { loading.value = false }
}
onMounted(load)
watch([status, sector, overdue], () => { page.value = 1; load() })
watch(page, load)
let st: ReturnType<typeof setTimeout>; watch(search, () => { clearTimeout(st); st = setTimeout(() => { page.value = 1; load() }, 300) })
const columns = [{ key: 'reference_no', label: t('admin.opportunities.columns.ref'), sortable: true }, { key: 'name', label: t('admin.opportunities.columns.name'), sortable: true }, { key: 'status', label: t('admin.opportunities.columns.status') }, { key: 'owner_name', label: t('admin.opportunities.columns.owner') }, { key: 'assignee', label: t('admin.opportunities.columns.assignee') }, { key: 'sla_days_left', label: t('admin.opportunities.columns.sla'), sortable: true }, { key: 'updated_at', label: t('admin.opportunities.columns.updated'), sortable: true }]
</script>
<template>
  <div>
    <PageHeader :title="$t('admin.opportunities.title')" />
    <div class="card p-3 mb-4 grid sm:grid-cols-4 gap-3">
      <input v-model="search" type="search" class="input" :placeholder="$t('common.searchPlaceholder')" :aria-label="$t('common.search')" />
      <select v-model="status" class="input" :aria-label="$t('admin.opportunities.filters.status')"><option value="">{{ $t('admin.opportunities.filters.status') }}: {{ $t('common.all') }}</option><option v-for="s in OPPORTUNITY_STATUSES" :key="s" :value="s">{{ label('opportunityStatus', s) }}</option></select>
      <select v-model="sector" class="input" :aria-label="$t('admin.opportunities.filters.sector')"><option value="">{{ $t('admin.opportunities.filters.sector') }}: {{ $t('common.all') }}</option><option v-for="s in tax.list('sectors')" :key="s.key" :value="s.key">{{ term(s) }}</option></select>
      <label class="inline-flex items-center gap-2 text-sm"><input v-model="overdue" type="checkbox" class="h-4 w-4 rounded border-border text-blue" />{{ $t('admin.opportunities.filters.overdue') }}</label>
    </div>
    <DataTable :columns="columns" :rows="rows" row-key="uuid" :loading="loading">
      <template #cell-reference_no="{ value }"><span class="num text-muted">{{ value }}</span></template>
      <template #cell-name="{ row }"><NuxtLink :to="$localePath(`/app/admin/opportunities/${row.uuid}`)" class="font-medium hover:text-blue">{{ row.name }}</NuxtLink><span class="block text-xs text-muted">{{ term(row.sector) }}</span></template>
      <template #cell-status="{ value }"><AppBadge kind="opportunity" :value="value" /></template>
      <template #cell-assignee="{ value }">{{ value?.name ?? '—' }}</template>
      <template #cell-sla_days_left="{ row }"><span :class="row.is_overdue ? 'text-danger font-medium' : 'text-muted'">{{ row.sla_days_left == null ? '—' : row.is_overdue ? $t('common.overdueBy', { n: Math.abs(row.sla_days_left) }) : $t('common.daysLeft', { n: row.sla_days_left }) }}</span></template>
      <template #cell-updated_at="{ value }"><span class="text-muted whitespace-nowrap">{{ relative(value) }}</span></template>
      <template #footer><Pagination v-model:page="page" :last-page="meta.last_page" :total="meta.total" /></template>
    </DataTable>
  </div>
</template>

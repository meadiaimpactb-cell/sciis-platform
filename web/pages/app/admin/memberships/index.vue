<script setup lang="ts">
/** ADM-06 membership queue + MAT-01 investor browser: search, status, investor type, sector, stage, region,
 *  investment type and ticket range (GET /admin/memberships, `filter[x]`), all kept in the URL query. */
import type { Membership, Paginated } from '~/types/api'
import { MEMBERSHIP_STATUSES } from '~/types/enums'
definePageMeta({ layout: 'portal', middleware: ['role'], roles: ['membership_officer'] })
const { t } = useI18n()
useHead({ title: t('admin.memberships.metaTitle') })
const api = useApi()
const route = useRoute()
const router = useRouter()
const tax = useTaxonomyStore()
await tax.load().catch(() => {})
const { label, term } = useEnumLabel()
const { date, number } = useFormat()
const KEYS = ['search', 'status', 'investor_type', 'sector', 'stage', 'region', 'type', 'range'] as const
type Key = (typeof KEYS)[number]
const q = (k: string) => (typeof route.query[k] === 'string' ? (route.query[k] as string) : '')
const f = reactive(Object.fromEntries(KEYS.map((k) => [k, q(k)])) as Record<Key, string>)
const page = ref(Number(q('page')) || 1)
const more = ref(KEYS.slice(3).some((k) => !!f[k]))
const rows = ref<Membership[]>([]); const meta = ref({ total: 0, last_page: 1 }); const loading = ref(false)
const query = () => Object.fromEntries(KEYS.filter((k) => f[k].trim()).map((k) => [`filter[${k}]`, f[k].trim()]))
const syncUrl = () => router.replace({ query: { ...Object.fromEntries(KEYS.filter((k) => f[k].trim()).map((k) => [k, f[k].trim()])), ...(page.value > 1 ? { page: String(page.value) } : {}) } })
const load = async () => { loading.value = true; try { const r = await api.get<Paginated<Membership>>('/admin/memberships', { page: page.value, ...query() }); rows.value = r.data; meta.value = { total: r.meta.total, last_page: r.meta.last_page } } finally { loading.value = false } }
onMounted(load)
watch(() => KEYS.filter((k) => k !== 'search').map((k) => f[k]), () => { page.value = 1; syncUrl(); load() })
watch(page, () => { syncUrl(); load() })
let st: ReturnType<typeof setTimeout>; watch(() => f.search, () => { clearTimeout(st); st = setTimeout(() => { page.value = 1; syncUrl(); load() }, 300) })
const active = computed(() => KEYS.filter((k) => !!f[k]).length)
const clear = () => { for (const k of KEYS) f[k] = '' }
const columns = [{ key: 'user', label: t('admin.memberships.columns.name') }, { key: 'type', label: t('admin.memberships.columns.type') }, { key: 'status', label: t('admin.memberships.columns.status') }, { key: 'submitted_at', label: t('admin.memberships.columns.submitted'), sortable: true }, { key: 'completion', label: t('admin.memberships.columns.completion') }, { key: 'actions', label: '' }]
/** ADM-06 — user-file link: `user_id` (staff) — UserResource itself carries `id` only for `users.manage`. */
const userId = (m: Membership) => m.user_id ?? (m.user as { id?: number } | undefined)?.id
</script>
<template>
  <div>
    <PageHeader :title="$t('admin.memberships.title')" :sub="$t('admin.memberships.sub')" />
    <div class="card p-3 mb-4 space-y-3">
      <div class="grid sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_auto] gap-3 items-center">
        <input v-model="f.search" type="search" class="input" :placeholder="$t('admin.listFilters.searchUsers')" :aria-label="$t('common.search')" />
        <select v-model="f.status" class="input" :aria-label="$t('common.status')"><option value="">{{ $t('common.status') }}: {{ $t('common.all') }}</option><option v-for="s in MEMBERSHIP_STATUSES" :key="s" :value="s">{{ label('membershipStatus', s) }}</option></select>
        <select v-model="f.investor_type" class="input" :aria-label="$t('admin.listFilters.investorType')"><option value="">{{ $t('admin.listFilters.investorType') }}: {{ $t('common.all') }}</option><option value="individual">{{ label('userType', 'investor_individual') }}</option><option value="institutional">{{ label('userType', 'investor_institutional') }}</option></select>
        <div class="flex gap-1 justify-end">
          <AppButton variant="ghost" size="sm" :aria-expanded="more" @click="more = !more">{{ $t('admin.listFilters.moreFilters') }}<span v-if="active" class="num ms-1 rounded-full bg-blue-soft text-blue px-1.5 text-[11px]">{{ active }}</span></AppButton>
          <AppButton v-if="active" variant="ghost" size="sm" @click="clear">{{ $t('common.reset') }}</AppButton>
        </div>
      </div>
      <div v-show="more" class="grid sm:grid-cols-2 lg:grid-cols-5 gap-3">
        <select v-model="f.sector" class="input" :aria-label="$t('admin.listFilters.sector')"><option value="">{{ $t('admin.listFilters.sector') }}: {{ $t('common.all') }}</option><option v-for="s in tax.list('sectors')" :key="s.key" :value="s.key">{{ term(s) }}</option></select>
        <select v-model="f.stage" class="input" :aria-label="$t('admin.listFilters.stage')"><option value="">{{ $t('admin.listFilters.stage') }}: {{ $t('common.all') }}</option><option v-for="s in tax.list('project_stages')" :key="s.key" :value="s.key">{{ label('stage', s.key) }}</option></select>
        <select v-model="f.region" class="input" :aria-label="$t('admin.listFilters.region')"><option value="">{{ $t('admin.listFilters.region') }}: {{ $t('common.all') }}</option><option v-for="s in tax.list('regions')" :key="s.key" :value="s.key">{{ term(s) }}</option></select>
        <select v-model="f.type" class="input" :aria-label="$t('admin.listFilters.investmentType')"><option value="">{{ $t('admin.listFilters.investmentType') }}: {{ $t('common.all') }}</option><option v-for="s in tax.list('investment_types')" :key="s.key" :value="s.key">{{ label('investmentType', s.key) }}</option></select>
        <select v-model="f.range" class="input" :aria-label="$t('admin.listFilters.ticket')"><option value="">{{ $t('admin.listFilters.ticket') }}: {{ $t('common.all') }}</option><option v-for="s in tax.list('investment_ranges')" :key="s.key" :value="s.key">{{ term(s) }}</option></select>
      </div>
    </div>
    <DataTable :columns="columns" :rows="rows" :loading="loading">
      <template #cell-user="{ row }"><NuxtLink v-if="userId(row)" :to="$localePath(`/app/admin/users/${userId(row)}`)" class="font-medium hover:text-blue">{{ row.user?.name }}</NuxtLink><span v-else class="font-medium">{{ row.user?.name }}</span><span class="block text-xs text-muted ltr text-start">{{ row.user?.email }}</span></template>
      <template #cell-type="{ row }">{{ label('userType', row.user?.type) }}</template>
      <template #cell-status="{ value }"><AppBadge kind="membership" :value="value" /></template>
      <template #cell-submitted_at="{ value }"><span class="num">{{ date(value) }}</span></template>
      <template #cell-completion="{ row }"><span class="num">{{ number(row.profile?.completion_percent) }}%</span></template>
      <template #cell-actions="{ row }"><AppButton size="sm" variant="outline" :to="$localePath(`/app/admin/memberships/${row.id}`)">{{ $t('common.view') }}</AppButton></template>
      <template #footer><Pagination v-model:page="page" :last-page="meta.last_page" :total="meta.total" /></template>
    </DataTable>
  </div>
</template>

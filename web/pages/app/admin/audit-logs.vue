<script setup lang="ts">
/** AUD-03 — audit log browser (`filter[user|action|type|from|to]`; user = id, uuid or email, #31) + xlsx export
 *  honouring the same filters. Filters live in the URL query (the user file links here with `?user=`).
 *  ACC-04 `access.denied` and AUD-01 `*.viewed` rows are rendered readably (AuditAction). */
import type { AuditLog, Paginated } from '~/types/api'
import { toastApiError, toastInfo } from '~/utils/notify'
definePageMeta({ layout: 'portal', middleware: ['role'], roles: [] })
const { t } = useI18n()
useHead({ title: t('admin.audit.metaTitle') })
const api = useApi()
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const { dateTime } = useFormat()
const KEYS = ['user', 'action', 'type', 'from', 'to'] as const
const qv = (k: string) => (typeof route.query[k] === 'string' ? (route.query[k] as string) : '')
const f = reactive(Object.fromEntries(KEYS.map((k) => [k, qv(k)])) as Record<(typeof KEYS)[number], string>)
const page = ref(Number(qv('page')) || 1)
const rows = ref<AuditLog[]>([]); const meta = ref({ total: 0, last_page: 1 }); const loading = ref(false)
const rangeError = computed(() => !!(f.from && f.to && f.from > f.to))
const filterQuery = () => Object.fromEntries(KEYS.filter((k) => f[k].trim() && !(rangeError.value && (k === 'from' || k === 'to'))).map((k) => [`filter[${k}]`, f[k].trim()])) as Record<string, string>
const syncUrl = () => router.replace({ query: { ...Object.fromEntries(KEYS.filter((k) => f[k].trim()).map((k) => [k, f[k].trim()])), ...(page.value > 1 ? { page: String(page.value) } : {}) } })
const load = async () => { loading.value = true; try { const r = await api.get<Paginated<AuditLog>>('/admin/audit-logs', { page: page.value, per_page: 25, ...filterQuery() }); rows.value = r.data ?? []; meta.value = { total: r.meta?.total ?? rows.value.length, last_page: r.meta?.last_page ?? 1 } } catch (e) { toastApiError(e) } finally { loading.value = false } }
onMounted(load); watch(page, () => { syncUrl(); load() })
let st: ReturnType<typeof setTimeout>; watch(f, () => { clearTimeout(st); st = setTimeout(() => { page.value = 1; syncUrl(); load() }, 300) })
const hasFilters = computed(() => KEYS.some((k) => !!f[k]))
const clear = () => { for (const k of KEYS) f[k] = '' }
/** Quick presets for the action prefix filter (the API matches `action LIKE 'x%'`). */
const ACTION_PRESETS = ['access.denied', 'auth.', 'opportunity.', 'membership.', 'user.', 'document.', 'evaluation.', 'nomination.', 'interest.', 'report.', 'role.', 'setting.', 'privacy.']
const expanded = ref<string | null>(null)
const exportXlsx = async () => {
  const path = '/admin/audit-logs/export.xlsx'
  try {
    if (api.mock) { const r = await api.get<{ url: string; note?: string }>(path, filterQuery()); toastInfo(r.note ?? r.url); return }
    const qs = new URLSearchParams(filterQuery()).toString()
    window.open(`${api.baseURL}${path}${qs ? `?${qs}` : ''}`, '_blank', 'noopener'); toastInfo(t('notify.preparing'))
  } catch (e) { toastApiError(e) }
}
const columns = [{ key: 'created_at', label: t('common.date'), sortable: true }, { key: 'user', label: t('admin.audit.columns.user') }, { key: 'action', label: t('admin.audit.filters.action') }, { key: 'auditable', label: t('admin.audit.filters.type') }, { key: 'ip', label: t('admin.audit.columns.ip') }, { key: 'diff', label: `${t('admin.audit.before')} / ${t('admin.audit.after')}` }]
</script>
<template>
  <div>
    <PageHeader :title="$t('admin.audit.title')" :sub="$t('admin.audit.sub')"><template #actions><AppButton variant="secondary" size="sm" :disabled="rangeError" @click="exportXlsx">{{ $t('admin.audit.export') }}</AppButton></template></PageHeader>
    <div class="card p-3 mb-4 space-y-2">
      <div class="grid sm:grid-cols-2 lg:grid-cols-[1.4fr_1.2fr_1fr_1fr_1fr_auto] gap-3 items-end">
        <label class="block"><span class="text-xs text-muted">{{ $t('admin.audit.filters.user') }}</span><input v-model="f.user" class="input mt-1" :placeholder="$t('admin.audit.filters.user')" /></label>
        <label class="block"><span class="text-xs text-muted">{{ $t('admin.audit.filters.action') }}</span><input v-model="f.action" class="input ltr mt-1" list="audit-action-presets" :placeholder="$t('admin.audit.actionHint')" /><datalist id="audit-action-presets"><option v-for="a in ACTION_PRESETS" :key="a" :value="a" /></datalist></label>
        <label class="block"><span class="text-xs text-muted">{{ $t('admin.audit.filters.type') }}</span><input v-model="f.type" class="input ltr mt-1" :placeholder="$t('admin.audit.typeHint')" /></label>
        <label class="block"><span class="text-xs text-muted">{{ $t('admin.audit.filters.from') }}</span><input v-model="f.from" type="date" class="input ltr num mt-1" :max="f.to || undefined" :aria-invalid="rangeError" /></label>
        <label class="block"><span class="text-xs text-muted">{{ $t('admin.audit.filters.to') }}</span><input v-model="f.to" type="date" class="input ltr num mt-1" :min="f.from || undefined" :aria-invalid="rangeError" /></label>
        <AppButton v-if="hasFilters" variant="ghost" size="sm" @click="clear">{{ $t('common.reset') }}</AppButton>
      </div>
      <p v-if="rangeError" class="error-text" role="alert">{{ $t('admin.audit.rangeError') }}</p>
      <div class="flex flex-wrap gap-1.5"><button type="button" class="chip text-xs" :class="f.action === 'access.denied' && 'chip-active'" :aria-pressed="f.action === 'access.denied'" @click="f.action = f.action === 'access.denied' ? '' : 'access.denied'">{{ $t('admin.audit.actions.access_denied') }}</button></div>
    </div>
    <DataTable :columns="columns" :rows="rows" row-key="uuid" :loading="loading" dense>
      <template #cell-created_at="{ value }"><span class="whitespace-nowrap num">{{ dateTime(value) }}</span></template>
      <template #cell-user="{ value }"><NuxtLink v-if="value?.id && auth.canAny('users.manage')" :to="$localePath(`/app/admin/users/${value.id}`)" class="hover:text-blue">{{ value.name }}</NuxtLink><span v-else>{{ value?.name ?? $t('admin.audit.system') }}</span></template>
      <template #cell-action="{ row }"><AuditAction :action="row.action" :after="row.after" /></template>
      <template #cell-auditable="{ row }"><span class="ltr inline-block text-xs">{{ row.auditable_type ?? '—' }}<template v-if="row.auditable_id">#{{ row.auditable_id }}</template></span></template>
      <template #cell-ip="{ value }"><span class="ltr num inline-block text-xs whitespace-nowrap">{{ value || '—' }}</span></template>
      <template #cell-diff="{ row }"><button v-if="row.before || row.after" type="button" class="link text-xs" @click="expanded = expanded === row.uuid ? null : row.uuid">{{ expanded === row.uuid ? $t('common.showLess') : $t('common.showMore') }}</button><pre v-if="expanded === row.uuid" class="mt-1 text-[11px] ltr text-start bg-bg rounded p-2 overflow-auto max-w-xs">{{ JSON.stringify({ before: row.before, after: row.after }, null, 1) }}</pre><span v-else-if="!row.before && !row.after">—</span></template>
      <template #footer><Pagination v-model:page="page" :last-page="meta.last_page" :total="meta.total" /></template>
    </DataTable>
  </div>
</template>

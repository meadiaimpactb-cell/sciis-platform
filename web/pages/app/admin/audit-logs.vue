<script setup lang="ts">
import type { AuditLog, Paginated } from '~/types/api'
definePageMeta({ layout: 'portal', middleware: ['role'], roles: [] })
const { t } = useI18n()
useHead({ title: t('admin.audit.metaTitle') })
const api = useApi()
const { dateTime } = useFormat()
const f = reactive({ user: '', action: '', type: '' })
const page = ref(1)
const rows = ref<AuditLog[]>([]); const meta = ref({ total: 0, last_page: 1 }); const loading = ref(false)
const load = async () => { loading.value = true; try { const r = await api.get<Paginated<AuditLog>>('/admin/audit-logs', { page: page.value, per_page: 25, 'filter[user]': f.user || undefined, 'filter[action]': f.action || undefined, 'filter[type]': f.type || undefined }); rows.value = r.data; meta.value = { total: r.meta.total, last_page: r.meta.last_page } } finally { loading.value = false } }
onMounted(load); watch(page, load)
let st: ReturnType<typeof setTimeout>; watch(f, () => { clearTimeout(st); st = setTimeout(() => { page.value = 1; load() }, 300) })
const expanded = ref<string | null>(null)
const columns = [{ key: 'created_at', label: t('common.date'), sortable: true }, { key: 'user', label: t('admin.audit.filters.user') }, { key: 'action', label: t('admin.audit.filters.action') }, { key: 'auditable', label: t('admin.audit.filters.type') }, { key: 'diff', label: `${t('admin.audit.before')} / ${t('admin.audit.after')}` }]
</script>
<template>
  <div>
    <PageHeader :title="$t('admin.audit.title')" :sub="$t('admin.audit.sub')" />
    <div class="card p-3 mb-4 grid sm:grid-cols-3 gap-3"><input v-model="f.user" class="input" :placeholder="$t('admin.audit.filters.user')" :aria-label="$t('admin.audit.filters.user')" /><input v-model="f.action" class="input ltr" :placeholder="$t('admin.audit.filters.action')" :aria-label="$t('admin.audit.filters.action')" /><input v-model="f.type" class="input ltr" :placeholder="$t('admin.audit.filters.type')" :aria-label="$t('admin.audit.filters.type')" /></div>
    <DataTable :columns="columns" :rows="rows" row-key="uuid" :loading="loading" dense>
      <template #cell-created_at="{ value }"><span class="whitespace-nowrap num">{{ dateTime(value) }}</span></template>
      <template #cell-user="{ value }">{{ value?.name ?? $t('admin.audit.system') }}</template>
      <template #cell-action="{ value }"><code class="text-xs ltr inline-block rounded bg-bg px-1.5 py-0.5">{{ value }}</code></template>
      <template #cell-auditable="{ row }"><span class="ltr inline-block text-xs">{{ row.auditable_type }}#{{ row.auditable_id }}</span></template>
      <template #cell-diff="{ row }"><button v-if="row.before || row.after" type="button" class="link text-xs" @click="expanded = expanded === row.uuid ? null : row.uuid">{{ expanded === row.uuid ? $t('common.showLess') : $t('common.showMore') }}</button><pre v-if="expanded === row.uuid" class="mt-1 text-[11px] ltr text-start bg-bg rounded p-2 overflow-auto max-w-xs">{{ JSON.stringify({ before: row.before, after: row.after }, null, 1) }}</pre><span v-else-if="!row.before && !row.after">—</span></template>
      <template #footer><Pagination v-model:page="page" :last-page="meta.last_page" :total="meta.total" /></template>
    </DataTable>
  </div>
</template>

<script setup lang="ts">
import type { Membership, Paginated } from '~/types/api'
import { MEMBERSHIP_STATUSES } from '~/types/enums'
definePageMeta({ layout: 'portal', middleware: ['role'], roles: ['membership_officer'] })
const { t } = useI18n()
useHead({ title: t('admin.memberships.metaTitle') })
const api = useApi()
const { label } = useEnumLabel()
const { date, number } = useFormat()
const status = ref('')
const page = ref(1)
const rows = ref<Membership[]>([]); const meta = ref({ total: 0, last_page: 1 }); const loading = ref(false)
const load = async () => { loading.value = true; try { const r = await api.get<Paginated<Membership>>('/admin/memberships', { page: page.value, 'filter[status]': status.value || undefined }); rows.value = r.data; meta.value = { total: r.meta.total, last_page: r.meta.last_page } } finally { loading.value = false } }
onMounted(load); watch([status, page], load)
const columns = [{ key: 'user', label: t('admin.memberships.columns.name') }, { key: 'type', label: t('admin.memberships.columns.type') }, { key: 'status', label: t('admin.memberships.columns.status') }, { key: 'submitted_at', label: t('admin.memberships.columns.submitted'), sortable: true }, { key: 'completion', label: t('admin.memberships.columns.completion') }, { key: 'actions', label: '' }]
</script>
<template>
  <div>
    <PageHeader :title="$t('admin.memberships.title')" :sub="$t('admin.memberships.sub')" />
    <div class="card p-3 mb-4 flex gap-3"><select v-model="status" class="input w-auto" :aria-label="$t('common.status')"><option value="">{{ $t('common.status') }}: {{ $t('common.all') }}</option><option v-for="s in MEMBERSHIP_STATUSES" :key="s" :value="s">{{ label('membershipStatus', s) }}</option></select></div>
    <DataTable :columns="columns" :rows="rows" :loading="loading">
      <template #cell-user="{ row }"><span class="font-medium">{{ row.user?.name }}</span><span class="block text-xs text-muted ltr text-start">{{ row.user?.email }}</span></template>
      <template #cell-type="{ row }">{{ label('userType', row.user?.type) }}</template>
      <template #cell-status="{ value }"><AppBadge kind="membership" :value="value" /></template>
      <template #cell-submitted_at="{ value }">{{ date(value) }}</template>
      <template #cell-completion="{ row }"><span class="num">{{ number(row.profile?.completion_percent) }}%</span></template>
      <template #cell-actions="{ row }"><AppButton size="sm" variant="outline" :to="`/app/admin/memberships/${row.id}`">{{ $t('common.view') }}</AppButton></template>
      <template #footer><Pagination v-model:page="page" :last-page="meta.last_page" :total="meta.total" /></template>
    </DataTable>
  </div>
</template>

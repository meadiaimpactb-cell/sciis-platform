<script setup lang="ts">
import type { Interest, Paginated } from '~/types/api'
import type { InterestStatus } from '~/types/enums'
definePageMeta({ layout: 'portal', middleware: ['role'], roles: ['matching_officer'] })
const { t } = useI18n()
useHead({ title: t('admin.interests.metaTitle') })
const api = useApi()
const toast = useToast()
const { label } = useEnumLabel()
const { date } = useFormat()
type Row = Interest & { allowed_transitions: InterestStatus[] }
const { data, loading, refresh } = useAsyncResource(() => api.get<Paginated<Row>>('/admin/interests', { per_page: 50 }).then((r) => r.data))
const busy = ref<number | null>(null)
const transition = async (i: Row, to: InterestStatus) => { busy.value = i.id; try { await api.post(`/admin/interests/${i.id}/transition`, { to }); toast.success(t('common.success')); await refresh() } catch (e) { toast.error((e as Error).message) } finally { busy.value = null } }
const columns = [{ key: 'investor', label: t('admin.interests.investor') }, { key: 'opportunity', label: t('admin.interests.opportunity') }, { key: 'status', label: t('common.status') }, { key: 'owner_decision', label: t('admin.interests.ownerDecision') }, { key: 'created_at', label: t('common.createdAt'), sortable: true }, { key: 'transition', label: t('admin.interests.transition') }]
</script>
<template>
  <div>
    <PageHeader :title="$t('admin.interests.title')" :sub="$t('admin.interests.sub')" />
    <DataTable :columns="columns" :rows="data ?? []" :loading="loading">
      <template #cell-investor="{ row }"><span class="font-medium">{{ row.investor?.name }}</span></template>
      <template #cell-opportunity="{ row }"><NuxtLink :to="$localePath(`/app/admin/opportunities/${row.opportunity.uuid}`)" class="hover:text-blue">{{ row.opportunity.name }}</NuxtLink></template>
      <template #cell-status="{ value }"><AppBadge kind="interest" :value="value" /></template>
      <template #cell-owner_decision="{ value }">{{ value ? label('ownerDecision', value) : '—' }}</template>
      <template #cell-created_at="{ value }">{{ date(value) }}</template>
      <template #cell-transition="{ row }"><div class="flex flex-wrap gap-1"><AppButton v-for="s in row.allowed_transitions" :key="s" size="sm" :variant="s === 'closed' ? 'ghost' : 'outline'" :loading="busy === row.id" @click="transition(row, s)">{{ label('interestStatus', s) }}</AppButton></div></template>
    </DataTable>
  </div>
</template>

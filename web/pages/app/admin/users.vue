<script setup lang="ts">
import type { Paginated, StaffUser } from '~/types/api'
import { STAFF_ROLES, type StaffRole } from '~/types/enums'
definePageMeta({ layout: 'portal', middleware: ['role'], roles: [] })
const { t } = useI18n()
useHead({ title: t('admin.users.metaTitle') })
const api = useApi()
const toast = useToast()
const { label } = useEnumLabel()
const { date } = useFormat()
const typeFilter = ref('staff')
const { data, loading, refresh } = useAsyncResource(() => api.get<Paginated<StaffUser>>('/admin/users', { per_page: 100, 'filter[type]': typeFilter.value || undefined }).then((r) => r.data))
watch(typeFilter, refresh)
const open = ref(false); const editing = ref<StaffUser | null>(null)
const form = reactive({ name: '', email: '', roles: [] as StaffRole[] })
const busy = ref(false)
const openNew = () => { editing.value = null; form.name = ''; form.email = ''; form.roles = []; open.value = true }
const openEdit = (u: StaffUser) => { editing.value = u; form.name = u.name; form.email = u.email; form.roles = [...u.roles]; open.value = true }
const save = async () => { busy.value = true; try { if (editing.value) await api.patch(`/admin/users/${editing.value.id}`, { roles: form.roles }); else await api.post('/admin/users', form); open.value = false; toast.success(t('common.success')); await refresh() } catch (e) { toast.error((e as Error).message) } finally { busy.value = false } }
const toggleStatus = async (u: StaffUser) => { await api.patch(`/admin/users/${u.id}`, { status: u.status === 'active' ? 'suspended' : 'active' }); await refresh() }
const columns = [{ key: 'name', label: t('common.name'), sortable: true }, { key: 'type', label: t('admin.memberships.columns.type') }, { key: 'roles', label: t('admin.users.roles') }, { key: 'two_factor_enabled', label: t('admin.users.twoFactor') }, { key: 'status', label: t('common.status') }, { key: 'last_login_at', label: t('admin.users.lastLogin'), sortable: true }, { key: 'actions', label: '' }]
const roleOpts = STAFF_ROLES.map((r) => ({ value: r, label: label('role', r) }))
</script>
<template>
  <div>
    <PageHeader :title="$t('admin.users.title')" :sub="$t('admin.users.sub')"><template #actions><AppButton @click="openNew">{{ $t('admin.users.addStaff') }}</AppButton></template></PageHeader>
    <div class="card p-3 mb-4"><select v-model="typeFilter" class="input w-auto" :aria-label="$t('admin.memberships.columns.type')"><option value="">{{ $t('common.all') }}</option><option value="staff">{{ $t('admin.users.staffOnly') }}</option><option value="investor_individual">{{ label('userType', 'investor_individual') }}</option><option value="investor_institutional">{{ label('userType', 'investor_institutional') }}</option><option value="entrepreneur_individual">{{ label('userType', 'entrepreneur_individual') }}</option><option value="entrepreneur_entity">{{ label('userType', 'entrepreneur_entity') }}</option></select></div>
    <DataTable :columns="columns" :rows="data ?? []" :loading="loading">
      <template #cell-name="{ row }"><span class="font-medium">{{ row.name }}</span><span class="block text-xs text-muted ltr text-start">{{ row.email }}</span></template>
      <template #cell-type="{ value }">{{ label('userType', value) }}</template>
      <template #cell-roles="{ value }"><div class="flex flex-wrap gap-1"><span v-for="r in value" :key="r" class="text-xs rounded-full bg-bg px-2 py-0.5">{{ label('role', r) }}</span></div></template>
      <template #cell-two_factor_enabled="{ value }"><AppBadge kind="generic" :value="value ? 'active' : 'suspended'" :label="value ? $t('common.yes') : $t('common.no')" /></template>
      <template #cell-status="{ value }"><AppBadge kind="generic" :value="value" :label="value === 'active' ? $t('admin.users.activate') : $t('admin.users.suspend')" /></template>
      <template #cell-last_login_at="{ value }">{{ date(value) }}</template>
      <template #cell-actions="{ row }"><div class="flex gap-1 justify-end"><AppButton v-if="row.type === 'staff'" size="sm" variant="outline" @click="openEdit(row)">{{ $t('common.edit') }}</AppButton><AppButton size="sm" variant="ghost" @click="toggleStatus(row)">{{ row.status === 'active' ? $t('admin.users.suspend') : $t('admin.users.activate') }}</AppButton></div></template>
    </DataTable>
    <Modal :open="open" :title="editing ? $t('common.edit') : $t('admin.users.addStaff')" @close="open = false">
      <div class="space-y-4">
        <FormField v-model="form.name" name="name" :label="$t('common.name')" :disabled="!!editing" required />
        <FormField v-model="form.email" name="email" type="email" :label="$t('common.email')" :disabled="!!editing" required ltr />
        <FormField v-model="form.roles" name="roles" type="chips" :label="$t('admin.users.roles')" :options="roleOpts" />
      </div>
      <template #footer><AppButton variant="ghost" @click="open = false">{{ $t('common.cancel') }}</AppButton><AppButton :loading="busy" @click="save">{{ $t('common.save') }}</AppButton></template>
    </Modal>
  </div>
</template>

<script setup lang="ts">
/** ACC-06 users (create without password → reset link emailed, #26) + ACC-03 roles & permissions tab
 *  + ADM-07 read-only approval matrix. MAT-01: search (debounced) / type / role / status filters kept in the URL query. */
import { z } from 'zod'
import type { ApprovalMatrixRow, Paginated, StaffUser, RoleItem } from '~/types/api'
import { STAFF_ROLES } from '~/types/enums'
import { emailField, normalizePhone, optionalPhoneField, roleNameField, staffNameField, vmsg } from '~/utils/validation'
import { confirmDialog, toastApiError, toastSuccess } from '~/utils/notify'
definePageMeta({ layout: 'portal', middleware: ['role'], roles: [] })
const { t } = useI18n()
useHead({ title: t('admin.users.metaTitle') })
const api = useApi()
const route = useRoute()
const router = useRouter()
const { label } = useEnumLabel()
const { date, number } = useFormat()
const q = (k: string) => (typeof route.query[k] === 'string' ? (route.query[k] as string) : '')
const TABS = ['users', 'roles', 'matrix'] as const
const tab = ref<string>(TABS.includes(q('tab') as (typeof TABS)[number]) ? q('tab') : 'users')
const tabs = [{ key: 'users', label: t('admin.users.tabs.users') }, { key: 'roles', label: t('admin.users.tabs.roles') }, { key: 'matrix', label: t('admin.matrix.tab') }]

// ---- list filters (URL: ?search=&type=&role=&status=&page=; type defaults to staff, `all` = every type) ----
const f = reactive({ search: q('search'), type: route.query.type === undefined ? 'staff' : q('type') === 'all' ? '' : q('type'), role: q('role'), status: q('status') })
const page = ref(Number(q('page')) || 1)
const rows = ref<StaffUser[]>([]); const meta = ref({ total: 0, last_page: 1 }); const loading = ref(false)
const syncUrl = () => {
  const query: Record<string, string> = {}
  if (tab.value !== 'users') query.tab = tab.value
  if (f.search.trim()) query.search = f.search.trim()
  if (f.type !== 'staff') query.type = f.type || 'all'
  if (f.role) query.role = f.role
  if (f.status) query.status = f.status
  if (page.value > 1) query.page = String(page.value)
  router.replace({ query })
}
const load = async () => {
  loading.value = true
  try {
    const r = await api.get<Paginated<StaffUser>>('/admin/users', { page: page.value, per_page: 25, 'filter[search]': f.search.trim() || undefined, 'filter[type]': f.type || undefined, 'filter[role]': f.role || undefined, 'filter[status]': f.status || undefined })
    rows.value = r.data ?? []
    meta.value = { total: r.meta?.total ?? rows.value.length, last_page: r.meta?.last_page ?? 1 }
  } catch (e) { toastApiError(e) } finally { loading.value = false }
}
const refresh = load
onMounted(load)
watch(() => [f.type, f.role, f.status], () => { page.value = 1; syncUrl(); load() })
watch(page, () => { syncUrl(); load() })
let st: ReturnType<typeof setTimeout>; watch(() => f.search, () => { clearTimeout(st); st = setTimeout(() => { page.value = 1; syncUrl(); load() }, 300) })
watch(tab, syncUrl)
const hasFilters = computed(() => !!(f.search || f.role || f.status || f.type !== 'staff'))
const clearFilters = () => { f.search = ''; f.type = 'staff'; f.role = ''; f.status = '' }

// ---- staff user (create: name/email/phone/roles — StoreStaffUserRequest; edit: roles only — UpdateUserRequest) ----
const open = ref(false); const editing = ref<StaffUser | null>(null)
const userForm = useZodForm(
  z.object({ is_new: z.boolean(), name: staffNameField, email: emailField, phone: optionalPhoneField, roles: z.array(z.string()) })
    .refine((v) => !v.is_new || v.roles.length > 0, { path: ['roles'], error: () => vmsg('selectOne') }),
  { is_new: true, name: '', email: '', phone: '', roles: [] as string[] },
)
const openNew = () => { editing.value = null; userForm.reset(); open.value = true }
const openEdit = (u: StaffUser) => { editing.value = u; userForm.reset({ is_new: false, name: u.name, email: u.email, phone: '', roles: [...u.roles] }); open.value = true }
const save = () => userForm.submit(async (v) => {
  if (editing.value) {
    await api.patch(`/admin/users/${editing.value.id}`, { roles: v.roles })
    toastSuccess(t('notify.updated', { item: t('notify.entities.user') }))
  } else {
    const phone = normalizePhone(v.phone ?? '')
    const r = await api.post<{ data: StaffUser; password_reset_sent?: boolean; message?: string | null }>('/admin/users', { name: v.name.trim(), email: v.email.trim().toLowerCase(), phone: phone || undefined, roles: v.roles })
    toastSuccess(r.password_reset_sent ? t('admin.users.resetLinkSent', { email: v.email.trim() }) : t('notify.created', { item: t('notify.entities.user') }))
  }
  open.value = false
  await refresh()
})
/** Suspension blocks the sign-in → confirmation with the user's name; re-activation is immediate. */
const toggleStatus = async (u: StaffUser) => {
  const suspend = u.status === 'active'
  if (suspend && !(await confirmDialog(t('notify.suspendTitle'), t('notify.suspendText', { name: u.name }), t('notify.suspendConfirm')))) return
  try {
    await api.patch(`/admin/users/${u.id}`, { status: suspend ? 'suspended' : 'active' })
    toastSuccess(t(suspend ? 'admin.users.suspended' : 'admin.users.activated', { name: u.name }))
    await refresh()
  } catch (e) { toastApiError(e) }
}
const columns = [{ key: 'name', label: t('common.name'), sortable: true }, { key: 'type', label: t('admin.memberships.columns.type') }, { key: 'roles', label: t('admin.users.roles') }, { key: 'two_factor_enabled', label: t('admin.users.twoFactor') }, { key: 'status', label: t('common.status') }, { key: 'last_login_at', label: t('admin.users.lastLogin'), sortable: true }, { key: 'actions', label: '' }]

// ---- roles tab (ACC-03 — RoleRequest: name ^[a-z][a-z0-9_]*$ 3..60, permissions required on create) ----
const { data: rolesData, refresh: refreshRoles } = useAsyncResource(() => api.get<{ data: { roles: RoleItem[]; permissions: string[] } }>('/admin/roles').then((r) => r.data))
const roleNames = computed<string[]>(() => rolesData.value?.roles.map((r) => r.name) ?? [...STAFF_ROLES])
const roleOpts = computed(() => roleNames.value.map((r) => ({ value: r, label: roleLabel(r) })))
const roleEditing = ref<RoleItem | null>(null); const roleOpen = ref(false)
const roleForm = useZodForm(
  z.object({ role_is_new: z.boolean(), role_name: roleNameField, permissions: z.array(z.string()) })
    .refine((v) => !v.role_is_new || v.permissions.length > 0, { path: ['permissions'], error: () => vmsg('selectOne') }),
  { role_is_new: true, role_name: '', permissions: [] as string[] },
  { aliases: { name: 'role_name' } },
)
const openRole = (r?: RoleItem) => { roleEditing.value = r ?? null; roleForm.reset({ role_is_new: !r, role_name: r?.name ?? '', permissions: [...(r?.permissions ?? [])] }); roleOpen.value = true }
const togglePerm = (p: string) => {
  const list = roleForm.values.permissions
  roleForm.values.permissions = list.includes(p) ? list.filter((x) => x !== p) : [...list, p]
}
const saveRole = () => roleForm.submit(async (v) => {
  if (roleEditing.value) await api.patch(`/admin/roles/${roleEditing.value.id}`, { permissions: v.permissions })
  else await api.post('/admin/roles', { name: v.role_name.trim(), permissions: v.permissions })
  toastSuccess(t(roleEditing.value ? 'notify.updated' : 'notify.created', { item: t('notify.entities.role') }))
  roleOpen.value = false
  await refreshRoles()
  matrix.value = null // derived from the role permissions → reload on next view
})
const roleLabel = (name: string) => { const l = label('role', name); return l === name ? name : l }

// ---- ADM-07 approval matrix (read-only, derived from the live role permissions) ----
type Matrix = { decisions: ApprovalMatrixRow[]; roles: { name: string; is_system: boolean }[] }
const matrix = ref<Matrix | null>(null); const matrixLoading = ref(false)
const loadMatrix = async () => {
  matrixLoading.value = true
  try { matrix.value = (await api.get<{ data: Matrix }>('/admin/roles/approval-matrix')).data } catch (e) { toastApiError(e) } finally { matrixLoading.value = false }
}
watch(tab, (v) => { if (v === 'matrix' && !matrix.value) loadMatrix() }, { immediate: true })
const AREAS = ['membership', 'opportunity', 'evaluation', 'matching', 'execution', 'platform']
const matrixAreas = computed(() => AREAS.map((a) => ({ area: a, rows: (matrix.value?.decisions ?? []).filter((d) => d.area === a) })).filter((g) => g.rows.length))
const cell = (row: ApprovalMatrixRow, role: string): 'yes' | 'excluded' | 'no' => (row.roles.some((r) => r.name === role) ? 'yes' : row.excluded_roles?.some((r) => r.name === role) ? 'excluded' : 'no')
</script>
<template>
  <div>
    <PageHeader :title="$t('admin.users.title')" :sub="$t('admin.users.sub')"><template #actions><AppButton v-if="tab === 'users'" @click="openNew">{{ $t('admin.users.addStaff') }}</AppButton><AppButton v-else-if="tab === 'roles'" @click="openRole()">{{ $t('admin.users.addRole') }}</AppButton></template></PageHeader>
    <Tabs v-model="tab" :tabs="tabs" variant="pills" class="w-fit mb-4" />

    <template v-if="tab === 'users'">
      <div class="card p-3 mb-4 grid sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1fr_auto] gap-3 items-center">
        <input v-model="f.search" type="search" class="input" :placeholder="$t('admin.listFilters.searchUsers')" :aria-label="$t('common.search')" />
        <select v-model="f.type" class="input" :aria-label="$t('admin.memberships.columns.type')"><option value="">{{ $t('admin.memberships.columns.type') }}: {{ $t('common.all') }}</option><option value="staff">{{ $t('admin.users.staffOnly') }}</option><option value="investor_individual">{{ label('userType', 'investor_individual') }}</option><option value="investor_institutional">{{ label('userType', 'investor_institutional') }}</option><option value="entrepreneur_individual">{{ label('userType', 'entrepreneur_individual') }}</option><option value="entrepreneur_entity">{{ label('userType', 'entrepreneur_entity') }}</option></select>
        <select v-model="f.role" class="input" :aria-label="$t('admin.users.roles')"><option value="">{{ $t('admin.listFilters.role') }}: {{ $t('common.all') }}</option><option v-for="r in roleOpts" :key="r.value" :value="r.value">{{ r.label }}</option></select>
        <select v-model="f.status" class="input" :aria-label="$t('common.status')"><option value="">{{ $t('common.status') }}: {{ $t('common.all') }}</option><option value="active">{{ $t('admin.listFilters.active') }}</option><option value="suspended">{{ $t('admin.listFilters.suspended') }}</option></select>
        <AppButton v-if="hasFilters" variant="ghost" size="sm" @click="clearFilters">{{ $t('common.reset') }}</AppButton>
      </div>
      <DataTable :columns="columns" :rows="rows" :loading="loading">
        <template #cell-name="{ row }"><NuxtLink :to="$localePath(`/app/admin/users/${row.id}`)" class="font-medium hover:text-blue">{{ row.name }}</NuxtLink><span class="block text-xs text-muted ltr text-start">{{ row.email }}</span></template>
        <template #cell-type="{ value }">{{ label('userType', value) }}</template>
        <template #cell-roles="{ value }"><div class="flex flex-wrap gap-1"><span v-for="r in value ?? []" :key="r" class="text-xs rounded-full bg-bg px-2 py-0.5">{{ roleLabel(r) }}</span></div></template>
        <template #cell-two_factor_enabled="{ value }"><AppBadge kind="generic" :value="value ? 'active' : 'suspended'" :label="value ? $t('common.yes') : $t('common.no')" /></template>
        <template #cell-status="{ value }"><AppBadge kind="generic" :value="value" :label="value === 'active' ? $t('admin.listFilters.active') : $t('admin.listFilters.suspended')" /></template>
        <template #cell-last_login_at="{ value }"><span class="num">{{ date(value) }}</span></template>
        <template #cell-actions="{ row }"><div class="flex gap-1 justify-end"><AppButton size="sm" variant="ghost" :to="$localePath(`/app/admin/users/${row.id}`)">{{ $t('admin.userFile.open') }}</AppButton><AppButton v-if="row.type === 'staff'" size="sm" variant="outline" @click="openEdit(row)">{{ $t('common.edit') }}</AppButton><AppButton size="sm" variant="ghost" @click="toggleStatus(row)">{{ row.status === 'active' ? $t('admin.users.suspend') : $t('admin.users.activate') }}</AppButton></div></template>
        <template #footer><Pagination v-model:page="page" :last-page="meta.last_page" :total="meta.total" /></template>
      </DataTable>
    </template>

    <div v-else-if="tab === 'roles'" class="space-y-3">
      <p v-if="!rolesData" class="text-sm text-muted">{{ $t('common.loading') }}</p>
      <article v-for="r in rolesData?.roles ?? []" :key="r.id" class="card card-pad flex flex-col sm:flex-row gap-3">
        <div class="flex-1 min-w-0">
          <div class="flex flex-wrap items-center gap-2"><h3 class="font-semibold">{{ roleLabel(r.name) }}</h3><code class="text-xs ltr rounded bg-bg px-1.5 py-0.5">{{ r.name }}</code><AppBadge v-if="r.is_system" kind="generic" value="pending" :label="$t('admin.users.systemRole')" :dot="false" /><span class="text-xs text-muted">{{ $t('admin.users.usersCount', { n: r.users_count }) }}</span></div>
          <ul class="mt-2 flex flex-wrap gap-1"><li v-for="p in r.permissions" :key="p" class="text-[11px] ltr rounded-full bg-bg px-2 py-0.5">{{ p }}</li><li v-if="!r.permissions.length" class="text-xs text-muted">—</li></ul>
          <p v-if="r.name === 'super_admin'" class="text-xs text-muted mt-1">{{ $t('admin.users.superAdminLocked') }}</p>
        </div>
        <AppButton v-if="r.name !== 'super_admin'" size="sm" variant="outline" @click="openRole(r)">{{ $t('common.edit') }}</AppButton>
      </article>
    </div>

    <!-- ADM-07 approval-authority matrix -->
    <div v-else class="space-y-4">
      <InlineAlert tone="info">{{ $t('admin.matrix.hint') }}</InlineAlert>
      <p v-if="matrixLoading && !matrix" class="text-sm text-muted">{{ $t('common.loading') }}</p>
      <div v-else-if="matrix" class="card overflow-hidden">
        <div class="overflow-x-auto">
          <table class="table-base text-sm">
            <thead>
              <tr>
                <th scope="col" class="min-w-[220px]">{{ $t('admin.matrix.decision') }}</th>
                <th scope="col" class="min-w-[160px]">{{ $t('admin.matrix.requires') }}</th>
                <th v-for="r in matrix.roles" :key="r.name" scope="col" class="text-center whitespace-nowrap text-xs">{{ roleLabel(r.name) }}</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="g in matrixAreas" :key="g.area">
                <tr class="bg-bg/60"><td :colspan="2 + matrix.roles.length" class="font-semibold">{{ $t(`admin.matrix.areas.${g.area}`) }}</td></tr>
                <tr v-for="d in g.rows" :key="d.key">
                  <td class="font-medium">{{ $t(`admin.matrix.decisions.${d.key}`) }}<span v-if="d.roles_setting" class="block text-[11px] text-muted font-normal">{{ $t('admin.matrix.settingRestricted') }} <code class="ltr">{{ d.roles_setting }}</code></span></td>
                  <td><span v-if="d.mode === 'any' && d.permissions.length > 1" class="block text-[11px] text-muted">{{ $t('admin.matrix.anyOf') }}</span><ul class="flex flex-wrap gap-1"><li v-for="p in d.permissions" :key="p" class="text-[11px] ltr rounded-full bg-bg px-2 py-0.5">{{ p }}</li></ul></td>
                  <td v-for="r in matrix.roles" :key="r.name" class="text-center">
                    <span v-if="cell(d, r.name) === 'yes'" class="text-success font-semibold" :title="$t('admin.matrix.yes')">✓<span class="sr-only">{{ $t('admin.matrix.yes') }}</span></span>
                    <span v-else-if="cell(d, r.name) === 'excluded'" class="text-warning" :title="$t('admin.matrix.excluded')">!<span class="sr-only">{{ $t('admin.matrix.excluded') }}</span></span>
                    <span v-else class="text-muted/60" aria-hidden="true">—</span>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
        <p class="px-4 py-3 border-t border-border text-xs text-muted flex flex-wrap gap-4"><span><span class="text-success font-semibold">✓</span> {{ $t('admin.matrix.yes') }}</span><span><span class="text-warning">!</span> {{ $t('admin.matrix.excluded') }}</span><span class="num">{{ $t('admin.matrix.rolesCount', { n: number(matrix.roles.length) }) }}</span></p>
      </div>
    </div>

    <Modal :open="open" :title="editing ? $t('common.edit') : $t('admin.users.addStaff')" @close="open = false">
      <form class="space-y-4" novalidate @submit.prevent="save">
        <FormField v-model="userForm.values.name" name="name" :label="$t('common.name')" :disabled="!!editing" :error="userForm.errors.value.name" required autocomplete="off" />
        <FormField v-model="userForm.values.email" name="email" type="email" :label="$t('common.email')" :disabled="!!editing" :error="userForm.errors.value.email" required ltr autocomplete="off" />
        <PhoneField v-if="!editing" v-model="userForm.values.phone" name="phone" :label="$t('common.phone')" :error="userForm.errors.value.phone" />
        <FormField v-model="userForm.values.roles" name="roles" type="chips" :label="$t('admin.users.roles')" :options="roleOpts" :error="userForm.errors.value.roles" :required="!editing" />
        <InlineAlert v-if="!editing" tone="info">{{ $t('admin.users.passwordHint') }}</InlineAlert>
      </form>
      <template #footer><FormAlert :message="userForm.formError.value" class="basis-full" /><AppButton variant="ghost" @click="open = false">{{ $t('common.cancel') }}</AppButton><AppButton :loading="userForm.submitting.value" @click="save">{{ $t('common.save') }}</AppButton></template>
    </Modal>

    <Modal :open="roleOpen" :title="roleEditing ? `${$t('common.edit')} — ${roleLabel(roleEditing.name)}` : $t('admin.users.addRole')" @close="roleOpen = false">
      <form class="space-y-4" novalidate @submit.prevent="saveRole">
        <FormField v-if="!roleEditing" v-model="roleForm.values.role_name" name="role_name" :label="$t('admin.users.roleName')" :error="roleForm.errors.value.role_name" ltr required autocomplete="off" />
        <fieldset>
          <legend class="label">{{ $t('admin.users.permissions') }}<span v-if="!roleEditing" class="text-danger ms-0.5" aria-hidden="true">*</span></legend>
          <div class="grid sm:grid-cols-2 gap-1.5"><label v-for="p in rolesData?.permissions ?? []" :key="p" class="inline-flex items-center gap-2 text-sm ltr"><input type="checkbox" :checked="roleForm.values.permissions.includes(p)" class="h-4 w-4 rounded border-border text-blue" @change="togglePerm(p)" />{{ p }}</label></div>
          <p v-if="roleForm.errors.value.permissions" class="error-text" role="alert">{{ roleForm.errors.value.permissions }}</p>
        </fieldset>
      </form>
      <template #footer><FormAlert :message="roleForm.formError.value" class="basis-full" /><AppButton variant="ghost" @click="roleOpen = false">{{ $t('common.cancel') }}</AppButton><AppButton :loading="roleForm.submitting.value" @click="saveRole">{{ $t('common.save') }}</AppButton></template>
    </Modal>
  </div>
</template>

<script setup lang="ts">
/**
 * ACC-08 — organisation team (institutional investor / entrepreneur entity). The representative invites colleagues by
 * e-mail (signed link with expiry → /auth/invitation), grants "can act", disables / re-enables a colleague and
 * transfers the representative role. Colleagues see the team read-only. Everything is audited server-side.
 * GET /organization/team · POST /organization/invitations (+ /{id}/resend, DELETE /{id}) · PATCH /organization/members/{id}
 * · POST /organization/representative.
 */
import { z } from 'zod'
import type { OrganizationTeam, TeamInvitation, TeamMember } from '~/types/collab'
import { emailField, optionalText, parseApiError, RULES } from '~/utils/validation'
import { confirmDialog, toastApiError, toastSuccess } from '~/utils/notify'
definePageMeta({ layout: 'portal', middleware: ['auth'] })
const { t } = useI18n()
useHead({ title: t('collab.team.metaTitle') })
const api = useApi()
const auth = useAuthStore()
const { date, relative } = useFormat()

const team = ref<OrganizationTeam | null>(null)
const loading = ref(true)
const missing = ref(false)
const load = async () => {
  try {
    team.value = (await api.get<{ data: OrganizationTeam }>('/organization/team')).data
    missing.value = false
  } catch (e) {
    const p = parseApiError(e)
    if (p.status === 404 || p.status === 403) missing.value = true
    else toastApiError(e)
  } finally {
    loading.value = false
  }
}
onMounted(load)
const isRep = computed(() => !!team.value?.is_representative)

// ---- invite (email required, name optional, can_act default off)
const invite = useZodForm(z.object({ email: emailField, name: optionalText(RULES.staffNameMax), can_act: z.boolean() }), { email: '', name: '' as string | null, can_act: false })
const sendInvite = () => invite.submit(async (v) => {
  const r = await api.post<{ data: TeamInvitation }>('/organization/invitations', { email: v.email.trim().toLowerCase(), name: v.name?.trim() || undefined, can_act: v.can_act })
  if (team.value) team.value.invitations = [r.data, ...team.value.invitations.filter((i) => i.id !== r.data.id)]
  invite.reset()
  toastSuccess(t('collab.team.invited', { email: r.data.email }))
})

// ---- members
const busy = ref<string | null>(null)
const replaceMember = (m: TeamMember) => { if (team.value) team.value.members = team.value.members.map((x) => (x.id === m.id ? m : x)) }
const patchMember = async (m: TeamMember, body: { can_act?: boolean; disabled?: boolean }, ok: string) => {
  busy.value = `m-${m.id}`
  try {
    replaceMember((await api.patch<{ data: TeamMember }>(`/organization/members/${m.id}`, body)).data)
    toastSuccess(ok)
  } catch (e) {
    toastApiError(e)
  } finally {
    busy.value = null
  }
}
const toggleAct = (m: TeamMember) => patchMember(m, { can_act: !m.can_act }, t(m.can_act ? 'collab.team.actRevoked' : 'collab.team.actGranted', { name: m.name }))
const toggleDisabled = async (m: TeamMember) => {
  const disable = m.status === 'active'
  if (disable && !(await confirmDialog(t('collab.team.disableTitle'), t('collab.team.disableText', { name: m.name }), t('collab.team.disable')))) return
  await patchMember(m, { disabled: disable }, t(disable ? 'collab.team.disabled' : 'collab.team.enabled', { name: m.name }))
}
const transfer = async (m: TeamMember) => {
  if (!(await confirmDialog(t('collab.team.transferTitle'), t('collab.team.transferText', { name: m.name }), t('collab.team.transfer')))) return
  busy.value = `t-${m.id}`
  try {
    team.value = (await api.post<{ data: OrganizationTeam }>('/organization/representative', { user_id: m.id })).data
    await auth.fetchMe(true)
    toastSuccess(t('collab.team.transferred', { name: m.name }))
  } catch (e) {
    toastApiError(e)
  } finally {
    busy.value = null
  }
}

// ---- invitations
const resend = async (i: TeamInvitation) => {
  busy.value = `r-${i.id}`
  try {
    const r = await api.post<{ data: TeamInvitation }>(`/organization/invitations/${i.id}/resend`)
    if (team.value) team.value.invitations = team.value.invitations.map((x) => (x.id === i.id ? r.data : x))
    toastSuccess(t('collab.team.resent', { email: i.email }))
  } catch (e) {
    toastApiError(e)
  } finally {
    busy.value = null
  }
}
const revoke = async (i: TeamInvitation) => {
  if (!(await confirmDialog(t('collab.team.revokeTitle'), t('collab.team.revokeText', { email: i.email }), t('collab.team.revoke')))) return
  busy.value = `x-${i.id}`
  try {
    await api.del(`/organization/invitations/${i.id}`)
    if (team.value) team.value.invitations = team.value.invitations.filter((x) => x.id !== i.id)
    toastSuccess(t('collab.team.revoked', { email: i.email }))
  } catch (e) {
    toastApiError(e)
  } finally {
    busy.value = null
  }
}

const columns = computed(() => [
  { key: 'name', label: t('common.name'), sortable: true },
  { key: 'role', label: t('collab.team.role') },
  { key: 'can_act', label: t('collab.team.canAct') },
  { key: 'status', label: t('common.status') },
  { key: 'last_login_at', label: t('collab.team.lastLogin') },
  ...(isRep.value ? [{ key: 'actions', label: '' }] : []),
])
</script>

<template>
  <div>
    <PageHeader :title="$t('collab.team.title')" :sub="$t('collab.team.sub')" :eyebrow="team?.organization.name" />
    <p v-if="loading" class="text-muted">{{ $t('common.loading') }}</p>
    <EmptyState v-else-if="missing || !team" :title="$t('collab.team.noOrganization')" :text="$t('collab.team.noOrganizationText')" />
    <div v-else class="space-y-6">
      <InlineAlert v-if="!isRep" :tone="team.can_act ? 'info' : 'warning'">{{ team.can_act ? $t('collab.team.youCanAct') : $t('collab.team.youReadOnly') }}</InlineAlert>

      <SectionCard v-if="isRep" :title="$t('collab.team.inviteTitle')" :sub="$t('collab.team.inviteSub')">
        <form class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="sendInvite">
          <FormField v-model="invite.values.email" name="email" type="email" :label="$t('common.email')" :error="invite.errors.value.email" required autocomplete="off" ltr />
          <FormField v-model="invite.values.name" name="name" :label="$t('collab.team.inviteeName')" :error="invite.errors.value.name" :maxlength="RULES.staffNameMax" />
          <FormField v-model="invite.values.can_act" name="can_act" type="checkbox" :label="$t('collab.team.canActLabel')" :help="$t('collab.team.canActHelp')" class="sm:col-span-2" />
          <FormAlert class="sm:col-span-2" />
          <div class="sm:col-span-2 flex justify-end"><AppButton type="submit" :loading="invite.submitting.value">{{ $t('collab.team.sendInvite') }}</AppButton></div>
        </form>
      </SectionCard>

      <section>
        <h2 class="card-title mb-3">{{ $t('collab.team.members') }}</h2>
        <DataTable :columns="columns" :rows="team.members">
          <template #cell-name="{ row }"><span class="font-medium">{{ row.name }}<span v-if="row.is_me" class="text-xs text-muted"> ({{ $t('collab.team.you') }})</span></span><span class="block text-xs text-muted ltr text-start" dir="ltr">{{ row.email }}</span></template>
          <template #cell-role="{ row }"><AppBadge kind="generic" :value="row.is_representative ? 'approved' : 'new'" :label="row.is_representative ? $t('collab.team.representative') : $t('collab.team.colleague')" /></template>
          <template #cell-can_act="{ row }">
            <span v-if="row.is_representative" class="text-xs text-muted">{{ $t('common.yes') }}</span>
            <label v-else-if="isRep" class="inline-flex items-center gap-2 text-sm cursor-pointer">
              <input type="checkbox" class="h-4 w-4 accent-accent" :checked="row.can_act" :disabled="busy === `m-${row.id}` || row.status !== 'active'" :aria-label="`${$t('collab.team.canAct')} — ${row.name}`" @change="toggleAct(row as TeamMember)">
              {{ row.can_act ? $t('common.yes') : $t('common.no') }}
            </label>
            <span v-else class="text-sm">{{ row.can_act ? $t('common.yes') : $t('common.no') }}</span>
          </template>
          <template #cell-status="{ value }"><AppBadge kind="generic" :value="value === 'active' ? 'active' : 'suspended'" :label="value === 'active' ? $t('collab.team.active') : $t('collab.team.disabledLabel')" /></template>
          <template #cell-last_login_at="{ value }"><span class="num text-sm">{{ value ? relative(value) : '—' }}</span></template>
          <template #cell-actions="{ row }">
            <div v-if="!row.is_me && !row.is_representative" class="flex flex-wrap gap-1 justify-end">
              <AppButton v-if="row.status === 'active'" size="sm" variant="outline" :loading="busy === `t-${row.id}`" @click="transfer(row as TeamMember)">{{ $t('collab.team.makeRepresentative') }}</AppButton>
              <AppButton size="sm" variant="ghost" :class="row.status === 'active' && 'text-danger'" :loading="busy === `m-${row.id}`" @click="toggleDisabled(row as TeamMember)">{{ row.status === 'active' ? $t('collab.team.disable') : $t('collab.team.enable') }}</AppButton>
            </div>
          </template>
        </DataTable>
      </section>

      <SectionCard v-if="isRep" :title="$t('collab.team.invitations')" :sub="$t('collab.team.invitationsSub')" flush>
        <ul v-if="team.invitations.length" class="divide-y divide-border">
          <li v-for="i in team.invitations" :key="i.id" class="flex flex-col sm:flex-row sm:items-center gap-3 px-5 py-4">
            <div class="min-w-0 flex-1">
              <p class="font-medium ltr text-start truncate" dir="ltr">{{ i.email }}</p>
              <p class="text-xs text-muted">
                <template v-if="i.name">{{ i.name }} · </template>{{ i.can_act ? $t('collab.team.canActShort') : $t('collab.team.readOnlyShort') }} ·
                <span v-if="i.status === 'expired'" class="text-danger">{{ $t('collab.team.expired') }}</span>
                <span v-else>{{ $t('collab.team.expiresAt') }}: <span class="num">{{ date(i.expires_at) }}</span></span>
              </p>
            </div>
            <div class="flex gap-2 shrink-0">
              <AppButton size="sm" variant="secondary" :loading="busy === `r-${i.id}`" @click="resend(i)">{{ $t('collab.team.resend') }}</AppButton>
              <AppButton size="sm" variant="ghost" class="text-danger" :loading="busy === `x-${i.id}`" @click="revoke(i)">{{ $t('collab.team.revoke') }}</AppButton>
            </div>
          </li>
        </ul>
        <p v-else class="px-5 py-4 text-sm text-muted">{{ $t('collab.team.noInvitations') }}</p>
      </SectionCard>
    </div>
  </div>
</template>

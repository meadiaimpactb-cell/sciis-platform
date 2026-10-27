<script setup lang="ts">
import { z } from 'zod'
import type { Interest } from '~/types/api'
import { optionalText, RULES } from '~/utils/validation'
import { confirmDialog, toastApiError, toastInfo, toastSuccess } from '~/utils/notify'
definePageMeta({ layout: 'portal', middleware: ['entrepreneur'] })
const { t } = useI18n()
useHead({ title: t('entrepreneur.accessRequests.metaTitle') })
const api = useApi()
const { date } = useFormat()
const { data, loading, refresh } = useAsyncResource(() => api.get<{ data: Interest[] }>('/entrepreneur/access-requests').then((r) => r.data))
const investorName = (i: Interest) => i.investor?.name ?? t('entrepreneur.dashboard.investorLabel')

// approve: direct (it only shares the full file with this investor)
const busy = ref<number | null>(null)
const approve = async (i: Interest) => {
  busy.value = i.id
  try {
    await api.post(`/entrepreneur/access-requests/${i.id}/decide`, { decision: 'approve' })
    toastSuccess(t('notify.approved', { item: t('notify.entities.accessRequest') }))
    await refresh()
  } catch (e) { toastApiError(e) } finally { busy.value = null }
}

// decline: optional reason (DecideAccessRequest: reason ≤ 2000) + confirmation naming the investor
const declining = ref<Interest | null>(null)
const declineForm = useZodForm(z.object({ reason: optionalText(RULES.noteMax) }), { reason: '' })
const openDecline = (i: Interest) => { declineForm.reset(); declining.value = i }
const decline = () => declineForm.submit(async (v) => {
  const i = declining.value
  if (!i || !(await confirmDialog(t('notify.declineTitle'), t('notify.declineText', { name: investorName(i) }), t('notify.declineConfirm')))) return
  await api.post(`/entrepreneur/access-requests/${i.id}/decide`, { decision: 'decline', reason: v.reason?.trim() || undefined })
  declining.value = null
  toastInfo(t('notify.rejectedNotified', { item: t('notify.entities.accessRequest') }))
  await refresh()
})

// ENT-06 — revoke an approved full-file access: optional reason (RevokeAccessRequest: ≤ 2000, seen by the council)
// + confirmation naming the investor; documents close for them immediately and the request is closed.
const revoking = ref<Interest | null>(null)
const revokeForm = useZodForm(z.object({ revoke_reason: optionalText(RULES.noteMax) }), { revoke_reason: '' }, { aliases: { reason: 'revoke_reason' } })
const openRevoke = (i: Interest) => { revokeForm.reset(); revoking.value = i }
const revoke = () => revokeForm.submit(async (v) => {
  const i = revoking.value
  if (!i || !(await confirmDialog(t('entrepreneur.accessRequests.revokeTitle'), t('entrepreneur.accessRequests.revokeText', { name: investorName(i) }), t('entrepreneur.accessRequests.revokeConfirm')))) return
  await api.post(`/entrepreneur/access-requests/${i.id}/revoke`, { reason: v.revoke_reason?.trim() || undefined })
  revoking.value = null
  toastSuccess(t('notify.revoked', { item: t('notify.entities.fileAccess') }))
  await refresh()
})
</script>
<template>
  <div>
    <PageHeader :title="$t('entrepreneur.accessRequests.title')" :sub="$t('entrepreneur.accessRequests.sub')" />
    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <EmptyState v-else-if="!data?.length" :title="$t('common.noData')" />
    <div v-else class="space-y-4">
      <article v-for="i in data" :key="i.id" class="card card-pad flex flex-col md:flex-row md:items-center gap-4">
        <div class="flex-1 min-w-0">
          <div class="flex flex-wrap items-center gap-2"><AppBadge kind="interest" :value="i.status" size="md" /><span class="text-xs text-muted num">{{ date(i.created_at) }}</span></div>
          <h2 class="mt-2 card-title">{{ investorName(i) }}</h2>
          <p class="text-sm text-muted">{{ i.opportunity.name }}</p>
          <p v-if="i.note" class="mt-2 text-sm text-text">{{ i.note }}</p>
          <p v-if="i.owner_decision" class="mt-2 text-xs text-muted">{{ $t('admin.interests.ownerDecision') }}: {{ $t(`enums.ownerDecision.${i.owner_decision}`) }} · <span class="num">{{ date(i.owner_decided_at) }}</span></p>
          <p v-if="i.access_active" class="mt-1 text-xs text-success">{{ $t('entrepreneur.accessRequests.accessActive') }}</p>
          <p v-else-if="i.access_revoked_at" class="mt-1 text-xs text-muted">{{ $t('entrepreneur.accessRequests.accessRevokedAt') }}: <span class="num">{{ date(i.access_revoked_at) }}</span></p>
        </div>
        <div v-if="i.status === 'awaiting_owner_approval'" class="flex gap-2 shrink-0"><AppButton :loading="busy === i.id" @click="approve(i)">{{ $t('entrepreneur.accessRequests.approve') }}</AppButton><AppButton variant="ghost" @click="openDecline(i)">{{ $t('entrepreneur.accessRequests.decline') }}</AppButton></div>
        <div v-else-if="i.can_revoke" class="shrink-0"><AppButton variant="ghost" class="text-danger" @click="openRevoke(i)">{{ $t('entrepreneur.accessRequests.revoke') }}</AppButton></div>
      </article>
    </div>
    <Modal :open="!!declining" :title="$t('entrepreneur.accessRequests.decline')" @close="declining = null">
      <form novalidate @submit.prevent="decline">
        <p class="text-sm text-muted mb-3">{{ declining ? `${investorName(declining)} · ${declining.opportunity.name}` : '' }}</p>
        <FormField v-model="declineForm.values.reason" name="reason" type="textarea" :label="$t('entrepreneur.accessRequests.declineReason')" :error="declineForm.errors.value.reason" :rows="3" :maxlength="RULES.noteMax" />
      </form>
      <template #footer><FormAlert :message="declineForm.formError.value" class="basis-full" /><AppButton variant="ghost" @click="declining = null">{{ $t('common.cancel') }}</AppButton><AppButton variant="danger" :loading="declineForm.submitting.value" @click="decline">{{ $t('common.decline') }}</AppButton></template>
    </Modal>
    <Modal :open="!!revoking" :title="$t('entrepreneur.accessRequests.revokeTitle')" @close="revoking = null">
      <form novalidate @submit.prevent="revoke">
        <p class="text-sm text-muted mb-3">{{ revoking ? `${investorName(revoking)} · ${revoking.opportunity.name}` : '' }}</p>
        <p class="text-sm mb-3">{{ $t('entrepreneur.accessRequests.revokeHint') }}</p>
        <FormField v-model="revokeForm.values.revoke_reason" name="revoke_reason" type="textarea" :label="$t('entrepreneur.accessRequests.revokeReason')" :error="revokeForm.errors.value.revoke_reason" :rows="3" :maxlength="RULES.noteMax" />
      </form>
      <template #footer><FormAlert :message="revokeForm.formError.value" class="basis-full" /><AppButton variant="ghost" @click="revoking = null">{{ $t('common.cancel') }}</AppButton><AppButton variant="danger" :loading="revokeForm.submitting.value" @click="revoke">{{ $t('entrepreneur.accessRequests.revokeConfirm') }}</AppButton></template>
    </Modal>
  </div>
</template>

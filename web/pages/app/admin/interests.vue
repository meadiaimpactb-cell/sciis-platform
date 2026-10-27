<script setup lang="ts">
/** Interests pipeline — `allowed_transitions` comes from the API (#21); closing requires a reason (TransitionInterestRequest ≤2000). */
import { z } from 'zod'
import type { Interest, Paginated } from '~/types/api'
import type { InterestStatus } from '~/types/enums'
import { requiredString, RULES } from '~/utils/validation'
import { confirmDialog, toastApiError, toastSuccess } from '~/utils/notify'
definePageMeta({ layout: 'portal', middleware: ['role'], roles: ['matching_officer'] })
const { t } = useI18n()
useHead({ title: t('admin.interests.metaTitle') })
const api = useApi()
const { label } = useEnumLabel()
const { date } = useFormat()
const { data, loading, refresh } = useAsyncResource(() => api.get<Paginated<Interest>>('/admin/interests', { per_page: 50 }).then((r) => r.data ?? []))
const busy = ref<number | null>(null)
const closing = ref<Interest | null>(null)
const closeForm = useZodForm(z.object({ close_reason: requiredString('admin.interests.closeReason', RULES.noteMin, RULES.noteMax) }), { close_reason: '' }, { aliases: { reason: 'close_reason' } })
const interestName = (i: Interest) => [i.investor?.name, i.opportunity.name].filter(Boolean).join(' · ')

const transition = async (i: Interest, to: InterestStatus) => {
  if (to === 'closed') { closing.value = i; closeForm.reset(); return }
  busy.value = i.id
  try {
    await api.post(`/admin/interests/${i.id}/transition`, { to })
    toastSuccess(t('notify.statusChangedTo', { item: t('notify.entities.interest'), status: label('interestStatus', to) }))
    await refresh()
  } catch (e) { toastApiError(e) } finally { busy.value = null }
}
/** Close = irreversible → reason validated first, then an explicit confirmation naming the interest. */
const close = () => closeForm.submit(async (v) => {
  const i = closing.value
  if (!i) return
  if (!(await confirmDialog(t('notify.closeTitle'), t('notify.closeText', { name: interestName(i) }), t('notify.closeConfirm')))) return
  await api.post(`/admin/interests/${i.id}/transition`, { to: 'closed', reason: v.close_reason.trim() })
  closing.value = null
  toastSuccess(t('notify.closed', { item: t('notify.entities.interest') }))
  await refresh()
})
const columns = [{ key: 'investor', label: t('admin.interests.investor') }, { key: 'opportunity', label: t('admin.interests.opportunity') }, { key: 'status', label: t('common.status') }, { key: 'owner_decision', label: t('admin.interests.ownerDecision') }, { key: 'created_at', label: t('common.createdAt'), sortable: true }, { key: 'transition', label: t('admin.interests.transition') }, { key: 'messages', label: '' }]
// MTG-03 — staff ↔ investor messages on one interest.
const messagesFor = ref<Interest | null>(null)
</script>
<template>
  <div>
    <PageHeader :title="$t('admin.interests.title')" :sub="$t('admin.interests.sub')" />
    <DataTable :columns="columns" :rows="data ?? []" :loading="loading">
      <template #cell-investor="{ row }"><span class="font-medium">{{ row.investor?.name ?? '—' }}</span></template>
      <template #cell-opportunity="{ row }"><NuxtLink :to="$localePath(`/app/admin/opportunities/${row.opportunity.uuid}`)" class="text-ink hover:text-accent-text">{{ row.opportunity.name }}</NuxtLink></template>
      <template #cell-status="{ value }"><AppBadge kind="interest" :value="value" /></template>
      <template #cell-owner_decision="{ value }">{{ value ? label('ownerDecision', value) : '—' }}</template>
      <template #cell-created_at="{ value }"><span class="num text-muted whitespace-nowrap">{{ date(value) }}</span></template>
      <template #cell-transition="{ row }"><div class="flex flex-wrap gap-1"><AppButton v-for="s in row.allowed_transitions ?? []" :key="s" size="sm" :variant="s === 'closed' ? 'ghost' : 'outline'" :loading="busy === row.id && s !== 'closed'" @click="transition(row as Interest, s)">{{ label('interestStatus', s) }}</AppButton><span v-if="!(row.allowed_transitions ?? []).length" class="text-xs text-muted">—</span></div></template>
      <template #cell-messages="{ row }"><AppButton size="sm" variant="ghost" @click="messagesFor = row as Interest">{{ $t('collab.messages.button') }}</AppButton></template>
    </DataTable>
    <Modal :open="!!messagesFor" :title="$t('collab.messages.withInvestor')" size="lg" @close="messagesFor = null">
      <p class="text-sm text-muted mb-3">{{ messagesFor ? interestName(messagesFor) : '' }}</p>
      <LazyMessageThread v-if="messagesFor" subject-type="interest" :subject-key="messagesFor.id" staff />
    </Modal>
    <Modal :open="!!closing" :title="$t('admin.interests.closeTitle')" @close="closing = null">
      <form class="space-y-3" novalidate @submit.prevent="close">
        <p class="text-sm text-muted">{{ closing ? interestName(closing) : '' }}</p>
        <FormField v-model="closeForm.values.close_reason" name="close_reason" type="textarea" :label="$t('admin.interests.closeReason')" :rows="3" :help="$t('admin.interests.closeHint')" :error="closeForm.errors.value.close_reason" :maxlength="RULES.noteMax" required />
      </form>
      <template #footer><FormAlert :message="closeForm.formError.value" class="basis-full" /><AppButton variant="ghost" @click="closing = null">{{ $t('common.cancel') }}</AppButton><AppButton variant="danger" :loading="closeForm.submitting.value" @click="close">{{ $t('notify.closeConfirm') }}</AppButton></template>
    </Modal>
  </div>
</template>

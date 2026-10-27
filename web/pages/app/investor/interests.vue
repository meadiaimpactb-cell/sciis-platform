<script setup lang="ts">
import { z } from 'zod'
import type { Interest } from '~/types/api'
import { INTEREST_STATUSES } from '~/types/enums'
import { optionalText, RULES } from '~/utils/validation'
import { confirmDialog, toastSuccess } from '~/utils/notify'
definePageMeta({ layout: 'portal', middleware: ['investor'] })
const { t } = useI18n()
useHead({ title: t('investor.interests.metaTitle') })
const api = useApi()
const tax = useTaxonomyStore()
await tax.load().catch(() => {})
const { term } = useEnumLabel()
const { date } = useFormat()
const { data, loading, refresh } = useAsyncResource(() => api.get<{ data: Interest[] }>('/investor/interests').then((r) => r.data))
const steps = INTEREST_STATUSES.filter((s) => s !== 'closed')
const stepIdx = (s: string) => steps.indexOf(s as never)

// close: optional reason + note (CloseInterestRequest: reason_id nullable, note ≤ 2000), confirmed with the opportunity name
const closing = ref<Interest | null>(null)
const closeForm = useZodForm(z.object({ reason_id: z.string().nullish(), note: optionalText(RULES.noteMax) }), { reason_id: '' as string | null, note: '' })
const openClose = (i: Interest) => { closeForm.reset(); closing.value = i }
const close = () => closeForm.submit(async (v) => {
  const i = closing.value
  if (!i || !(await confirmDialog(t('notify.closeTitle'), t('notify.closeText', { name: i.opportunity.name }), t('notify.closeConfirm')))) return
  await api.post(`/investor/interests/${i.id}/close`, { reason_id: Number(v.reason_id) || undefined, note: v.note?.trim() || undefined })
  closing.value = null
  toastSuccess(t('notify.closed', { item: t('notify.entities.interest') }))
  await refresh()
})
const reasonOpts = computed(() => tax.list('decline_reasons').map((r) => ({ value: String(r.id), label: term(r) })))
// ACC-08 — colleagues without "can act" see the organisation's interests read-only.
const canAct = computed(() => useAuthStore().me?.organization_access?.can_act !== false)
// MTG-03 — messages with the council about one interest (never with the owner).
const messagesFor = ref<Interest | null>(null)
</script>

<template>
  <div>
    <PageHeader :title="$t('investor.interests.title')" :sub="$t('investor.interests.sub')" />
    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <EmptyState v-else-if="!data?.length" :title="$t('common.noData')"><AppButton to="/app/opportunities" size="sm">{{ $t('portal.nav.opportunities') }}</AppButton></EmptyState>
    <div v-else class="space-y-4">
      <article v-for="i in data" :key="i.id" class="card card-pad">
        <div class="flex flex-col md:flex-row md:items-start gap-4">
          <div class="flex-1 min-w-0">
            <div class="flex flex-wrap items-center gap-2"><AppBadge kind="interest" :value="i.status" size="md" /><span class="text-xs text-muted num">{{ date(i.created_at) }}</span></div>
            <h2 class="mt-2 card-title"><NuxtLink :to="$localePath(`/app/opportunities/${i.opportunity.uuid}`)" class="hover:text-accent-text transition">{{ i.opportunity.name }}</NuxtLink></h2>
            <p class="text-sm text-muted">{{ term(i.opportunity.sector) }} · <span class="num">{{ i.opportunity.reference_no }}</span></p>
            <StageTrack v-if="i.status !== 'closed'" class="mt-4" :stages="steps.map((s) => $t(`investor.interests.steps.${s}`))" :current="stepIdx(i.status)" :label="i.opportunity.name" />
            <p v-if="i.next_step" class="mt-3 text-sm"><span class="text-muted">{{ $t('investor.dashboard.nextStep') }}:</span> {{ i.next_step }}</p>
            <p v-if="i.closed_reason" class="mt-2 text-sm text-muted">{{ $t('common.reason') }}: {{ i.closed_reason }}</p>
          </div>
          <div class="shrink-0 flex md:flex-col gap-2">
            <AppButton variant="secondary" size="sm" :to="`/app/opportunities/${i.opportunity.uuid}`">{{ $t('common.view') }}</AppButton>
            <AppButton variant="secondary" size="sm" @click="messagesFor = i">{{ $t('collab.messages.button') }}</AppButton>
            <AppButton v-if="i.status !== 'closed' && canAct" variant="ghost" size="sm" @click="openClose(i)">{{ $t('investor.interests.closeBtn') }}</AppButton>
          </div>
        </div>
      </article>
    </div>
    <Modal :open="!!closing" :title="$t('investor.interests.closeModal.title')" @close="closing = null">
      <form class="space-y-4" novalidate @submit.prevent="close">
        <p class="text-sm text-muted">{{ closing?.opportunity.name }}</p>
        <FormField v-model="closeForm.values.reason_id" name="reason_id" type="select" :label="$t('investor.interests.closeModal.reason')" :options="reasonOpts" :error="closeForm.errors.value.reason_id" />
        <FormField v-model="closeForm.values.note" name="note" type="textarea" :label="$t('investor.interests.closeModal.note')" :error="closeForm.errors.value.note" :rows="3" :maxlength="RULES.noteMax" />
      </form>
      <template #footer><FormAlert :message="closeForm.formError.value" class="basis-full" /><AppButton variant="ghost" @click="closing = null">{{ $t('common.cancel') }}</AppButton><AppButton variant="danger" :loading="closeForm.submitting.value" @click="close">{{ $t('investor.interests.closeBtn') }}</AppButton></template>
    </Modal>
    <Modal :open="!!messagesFor" :title="$t('collab.messages.withCouncil')" size="lg" @close="messagesFor = null">
      <p class="text-sm text-muted mb-3">{{ messagesFor?.opportunity.name }}</p>
      <LazyMessageThread v-if="messagesFor" subject-type="interest" :subject-key="messagesFor.id" />
    </Modal>
  </div>
</template>

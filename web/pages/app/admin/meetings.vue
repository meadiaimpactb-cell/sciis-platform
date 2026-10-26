<script setup lang="ts">
/**
 * MTG-01 schedule (MeetingRequest: title 3..190, starts_at in the future, ends_at after starts_at, 1..20 participants,
 * video_url url) · MTG-02 minutes (MeetingMinutesRequest: minutes 5..10000, ≤30 actions with a description ≤500) ·
 * cancel a scheduled meeting (PATCH {status: cancelled}, confirmed first).
 */
import { z } from 'zod'
import type { Meeting, Paginated, QueueItem, Interest, UserPick } from '~/types/api'
import { endsAfter, futureDate, listOf, optionalText, optionalUrlField, requiredChoice, requiredDate, requiredString, RULES } from '~/utils/validation'
import { confirmDialog, toastApiError, toastSuccess } from '~/utils/notify'
definePageMeta({ layout: 'portal', middleware: ['role'], roles: ['matching_officer', 'impact_officer'] })
const { t } = useI18n()
useHead({ title: t('admin.meetings.metaTitle') })
const api = useApi()
const auth = useAuthStore()
const { dateTime } = useFormat()
const { data, loading, refresh } = useAsyncResource(() => api.get<Paginated<Meeting>>('/admin/meetings', { per_page: 50 }).then((r) => r.data ?? []))
const { data: opps } = useAsyncResource(() => api.get<Paginated<QueueItem>>('/admin/opportunities', { per_page: 100 }).then((r) => r.data ?? []))
const { data: interests } = useAsyncResource(() => api.get<Paginated<Interest>>('/admin/interests', { per_page: 100 }).then((r) => r.data ?? []).catch(() => [] as Interest[]))

type Pick_ = { id: number; name: string; role: 'owner' | 'investor' | 'council' | 'other' }

// ---------------------------------------------------------------- schedule (MTG-01)
const scheduleSchema = endsAfter(
  z.object({
    opportunity_uuid: requiredChoice('admin.interests.opportunity'),
    interest_id: z.string(),
    title: requiredString('admin.meetings.title_', RULES.titleMin, RULES.titleMax),
    starts_at: futureDate('admin.meetings.startsAt'),
    ends_at: requiredDate('admin.meetings.endsAt'),
    video_url: optionalUrlField,
    location: optionalText(RULES.urlMax),
    participants: listOf(z.object({ id: z.number(), name: z.string(), role: z.string() }), 1, RULES.participantsMax),
  }),
  'starts_at',
  'ends_at',
)
const blankSchedule = () => ({ opportunity_uuid: '', interest_id: '', title: '', starts_at: '', ends_at: '', video_url: '', location: '', participants: [] as Pick_[] })
const schedule = useZodForm(scheduleSchema, blankSchedule(), { aliases: { participant_user_ids: 'participants' } })
const open = ref(false)

// ends_at defaults to start + 1h (the API requires it)
const plusHour = (local: string) => { const d = new Date(local); d.setHours(d.getHours() + 1); const p = (n: number) => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}` }
watch(() => schedule.values.starts_at, (v) => { if (v && (!schedule.values.ends_at || schedule.values.ends_at <= v)) schedule.values.ends_at = plusHour(v) })

const selectedOpp = computed(() => (opps.value ?? []).find((o) => o.uuid === schedule.values.opportunity_uuid) ?? null)
const relatedInterests = computed(() => (interests.value ?? []).filter((i) => i.opportunity.uuid === schedule.values.opportunity_uuid && ['connected', 'meeting', 'shared'].includes(i.status)))
/** Suggested participants: the opportunity owner + investors with an interest on it. */
const suggestions = computed<Pick_[]>(() => {
  const out: Pick_[] = []
  const owner = selectedOpp.value?.owner
  if (owner?.id) out.push({ id: owner.id, name: owner.name, role: 'owner' })
  for (const i of relatedInterests.value) if (i.investor?.id && !out.some((p) => p.id === i.investor!.id)) out.push({ id: i.investor.id, name: i.investor.name, role: 'investor' })
  return out.filter((p) => !schedule.values.participants.some((x) => x.id === p.id))
})
const addParticipant = (p: Pick_) => {
  if (schedule.values.participants.some((x) => x.id === p.id)) return
  schedule.values.participants.push(p)
  schedule.clearErrors(['participants'])
}
const removeParticipant = (id: number) => (schedule.values.participants = schedule.values.participants.filter((p) => p.id !== id))
watch(() => schedule.values.opportunity_uuid, () => { schedule.values.participants = []; schedule.values.interest_id = ''; suggestions.value.forEach(addParticipant) })
watch(() => schedule.values.interest_id, (id) => { const i = relatedInterests.value.find((x) => String(x.id) === id); if (i?.investor?.id) addParticipant({ id: i.investor.id, name: i.investor.name, role: 'investor' }) })
// free search of /admin/users (slim list for non-managers)
const userQuery = ref(''); const userResults = ref<UserPick[]>([])
let st: ReturnType<typeof setTimeout>
watch(userQuery, (q) => { clearTimeout(st); if (q.trim().length < 2) { userResults.value = []; return } st = setTimeout(async () => { try { userResults.value = (await api.get<Paginated<UserPick>>('/admin/users', { search: q.trim(), per_page: 8 })).data ?? [] } catch { userResults.value = [] } }, 300) })

const openSchedule = () => { schedule.reset(blankSchedule()); userQuery.value = ''; open.value = true }
const saveSchedule = () => schedule.submit(async (v) => {
  await api.post('/admin/meetings', { opportunity_uuid: v.opportunity_uuid, interest_id: v.interest_id ? Number(v.interest_id) : undefined, title: v.title.trim(), starts_at: new Date(v.starts_at).toISOString(), ends_at: new Date(v.ends_at || plusHour(v.starts_at)).toISOString(), video_url: v.video_url?.trim() || undefined, location: v.location?.trim() || undefined, participant_user_ids: v.participants.map((p) => p.id) })
  open.value = false
  toastSuccess(t('notify.created', { item: t('notify.entities.meeting') }))
  await refresh()
})

// ---------------------------------------------------------------- minutes (MTG-02)
const minutesFor = ref<Meeting | null>(null)
type ActionRow = { description: string; owner_user_id: number | string; due_at: string }
const minutesForm = useZodForm(
  z.object({
    minutes: requiredString('admin.meetings.minutes', RULES.minutesMin, RULES.minutesMax),
    attended: z.array(z.number()),
    actions: listOf(z.object({ description: requiredString('admin.meetings.actionDescription', 1, RULES.shortTextMax), owner_user_id: z.union([z.number(), z.string()]), due_at: z.string() }), 0, RULES.meetingActionsMax),
  }),
  { minutes: '', attended: [] as number[], actions: [] as ActionRow[] },
)
const openMinutes = (m: Meeting) => {
  minutesFor.value = m
  minutesForm.reset({ minutes: m.minutes ?? '', attended: m.participants.filter((p) => p.attended && p.user_id).map((p) => p.user_id as number), actions: (m.actions ?? []).map((a) => ({ description: a.description, owner_user_id: a.owner_user_id ?? '', due_at: a.due_at ? a.due_at.slice(0, 10) : '' })) })
}
const ownerOptions = computed(() => { const m = minutesFor.value; const list = (m?.participants ?? []).filter((p) => p.user_id).map((p) => ({ value: String(p.user_id), label: p.name })); if (auth.me && !list.some((x) => x.label === auth.me!.name)) list.push({ value: 'me', label: auth.me.name }); return list })
const addAction = () => minutesForm.values.actions.push({ description: '', owner_user_id: '', due_at: '' })
const removeAction = (i: number) => { minutesForm.values.actions.splice(i, 1); minutesForm.clearErrors(['actions']) }
const saveMinutes = () => minutesForm.submit(async (v) => {
  if (!minutesFor.value) return
  const meId = (auth.me as { id?: number } | null)?.id
  await api.post(`/admin/meetings/${minutesFor.value.id}/minutes`, { minutes: v.minutes.trim(), attended_user_ids: v.attended, actions: v.actions.map((a) => ({ description: a.description.trim(), owner_user_id: a.owner_user_id === 'me' ? meId : a.owner_user_id || undefined, due_at: a.due_at || undefined })) })
  minutesFor.value = null
  toastSuccess(t('notify.savedItem', { item: t('notify.entities.minutes') }))
  await refresh()
})

// ---------------------------------------------------------------- cancel (PATCH {status: cancelled})
const cancelling = ref<number | null>(null)
const cancelMeeting = async (m: Meeting) => {
  if (!(await confirmDialog(t('notify.cancelTitle'), t('notify.cancelText', { name: m.title }), t('notify.cancelConfirm')))) return
  cancelling.value = m.id
  try {
    await api.patch(`/admin/meetings/${m.id}`, { status: 'cancelled' })
    toastSuccess(t('notify.cancelled', { item: t('notify.entities.meeting') }))
    await refresh()
  } catch (e) { toastApiError(e) } finally { cancelling.value = null }
}
</script>
<template>
  <div>
    <PageHeader :title="$t('admin.meetings.title')" :sub="$t('admin.meetings.sub')"><template #actions><AppButton @click="openSchedule">{{ $t('admin.meetings.schedule') }}</AppButton></template></PageHeader>
    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <div v-else class="space-y-3">
      <article v-for="m in data ?? []" :key="m.id" class="card card-pad flex flex-col sm:flex-row gap-3">
        <div class="flex-1 min-w-0"><div class="flex flex-wrap items-center gap-2"><AppBadge kind="meeting" :value="m.status" /><span class="text-sm text-muted num">{{ dateTime(m.starts_at) }}</span></div><h3 class="mt-1 font-semibold">{{ m.title }}</h3><p class="text-sm text-muted">{{ m.opportunity.name }} · {{ m.participants.map((p) => p.name).join('، ') }}</p><p v-if="m.minutes" class="mt-2 text-sm rounded-lg bg-bg p-2">{{ m.minutes }}</p><ul v-if="m.actions?.length" class="mt-2 space-y-1.5 text-xs"><li v-for="(a, i) in m.actions" :key="a.id ?? i" class="flex flex-wrap items-center gap-x-2 gap-y-1"><span class="h-1.5 w-1.5 rounded-full" :class="a.done_at ? 'bg-success' : 'bg-warning'" aria-hidden="true" /><span :class="a.done_at && 'line-through text-muted'">{{ a.description }}</span> — {{ a.owner ?? '—' }}<span v-if="a.due_at" class="text-muted num">· {{ dateTime(a.due_at) }}</span><!-- MTG-02 --><MeetingActionToggle v-if="a.id && (a.can_toggle ?? auth.canAny('meetings.manage'))" :id="a.id" class="ms-auto" :done="!!a.done_at" :description="a.description" @changed="refresh" /></li></ul></div>
        <div v-if="m.status === 'scheduled'" class="flex sm:flex-col gap-2 shrink-0">
          <AppButton size="sm" variant="secondary" @click="openMinutes(m)">{{ $t('admin.meetings.minutes') }}</AppButton>
          <AppButton size="sm" variant="ghost" class="text-danger" :loading="cancelling === m.id" @click="cancelMeeting(m)">{{ $t('admin.meetings.cancel') }}</AppButton>
        </div>
      </article>
      <p v-if="data && !data.length" class="text-sm text-muted">{{ $t('common.noData') }}</p>
    </div>

    <Modal :open="open" :title="$t('admin.meetings.schedule')" size="lg" @close="open = false">
      <form class="space-y-4" novalidate @submit.prevent="saveSchedule">
        <FormField v-model="schedule.values.opportunity_uuid" name="opportunity_uuid" type="select" :label="$t('admin.interests.opportunity')" :options="(opps ?? []).map((o) => ({ value: o.uuid, label: `${o.reference_no ?? ''} — ${o.name}` }))" :error="schedule.errors.value.opportunity_uuid" required />
        <FormField v-model="schedule.values.interest_id" name="interest_id" type="select" :label="$t('admin.meetings.interest')" :options="relatedInterests.map((i) => ({ value: String(i.id), label: i.investor?.name ?? String(i.id) }))" :error="schedule.errors.value.interest_id" />
        <FormField v-model="schedule.values.title" name="title" :label="$t('admin.meetings.title_')" :error="schedule.errors.value.title" :maxlength="RULES.titleMax" required />
        <div class="grid sm:grid-cols-2 gap-3">
          <FormField v-model="schedule.values.starts_at" name="starts_at" type="datetime-local" :label="$t('admin.meetings.startsAt')" :error="schedule.errors.value.starts_at" required ltr />
          <FormField v-model="schedule.values.ends_at" name="ends_at" type="datetime-local" :label="$t('admin.meetings.endsAt')" :error="schedule.errors.value.ends_at" required ltr />
        </div>
        <div class="grid sm:grid-cols-2 gap-3">
          <FormField v-model="schedule.values.video_url" name="video_url" type="url" :label="$t('admin.meetings.videoUrl')" :error="schedule.errors.value.video_url" :maxlength="RULES.urlMax" placeholder="https://" ltr />
          <FormField v-model="schedule.values.location" name="location" :label="$t('admin.meetings.place')" :error="schedule.errors.value.location" :maxlength="RULES.urlMax" />
        </div>
        <div>
          <p class="label">{{ $t('admin.meetings.participants') }} <span class="text-danger" aria-hidden="true">*</span></p>
          <p class="text-xs text-muted mb-2">{{ $t('admin.meetings.participantsHint') }}</p>
          <ul class="flex flex-wrap gap-1.5 mb-2">
            <li v-for="p in schedule.values.participants" :key="p.id" class="chip chip-active text-xs !py-0.5 inline-flex items-center gap-1">{{ p.name }} <span class="text-[10px] opacity-70">({{ $t(`enums.meetingRole.${p.role}`) !== `enums.meetingRole.${p.role}` ? $t(`enums.meetingRole.${p.role}`) : p.role }})</span><button type="button" class="ms-1" :aria-label="`${$t('common.delete')} ${p.name}`" @click="removeParticipant(p.id)">×</button></li>
            <li v-if="!schedule.values.participants.length" class="text-xs text-muted">{{ $t('admin.meetings.noParticipants') }}</li>
          </ul>
          <ul v-if="suggestions.length" class="flex flex-wrap gap-1.5 mb-2"><li v-for="p in suggestions" :key="p.id"><button type="button" class="chip text-xs !py-0.5" @click="addParticipant(p)">+ {{ p.name }}</button></li></ul>
          <input v-model="userQuery" class="input text-sm" :class="schedule.errors.value.participants && 'input-error'" :placeholder="$t('admin.meetings.searchUsers')" :aria-label="$t('admin.meetings.searchUsers')" />
          <ul v-if="userResults.length" class="mt-1 card divide-y divide-border max-h-40 overflow-auto"><li v-for="u in userResults" :key="u.id"><button type="button" class="w-full text-start px-3 py-1.5 text-sm hover:bg-bg" @click="addParticipant({ id: u.id, name: u.name, role: u.roles?.length ? 'council' : 'other' }); userQuery = ''; userResults = []">{{ u.name }}</button></li></ul>
          <p v-if="schedule.errors.value.participants" class="error-text" role="alert">{{ schedule.errors.value.participants }}</p>
        </div>
      </form>
      <template #footer><FormAlert :message="schedule.formError.value" class="basis-full" /><AppButton variant="ghost" @click="open = false">{{ $t('common.cancel') }}</AppButton><AppButton :loading="schedule.submitting.value" @click="saveSchedule">{{ $t('admin.meetings.schedule') }}</AppButton></template>
    </Modal>

    <Modal :open="!!minutesFor" :title="$t('admin.meetings.minutes')" size="lg" @close="minutesFor = null">
      <form class="space-y-4" novalidate @submit.prevent="saveMinutes">
        <FormField v-model="minutesForm.values.minutes" name="minutes" type="textarea" :label="$t('admin.meetings.minutes')" :error="minutesForm.errors.value.minutes" :rows="5" :maxlength="RULES.minutesMax" required />
        <div v-if="minutesFor?.participants.some((p) => p.user_id)">
          <p class="label">{{ $t('admin.meetings.attendance') }}</p>
          <div class="flex flex-wrap gap-3"><label v-for="p in minutesFor!.participants.filter((x) => x.user_id)" :key="p.user_id!" class="inline-flex items-center gap-2 text-sm"><input v-model="minutesForm.values.attended" type="checkbox" :value="p.user_id" class="h-4 w-4 rounded border-border text-blue" />{{ p.name }}</label></div>
        </div>
        <div>
          <p class="label">{{ $t('admin.meetings.actions') }}</p>
          <div v-for="(a, i) in minutesForm.values.actions" :key="i" class="mb-2">
            <div class="grid grid-cols-1 sm:grid-cols-[1fr_150px_130px_auto] gap-2">
              <input v-model="a.description" class="input" :class="minutesForm.errors.value[`actions.${i}.description`] && 'input-error'" :maxlength="RULES.shortTextMax" :placeholder="$t('admin.meetings.actionDescription')" :aria-label="$t('admin.meetings.actionDescription')" :aria-invalid="!!minutesForm.errors.value[`actions.${i}.description`]" @blur="minutesForm.validateField(`actions.${i}.description`)" />
              <select v-model="a.owner_user_id" class="input" :aria-label="$t('admin.meetings.actionOwner')"><option value="">{{ $t('admin.meetings.actionOwner') }}</option><option v-for="o in ownerOptions" :key="o.value" :value="o.value === 'me' ? 'me' : Number(o.value)">{{ o.label }}</option></select>
              <input v-model="a.due_at" type="date" class="input ltr" :aria-label="$t('admin.meetings.actionDue')" />
              <AppButton variant="ghost" size="sm" icon :aria-label="`${$t('common.delete')} ${a.description}`" @click="removeAction(i)">×</AppButton>
            </div>
            <p v-if="minutesForm.errors.value[`actions.${i}.description`]" class="error-text" role="alert">{{ minutesForm.errors.value[`actions.${i}.description`] }}</p>
          </div>
          <p v-if="minutesForm.errors.value.actions" class="error-text" role="alert">{{ minutesForm.errors.value.actions }}</p>
          <AppButton size="sm" variant="outline" :disabled="minutesForm.values.actions.length >= RULES.meetingActionsMax" @click="addAction">+ {{ $t('common.add') }}</AppButton>
        </div>
      </form>
      <template #footer><FormAlert :message="minutesForm.formError.value" class="basis-full" /><AppButton variant="ghost" @click="minutesFor = null">{{ $t('common.cancel') }}</AppButton><AppButton :loading="minutesForm.submitting.value" @click="saveMinutes">{{ $t('admin.meetings.saveMinutes') }}</AppButton></template>
    </Modal>
  </div>
</template>

<script setup lang="ts">
import type { Meeting, Paginated, QueueItem, Interest } from '~/types/api'
definePageMeta({ layout: 'portal', middleware: ['role'], roles: ['matching_officer', 'impact_officer'] })
const { t } = useI18n()
useHead({ title: t('admin.meetings.metaTitle') })
const api = useApi()
const toast = useToast()
const { data, loading, refresh } = useAsyncResource(() => api.get<Paginated<Meeting>>('/admin/meetings', { per_page: 50 }).then((r) => r.data))
const { data: opps } = useAsyncResource(() => api.get<Paginated<QueueItem>>('/admin/opportunities', { per_page: 100 }).then((r) => r.data))
const { data: interests } = useAsyncResource(() => api.get<Paginated<Interest>>('/admin/interests', { per_page: 100 }).then((r) => r.data))
const open = ref(false)
const form = reactive({ opportunity_uuid: '', interest_id: '', title: '', starts_at: '', ends_at: '', video_url: '', location: '' })
const minutesFor = ref<Meeting | null>(null); const minutes = ref(''); const actions = ref<{ description: string; owner: string; due_at: string; done_at: null }[]>([])
const busy = ref(false)
const schedule = async () => { busy.value = true; try { await api.post('/admin/meetings', { ...form, interest_id: form.interest_id || undefined, starts_at: new Date(form.starts_at).toISOString(), ends_at: form.ends_at ? new Date(form.ends_at).toISOString() : undefined }); open.value = false; toast.success(t('common.success')); await refresh() } catch (e) { toast.error((e as Error).message) } finally { busy.value = false } }
const saveMinutes = async () => { if (!minutesFor.value) return; busy.value = true; try { await api.post(`/admin/meetings/${minutesFor.value.id}/minutes`, { minutes: minutes.value, actions: actions.value }); minutesFor.value = null; toast.success(t('common.success')); await refresh() } catch (e) { toast.error((e as Error).message) } finally { busy.value = false } }
const relatedInterests = computed(() => (interests.value ?? []).filter((i) => i.opportunity.uuid === form.opportunity_uuid && ['connected', 'meeting', 'shared'].includes(i.status)))
</script>
<template>
  <div>
    <PageHeader :title="$t('admin.meetings.title')" :sub="$t('admin.meetings.sub')"><template #actions><AppButton @click="open = true">{{ $t('admin.meetings.schedule') }}</AppButton></template></PageHeader>
    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <div v-else class="space-y-3">
      <article v-for="m in data" :key="m.id" class="card card-pad flex flex-col sm:flex-row gap-3">
        <div class="flex-1 min-w-0"><div class="flex flex-wrap items-center gap-2"><AppBadge kind="meeting" :value="m.status" /><span class="text-sm text-muted num">{{ useFormat().dateTime(m.starts_at) }}</span></div><h3 class="mt-1 font-semibold">{{ m.title }}</h3><p class="text-sm text-muted">{{ m.opportunity.name }} · {{ m.participants.map((p) => p.name).join('، ') }}</p><p v-if="m.minutes" class="mt-2 text-sm rounded-lg bg-bg p-2">{{ m.minutes }}</p></div>
        <AppButton v-if="m.status === 'scheduled'" size="sm" variant="secondary" @click="minutesFor = m; minutes = ''; actions = []">{{ $t('admin.meetings.minutes') }}</AppButton>
      </article>
    </div>
    <Modal :open="open" :title="$t('admin.meetings.schedule')" @close="open = false">
      <div class="space-y-4">
        <FormField v-model="form.opportunity_uuid" name="opp" type="select" :label="$t('admin.interests.opportunity')" :options="(opps ?? []).map((o) => ({ value: o.uuid, label: `${o.reference_no} — ${o.name}` }))" required />
        <FormField v-model="form.interest_id" name="interest" type="select" :label="$t('admin.meetings.interest')" :options="relatedInterests.map((i) => ({ value: String(i.id), label: i.investor?.name ?? String(i.id) }))" />
        <FormField v-model="form.title" name="title" :label="$t('admin.meetings.title_')" required />
        <div class="grid sm:grid-cols-2 gap-3"><FormField v-model="form.starts_at" name="starts" type="datetime-local" :label="$t('admin.meetings.startsAt')" required ltr /><FormField v-model="form.ends_at" name="ends" type="datetime-local" :label="$t('admin.meetings.endsAt')" ltr /></div>
        <FormField v-model="form.video_url" name="video" type="url" :label="$t('admin.meetings.location')" ltr />
      </div>
      <template #footer><AppButton variant="ghost" @click="open = false">{{ $t('common.cancel') }}</AppButton><AppButton :loading="busy" :disabled="!form.opportunity_uuid || !form.title || !form.starts_at" @click="schedule">{{ $t('admin.meetings.schedule') }}</AppButton></template>
    </Modal>
    <Modal :open="!!minutesFor" :title="$t('admin.meetings.minutes')" @close="minutesFor = null">
      <div class="space-y-4">
        <FormField v-model="minutes" name="minutes" type="textarea" :label="$t('admin.meetings.minutes')" :rows="5" required />
        <div><p class="label">{{ $t('admin.meetings.actions') }}</p><div v-for="(a, i) in actions" :key="i" class="grid grid-cols-[1fr_120px_130px] gap-2 mb-2"><input v-model="a.description" class="input" :placeholder="$t('admin.meetings.actions')" /><input v-model="a.owner" class="input" :placeholder="$t('admin.dashboard.owner')" /><input v-model="a.due_at" type="date" class="input ltr" /></div><AppButton size="sm" variant="outline" @click="actions.push({ description: '', owner: '', due_at: '', done_at: null })">+ {{ $t('common.add') }}</AppButton></div>
      </div>
      <template #footer><AppButton variant="ghost" @click="minutesFor = null">{{ $t('common.cancel') }}</AppButton><AppButton :loading="busy" :disabled="!minutes" @click="saveMinutes">{{ $t('admin.meetings.saveMinutes') }}</AppButton></template>
    </Modal>
  </div>
</template>

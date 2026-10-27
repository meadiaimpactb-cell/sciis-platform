<script setup lang="ts">
/**
 * MTG-04 — event registration & attendance (events stay CMS items; content is edited in /app/admin/content).
 * Per event: turn on-platform registration on/off, capacity, deadline, manual close (full → closed automatically);
 * registrants with search / status filter, attendance marking, cancel / restore, export to Excel.
 * GET /admin/events · PUT /admin/events/{id}/registration-settings · GET /admin/events/{id}/registrations
 * · PATCH /admin/event-registrations/{id} · GET /admin/events/{id}/registrations/export.xlsx
 */
import { z } from 'zod'
import type { Paginated } from '~/types/api'
import type { AdminEventSummary, EventRegistration } from '~/types/collab'
import { optionalNumber } from '~/utils/validation'
import { confirmDialog, toastApiError, toastInfo, toastSuccess } from '~/utils/notify'
definePageMeta({ layout: 'portal', middleware: ['role'], roles: [] })
const { t } = useI18n()
useHead({ title: t('collab.adminEvents.metaTitle') })
const api = useApi()
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const { date, dateTime, number } = useFormat()
const allowed = computed(() => auth.canAny('settings.manage', 'meetings.manage'))

// ---------------------------------------------------------------- events
const events = ref<AdminEventSummary[]>([])
const eventsMeta = ref({ last_page: 1, total: 0 })
const eventsPage = ref(1)
const loadingEvents = ref(false)
const loadEvents = async () => {
  loadingEvents.value = true
  try {
    const r = await api.get<Paginated<AdminEventSummary>>('/admin/events', { page: eventsPage.value, per_page: 20 })
    events.value = r.data ?? []
    eventsMeta.value = { last_page: r.meta?.last_page ?? 1, total: r.meta?.total ?? events.value.length }
    const wanted = Number(route.query.event)
    if (wanted && !selected.value) selected.value = events.value.find((e) => e.id === wanted) ?? null
  } catch (e) {
    toastApiError(e)
  } finally {
    loadingEvents.value = false
  }
}
onMounted(() => { if (allowed.value) void loadEvents() })
watch(eventsPage, loadEvents)
const replaceEvent = (ev: AdminEventSummary) => {
  events.value = events.value.map((x) => (x.id === ev.id ? ev : x))
  if (selected.value?.id === ev.id) selected.value = ev
}
const stateLabel = (ev: AdminEventSummary) => {
  const r = ev.registration
  if (!r.enabled) return ev.registration_url ? t('collab.adminEvents.external') : t('collab.adminEvents.off')
  return r.open ? t('collab.adminEvents.open') : t(`collab.events.reasons.${r.reason ?? 'closed'}`)
}
const eventColumns = [
  { key: 'title', label: t('collab.adminEvents.event') },
  { key: 'state', label: t('collab.adminEvents.registration') },
  { key: 'registered', label: t('collab.adminEvents.registered') },
  { key: 'attended', label: t('collab.adminEvents.attended') },
  { key: 'actions', label: '' },
]

// ---------------------------------------------------------------- settings (enabled / capacity / deadline / closed)
const editing = ref<AdminEventSummary | null>(null)
const pad = (n: number) => String(n).padStart(2, '0')
const toLocalInput = (iso: string | null) => { if (!iso) return ''; const d = new Date(iso); return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}` }
const settings = useZodForm(z.object({ enabled: z.boolean(), capacity: optionalNumber({ min: 1, max: 100000, int: true }), deadline: z.string(), closed: z.boolean() }), { enabled: false, capacity: null as number | null, deadline: '', closed: false })
const openSettings = (ev: AdminEventSummary) => {
  editing.value = ev
  settings.reset({ enabled: ev.registration.enabled, capacity: ev.registration.capacity, deadline: toLocalInput(ev.registration.deadline), closed: ev.registration.closed })
}
const saveSettings = () => settings.submit(async (v) => {
  const ev = editing.value
  if (!ev) return
  const r = await api.put<{ data: AdminEventSummary }>(`/admin/events/${ev.id}/registration-settings`, { enabled: v.enabled, capacity: v.capacity ?? null, deadline: v.deadline ? new Date(v.deadline).toISOString() : null, closed: v.closed })
  replaceEvent(r.data)
  editing.value = null
  toastSuccess(t('notify.updated', { item: t('collab.adminEvents.settingsEntity') }))
})

// ---------------------------------------------------------------- registrants
const selected = ref<AdminEventSummary | null>(null)
const f = reactive({ search: '', status: '' })
const page = ref(1)
const rows = ref<EventRegistration[]>([])
const meta = ref({ last_page: 1, total: 0, summary: { registered: 0, cancelled: 0, attended: 0 } })
const loadingRows = ref(false)
const loadRows = async () => {
  if (!selected.value) return
  loadingRows.value = true
  try {
    const r = await api.get<{ data: EventRegistration[]; meta?: { last_page?: number; total?: number; summary?: { registered: number; cancelled: number; attended: number } } }>(`/admin/events/${selected.value.id}/registrations`, { page: page.value, per_page: 25, search: f.search.trim() || undefined, status: f.status || undefined })
    rows.value = r.data ?? []
    meta.value = { last_page: r.meta?.last_page ?? 1, total: r.meta?.total ?? rows.value.length, summary: r.meta?.summary ?? meta.value.summary }
  } catch (e) {
    toastApiError(e)
  } finally {
    loadingRows.value = false
  }
}
const pick = (ev: AdminEventSummary) => {
  selected.value = ev
  router.replace({ query: { ...route.query, event: String(ev.id) } })
}
/** Back to page 1 (the page watcher reloads) or reload the current first page. */
const firstPage = () => { if (page.value !== 1) page.value = 1; else void loadRows() }
watch(selected, (ev, prev) => { if (ev?.id !== prev?.id) firstPage() })
watch(page, loadRows)
watch(() => f.status, firstPage)
let st: ReturnType<typeof setTimeout>
watch(() => f.search, () => { clearTimeout(st); st = setTimeout(firstPage, 300) })

const busy = ref<number | null>(null)
const patchRow = async (r: EventRegistration, body: { attended?: boolean; status?: 'registered' | 'cancelled' }, ok: string) => {
  busy.value = r.id
  try {
    const res = await api.patch<{ data: EventRegistration }>(`/admin/event-registrations/${r.id}`, body)
    rows.value = rows.value.map((x) => (x.id === r.id ? res.data : x))
    toastSuccess(ok)
    void loadEvents() // counters / open state
  } catch (e) {
    toastApiError(e)
  } finally {
    busy.value = null
  }
}
const toggleAttended = (r: EventRegistration) => patchRow(r, { attended: !r.attended }, t(r.attended ? 'collab.adminEvents.unmarked' : 'collab.adminEvents.marked', { name: r.name }))
const toggleCancelled = async (r: EventRegistration) => {
  const cancel = r.status === 'registered'
  if (cancel && !(await confirmDialog(t('notify.cancelTitle'), t('notify.cancelText', { name: r.name }), t('notify.cancelConfirm')))) return
  await patchRow(r, { status: cancel ? 'cancelled' : 'registered' }, t(cancel ? 'collab.adminEvents.cancelledRow' : 'collab.adminEvents.restoredRow', { name: r.name }))
}
const exportXlsx = () => {
  if (!selected.value) return
  const path = `/admin/events/${selected.value.id}/registrations/export.xlsx`
  const qs = new URLSearchParams(Object.entries({ search: f.search.trim(), status: f.status }).filter(([, v]) => v)).toString()
  window.open(`${api.baseURL}${path}${qs ? `?${qs}` : ''}`, '_blank', 'noopener')
  toastInfo(t('notify.preparing'))
}
const rowColumns = [
  { key: 'name', label: t('common.name'), sortable: true },
  { key: 'phone', label: t('common.phone') },
  { key: 'organization', label: t('collab.events.organization') },
  { key: 'created_at', label: t('collab.adminEvents.registeredAt'), sortable: true },
  { key: 'attended', label: t('collab.adminEvents.attendance') },
  { key: 'status', label: t('common.status') },
]
</script>

<template>
  <div>
    <PageHeader :title="$t('collab.adminEvents.title')" :sub="$t('collab.adminEvents.sub')"><template #actions><AppButton v-if="auth.canAny('settings.manage')" variant="secondary" size="sm" to="/app/admin/content">{{ $t('collab.adminEvents.editContent') }}</AppButton></template></PageHeader>
    <EmptyState v-if="!allowed" :title="$t('notify.forbidden')" />
    <div v-else class="space-y-6">
      <DataTable :columns="eventColumns" :rows="events" :loading="loadingEvents" :empty-text="$t('events.empty')">
        <template #cell-title="{ row }"><span class="font-medium">{{ row.title }}</span><span class="block text-xs text-muted num">{{ dateTime(row.starts_at) }}<template v-if="row.location"> · {{ row.location }}</template></span><span v-if="!row.is_published" class="mt-1 inline-flex rounded-full border border-warning/30 bg-warning/10 px-2 py-px text-xs text-warning">{{ $t('collab.adminEvents.unpublished') }}</span></template>
        <template #cell-state="{ row }"><AppBadge kind="generic" :value="row.registration.enabled && row.registration.open ? 'active' : row.registration.enabled ? 'pending' : 'withdrawn'" :label="stateLabel(row as AdminEventSummary)" /><span v-if="row.registration.deadline" class="block text-xs text-muted mt-1">{{ $t('collab.events.deadline') }}: <span class="num">{{ date(row.registration.deadline) }}</span></span></template>
        <template #cell-registered="{ row }"><span class="num">{{ number(row.registration.registered_count) }}<template v-if="row.registration.capacity"> / {{ number(row.registration.capacity) }}</template></span></template>
        <template #cell-attended="{ row }"><span class="num">{{ number(row.registration.attended_count) }}</span></template>
        <template #cell-actions="{ row }"><div class="flex gap-1 justify-end"><AppButton size="sm" variant="outline" @click="openSettings(row as AdminEventSummary)">{{ $t('collab.adminEvents.settings') }}</AppButton><AppButton size="sm" :variant="selected?.id === row.id ? 'primary' : 'ghost'" :disabled="!row.registration.enabled && !row.registration.registered_count" @click="pick(row as AdminEventSummary)">{{ $t('collab.adminEvents.registrants') }}</AppButton></div></template>
        <template #footer><Pagination v-model:page="eventsPage" :last-page="eventsMeta.last_page" :total="eventsMeta.total" /></template>
      </DataTable>

      <section v-if="selected">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-3">
          <div>
            <h2 class="card-title">{{ $t('collab.adminEvents.registrantsOf', { title: selected.title }) }}</h2>
            <p class="text-sm text-muted mt-0.5 num">{{ $t('collab.adminEvents.summary', { registered: number(meta.summary.registered), attended: number(meta.summary.attended), cancelled: number(meta.summary.cancelled) }) }}</p>
          </div>
          <AppButton variant="secondary" size="sm" @click="exportXlsx">{{ $t('common.exportExcel') }}</AppButton>
        </div>
        <div class="card p-3 mb-4 grid sm:grid-cols-[2fr_1fr] gap-3">
          <input v-model="f.search" type="search" class="input" :placeholder="$t('collab.adminEvents.search')" :aria-label="$t('common.search')">
          <select v-model="f.status" class="input" :aria-label="$t('common.status')"><option value="">{{ $t('common.status') }}: {{ $t('common.all') }}</option><option value="registered">{{ $t('collab.adminEvents.statuses.registered') }}</option><option value="cancelled">{{ $t('collab.adminEvents.statuses.cancelled') }}</option></select>
        </div>
        <DataTable :columns="rowColumns" :rows="rows" :loading="loadingRows" :empty-text="$t('collab.adminEvents.noRegistrants')">
          <template #cell-name="{ row }"><span class="font-medium">{{ row.name }}</span><AppBadge v-if="row.is_member" kind="generic" value="new" :label="$t('collab.adminEvents.member')" :dot="false" class="ms-1" /><span class="block text-xs text-muted ltr text-start" dir="ltr">{{ row.email }}</span></template>
          <template #cell-phone="{ value }"><span class="num ltr" dir="ltr">{{ value || '—' }}</span></template>
          <template #cell-organization="{ value }">{{ value || '—' }}</template>
          <template #cell-created_at="{ value }"><span class="num text-sm">{{ dateTime(value) }}</span></template>
          <template #cell-attended="{ row }"><label class="inline-flex items-center gap-2 text-sm cursor-pointer"><input type="checkbox" class="h-4 w-4 accent-accent" :checked="row.attended" :disabled="busy === row.id || row.status === 'cancelled'" :aria-label="`${$t('collab.adminEvents.attendance')} — ${row.name}`" @change="toggleAttended(row as EventRegistration)">{{ row.attended ? $t('collab.adminEvents.present') : $t('collab.adminEvents.absent') }}</label></template>
          <template #cell-status="{ row }"><div class="flex items-center gap-2"><AppBadge kind="generic" :value="row.status === 'registered' ? 'active' : 'withdrawn'" :label="$t(`collab.adminEvents.statuses.${row.status}`)" /><AppButton size="sm" variant="ghost" :loading="busy === row.id" @click="toggleCancelled(row as EventRegistration)">{{ row.status === 'registered' ? $t('collab.adminEvents.cancelRow') : $t('collab.adminEvents.restoreRow') }}</AppButton></div></template>
          <template #footer><Pagination v-model:page="page" :last-page="meta.last_page" :total="meta.total" /></template>
        </DataTable>
      </section>
    </div>

    <Modal :open="!!editing" :title="$t('collab.adminEvents.settingsTitle')" @close="editing = null">
      <form class="space-y-4" novalidate @submit.prevent="saveSettings">
        <p class="text-sm text-muted">{{ editing?.title }}</p>
        <FormField v-model="settings.values.enabled" name="enabled" type="checkbox" :label="$t('collab.adminEvents.enable')" :help="$t('collab.adminEvents.enableHelp')" />
        <div class="grid sm:grid-cols-2 gap-3">
          <FormField v-model="settings.values.capacity" name="capacity" type="number" :label="$t('collab.adminEvents.capacity')" :help="$t('collab.adminEvents.capacityHelp')" :min="1" :error="settings.errors.value.capacity" :disabled="!settings.values.enabled" />
          <FormField v-model="settings.values.deadline" name="deadline" type="datetime-local" :label="$t('collab.events.deadline')" :help="$t('collab.adminEvents.deadlineHelp')" :error="settings.errors.value.deadline" :disabled="!settings.values.enabled" ltr />
        </div>
        <FormField v-model="settings.values.closed" name="closed" type="checkbox" :label="$t('collab.adminEvents.closeNow')" :help="$t('collab.adminEvents.closeNowHelp')" :disabled="!settings.values.enabled" />
        <InlineAlert v-if="editing?.registration_url" tone="info">{{ $t('collab.adminEvents.externalNote') }}</InlineAlert>
      </form>
      <template #footer><FormAlert :message="settings.formError.value" class="basis-full" /><AppButton variant="ghost" @click="editing = null">{{ $t('common.cancel') }}</AppButton><AppButton :loading="settings.submitting.value" @click="saveSettings">{{ $t('common.save') }}</AppButton></template>
    </Modal>
  </div>
</template>

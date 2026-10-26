<script setup lang="ts">
/**
 * OPP-08 — the investor's saved catalogue searches: save the current filters under a name, apply one, delete it.
 * OPP-07 — alerts: a bell per search (`notify`) and the "any new opportunity matching my profile" switch; the API sends
 * them daily (in-app + e-mail per the notification preferences).
 */
import { z } from 'zod'
import { BROWSE_FACETS, type SavedSearch, type SavedSearchFilters, type SavedSearchesResponse } from '~/types/discovery'
import { requiredString } from '~/utils/validation'
import { confirmDialog, toastApiError, toastSuccess, toastWarning } from '~/utils/notify'

/** Mirrors SavedFilterRequest (name 2..120). */
const NAME_MIN = 2
const NAME_MAX = 120

const props = defineProps<{ current: SavedSearchFilters }>()
const emit = defineEmits<{ (e: 'apply', filters: SavedSearchFilters): void }>()
const { t } = useI18n()
const { number } = useFormat()
const api = useApi()

const state = ref<SavedSearchesResponse>({ filters: [], profile_alerts: false, max: 20 })
const loaded = ref(false)
const busy = ref<string | null>(null)
const load = async () => {
  try {
    state.value = (await api.get<{ data: SavedSearchesResponse }>('/investor/saved-filters')).data
  } catch {
    // The catalogue keeps working without its saved searches.
  } finally { loaded.value = true }
}
onMounted(load)

/** Same normalisation as the API: facets + search + nominated (+ sort), empty values dropped. */
const normalize = (f: SavedSearchFilters): SavedSearchFilters => {
  const out: SavedSearchFilters = {}
  for (const k of BROWSE_FACETS) if (f[k]) out[k] = String(f[k])
  if (f.search?.trim()) out.search = f.search.trim()
  if (f.nominated) out.nominated = true
  if (f.sort && f.sort !== 'newest') out.sort = f.sort
  return out
}
const signature = (f: SavedSearchFilters) => JSON.stringify(Object.entries(normalize(f)).sort(([a], [b]) => a.localeCompare(b)))
const currentSignature = computed(() => signature(props.current))
const hasCriteria = computed(() => BROWSE_FACETS.some((k) => !!props.current[k]) || !!props.current.search?.trim())
const atMax = computed(() => state.value.filters.length >= state.value.max)

// ---------------------------------------------------------------- save the current search
const saveOpen = ref(false)
const form = useZodForm(
  z.object({ name: requiredString('investor.opportunities.saved.name', NAME_MIN, NAME_MAX), notify: z.boolean() }),
  { name: '', notify: true },
)
const openSave = () => {
  if (atMax.value) { toastWarning(t('investor.opportunities.saved.limit', { n: state.value.max })); return }
  form.reset()
  saveOpen.value = true
}
const save = () => form.submit(async (v) => {
  await api.post('/investor/saved-filters', { name: v.name.trim(), filters: normalize(props.current), notify: v.notify })
  saveOpen.value = false
  toastSuccess(t('investor.opportunities.saved.savedToast', { name: v.name.trim() }))
  await load()
})

// ---------------------------------------------------------------- per search
const toggleNotify = async (f: SavedSearch) => {
  busy.value = `n${f.id}`
  try {
    await api.patch(`/investor/saved-filters/${f.id}`, { notify: !f.notify })
    f.notify = !f.notify
    toastSuccess(t(f.notify ? 'investor.opportunities.saved.notifyOn' : 'investor.opportunities.saved.notifyOff', { name: f.name }))
  } catch (e) { toastApiError(e) } finally { busy.value = null }
}
const remove = async (f: SavedSearch) => {
  if (!(await confirmDialog(t('investor.opportunities.saved.deleteTitle'), t('investor.opportunities.saved.deleteText', { name: f.name }), t('common.delete')))) return
  busy.value = `d${f.id}`
  try {
    await api.del(`/investor/saved-filters/${f.id}`)
    state.value.filters = state.value.filters.filter((x) => x.id !== f.id)
    toastSuccess(t('investor.opportunities.saved.deletedToast', { name: f.name }))
  } catch (e) { toastApiError(e) } finally { busy.value = null }
}

// ---------------------------------------------------------------- OPP-07 profile-match alerts
const toggleProfile = async (ev: Event) => {
  const box = ev.target as HTMLInputElement
  busy.value = 'profile'
  try {
    await api.put('/investor/alerts/profile', { enabled: box.checked })
    state.value.profile_alerts = box.checked
    toastSuccess(t(box.checked ? 'investor.opportunities.saved.profileOn' : 'investor.opportunities.saved.profileOff'))
  } catch (e) {
    box.checked = state.value.profile_alerts
    toastApiError(e)
  } finally { busy.value = null }
}
</script>

<template>
  <section class="card p-4" :aria-label="$t('investor.opportunities.saved.title')">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <h2 class="text-sm font-semibold text-ink">{{ $t('investor.opportunities.saved.title') }}</h2>
        <p class="text-xs text-muted mt-0.5">{{ $t('investor.opportunities.saved.sub') }}</p>
      </div>
      <AppButton size="sm" variant="outline" :disabled="!hasCriteria" :title="hasCriteria ? undefined : $t('investor.opportunities.saved.needCriteria')" @click="openSave">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 4h12v17l-6-4-6 4V4z" /><path d="M12 8v6M9 11h6" /></svg>
        {{ $t('investor.opportunities.saved.saveCurrent') }}
      </AppButton>
    </div>

    <ul v-if="state.filters.length" class="mt-3 flex flex-wrap gap-2">
      <li v-for="f in state.filters" :key="f.id" class="inline-flex items-center rounded-full border text-sm transition" :class="signature(f.filters) === currentSignature ? 'border-navy bg-navy-50' : 'border-border bg-surface'">
        <button type="button" class="ps-3 pe-2 py-1.5 inline-flex items-center gap-1.5 rounded-s-full kbd-focus hover:text-navy" :aria-pressed="signature(f.filters) === currentSignature" @click="emit('apply', f.filters)">
          <span class="font-medium text-ink truncate max-w-[12rem]">{{ f.name }}</span>
          <span class="num text-xs text-muted">{{ number(f.count) }}</span>
        </button>
        <button type="button" class="h-8 w-8 inline-flex items-center justify-center kbd-focus rounded-full disabled:opacity-50" :class="f.notify ? 'text-teal-text' : 'text-muted hover:text-ink'" :aria-pressed="f.notify" :aria-label="$t(f.notify ? 'investor.opportunities.saved.notifyOffAria' : 'investor.opportunities.saved.notifyOnAria', { name: f.name })" :title="$t(f.notify ? 'investor.opportunities.saved.notifyOnHint' : 'investor.opportunities.saved.notifyOffHint')" :disabled="busy === `n${f.id}`" @click="toggleNotify(f)">
          <svg class="h-4 w-4" viewBox="0 0 24 24" :fill="f.notify ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9" /><path d="M13.7 21a2 2 0 01-3.4 0" /></svg>
        </button>
        <button type="button" class="h-8 w-8 me-0.5 inline-flex items-center justify-center kbd-focus rounded-full text-muted hover:text-danger disabled:opacity-50" :aria-label="$t('investor.opportunities.saved.deleteAria', { name: f.name })" :disabled="busy === `d${f.id}`" @click="remove(f)">
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
        </button>
      </li>
    </ul>
    <p v-else-if="loaded" class="mt-3 text-xs text-muted">{{ $t('investor.opportunities.saved.empty') }}</p>

    <label class="mt-3 pt-3 border-t border-border flex items-start gap-2.5 text-sm cursor-pointer">
      <input type="checkbox" class="mt-0.5 h-4 w-4 rounded border-border text-blue focus:ring-blue" :checked="state.profile_alerts" :disabled="!loaded || busy === 'profile'" @change="toggleProfile" />
      <span><span class="font-medium text-ink">{{ $t('investor.opportunities.saved.profileAlerts') }}</span><span class="block text-xs text-muted mt-0.5">{{ $t('investor.opportunities.saved.profileAlertsHint') }}</span></span>
    </label>

    <Modal :open="saveOpen" :title="$t('investor.opportunities.saved.modalTitle')" size="sm" @close="saveOpen = false">
      <form class="space-y-4" novalidate @submit.prevent="save">
        <FormField v-model="form.values.name" name="name" :label="$t('investor.opportunities.saved.name')" :error="form.errors.value.name" :maxlength="NAME_MAX" required autocomplete="off" />
        <FormField v-model="form.values.notify" name="notify" type="checkbox" :label="$t('investor.opportunities.saved.notify')" :help="$t('investor.opportunities.saved.notifyHelp')" />
      </form>
      <template #footer><FormAlert :message="form.formError.value" class="basis-full" /><AppButton variant="ghost" @click="saveOpen = false">{{ $t('common.cancel') }}</AppButton><AppButton :loading="form.submitting.value" @click="save">{{ $t('common.save') }}</AppButton></template>
    </Modal>
  </section>
</template>

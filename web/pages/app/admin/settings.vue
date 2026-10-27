<script setup lang="ts">
/** ADM-03 settings — GET returns `{settings, features}` (Laravel) or a bare array (older mock); PUT sends `{settings:{key:value}}` (#27). */
import { z } from 'zod'
import type { SettingItem, SettingsResponse, TaxonomyTerm } from '~/types/api'
import { TAXONOMY_GROUPS, type TaxonomyGroup } from '~/types/enums'
import { optionalText, parseApiError, requiredString, RULES, termKeyField } from '~/utils/validation'
import { confirmDialog, toastApiError, toastError, toastSuccess } from '~/utils/notify'
definePageMeta({ layout: 'portal', middleware: ['role'], roles: [] })
const { t, te } = useI18n()
useHead({ title: t('admin.settings.metaTitle') })
const api = useApi()
const tax = useTaxonomyStore()
await tax.load().catch(() => {})
const tab = ref('keys')
const tabs = [{ key: 'keys', label: t('admin.settings.tabs.keys') }, { key: 'taxonomies', label: t('admin.settings.tabs.taxonomies') }, { key: 'templates', label: t('admin.settings.tabs.templates') }, { key: 'forms', label: t('platform.builder.tab') }, { key: 'system', label: t('admin.settings.tabs.system') }]
const { data: payload, refresh } = useAsyncResource(() => api.get<{ data: SettingsResponse | SettingItem[] }>('/admin/settings').then((r): SettingsResponse => (Array.isArray(r.data) ? { settings: r.data, features: {} } : { settings: r.data.settings ?? [], features: r.data.features ?? {} })))
const settings = computed(() => payload.value?.settings ?? [])
const features = computed(() => Object.entries(payload.value?.features ?? {}))
const groups = computed(() => Array.from(new Set(settings.value.map((s) => s.group))))
const groupLabel = (g: string) => (te(`admin.settings.groups.${g}`) ? t(`admin.settings.groups.${g}`) : g)
/** Localized description of a key when the UI knows it (`admin.settingKeys.<key with dots as _>`), else the stored one. */
const keyLabel = (s: SettingItem) => { const k = `admin.settingKeys.${s.key.replace(/\./g, '_')}`; return te(k) ? t(k) : s.description }
const display = (v: unknown) => (typeof v === 'string' ? v : v == null ? '' : JSON.stringify(v))

// ---------------------------------------------------------------- setting keys
// A dynamic key/value grid: every row saves on its own. The per-key rules (enums, ranges, e-mails, https URLs) live
// in UpdateSettingsRequest — its 422 message (`settings.<key>`) is shown under the row, anything else is a toast.
const edits = reactive<Record<string, string>>({})
const rowErrors = reactive<Record<string, string>>({})
const savingKey = ref<string | null>(null)
const onEdit = (key: string, v: string) => { edits[key] = v; delete rowErrors[key] }
const saveKey = async (s: SettingItem) => {
  const raw = edits[s.key] ?? ''
  if (!raw.trim() && typeof s.value !== 'string' && s.value != null) { rowErrors[s.key] = t('admin.settings.valueRequired'); return }
  let value: unknown = raw
  try { value = JSON.parse(raw) } catch { value = raw }
  savingKey.value = s.key
  try {
    await api.put('/admin/settings', { settings: { [s.key]: value } })
    delete edits[s.key]
    delete rowErrors[s.key]
    toastSuccess(t('notify.savedItem', { item: `${t('notify.entities.settings')} (${s.key})` }))
    await refresh()
  } catch (e) {
    const p = parseApiError(e)
    const msg = p.errors[`settings.${s.key}`] ?? Object.values(p.errors)[0]
    if (msg) rowErrors[s.key] = msg
    else toastApiError(e)
  } finally { savingKey.value = null }
}

// ---------------------------------------------------------------- taxonomies (TaxonomyTermRequest)
const group = ref<TaxonomyGroup>('sectors')
const termForm = useZodForm(
  z.object({ term_key: termKeyField, label_ar: requiredString('admin.settings.labelAr', 1, RULES.titleMax), label_en: optionalText(RULES.titleMax) }),
  { term_key: '', label_ar: '', label_en: '' },
  { aliases: { key: 'term_key' } },
)
watch(group, () => termForm.reset())
const addTerm = () => termForm.submit(async (v) => {
  await api.post(`/admin/taxonomies/${group.value}`, { key: v.term_key.trim(), label_ar: v.label_ar.trim(), label_en: v.label_en?.trim() || null })
  termForm.reset()
  toastSuccess(t('notify.created', { item: t('notify.entities.taxonomy') }))
  await tax.load(true)
})
const termName = (x: TaxonomyTerm) => x.label_ar || x.key
/** Deactivating hides the term from every list (DELETE /admin/taxonomies/… is the same soft deactivation server-side). */
const toggleTerm = async (x: TaxonomyTerm, ev: Event) => {
  const box = ev.target as HTMLInputElement
  const deactivate = x.is_active
  if (deactivate && !(await confirmDialog(t('notify.deactivateTitle'), t('notify.deactivateText', { name: termName(x) }), t('notify.deactivateConfirm')))) {
    box.checked = x.is_active
    return
  }
  try {
    await api.patch(`/admin/taxonomies/${group.value}/${x.id}`, { is_active: !deactivate })
    await tax.load(true)
    toastSuccess(t(deactivate ? 'admin.settings.termDeactivated' : 'admin.settings.termActivated', { name: termName(x) }))
  } catch (e) {
    box.checked = x.is_active
    toastApiError(e)
  }
}
const labelArField = requiredString('admin.settings.labelAr', 1, RULES.titleMax)
const renameTerm = async (x: TaxonomyTerm, ev: Event) => {
  const input = ev.target as HTMLInputElement
  const labelAr = input.value.trim()
  if (labelAr === x.label_ar) return
  const check = labelArField.safeParse(labelAr)
  if (!check.success) {
    toastError(check.error.issues[0]?.message ?? '')
    input.value = x.label_ar
    return
  }
  try {
    await api.patch(`/admin/taxonomies/${group.value}/${x.id}`, { label_ar: labelAr })
    await tax.load(true)
    toastSuccess(t('notify.updated', { item: `${t('notify.entities.taxonomy')} «${labelAr}»` }))
  } catch (e) {
    input.value = x.label_ar
    toastApiError(e)
  }
}
</script>
<template>
  <div>
    <PageHeader :title="$t('admin.settings.title')" :sub="$t('admin.settings.sub')" />
    <Tabs v-model="tab" :tabs="tabs" variant="pills" class="w-fit" />
    <div v-show="tab === 'keys'" class="mt-5 space-y-5">
      <SectionCard v-for="g in groups" :key="g" :title="groupLabel(g)" flush>
        <div class="overflow-x-auto">
          <table class="table-base"><thead><tr><th>{{ $t('admin.settings.key') }}</th><th>{{ $t('admin.settings.value') }}</th><th>{{ $t('admin.settings.description') }}</th><th></th></tr></thead>
            <tbody><tr v-for="s in settings.filter((x) => x.group === g)" :key="s.key"><td class="num text-xs text-ink">{{ s.key }} <span v-if="s.is_public" class="ms-1 text-xs rounded-full border border-success/30 bg-success/10 text-success px-2 py-px" :title="$t('admin.settings.isPublicHint')">{{ $t('admin.settings.isPublic') }}</span><span v-if="s.pending_legal" class="ms-1 text-xs rounded-full border border-warning/30 bg-warning/10 text-warning px-2 py-px">{{ $t('app.pendingDecision') }}</span></td><td class="min-w-[12rem]"><input :value="edits[s.key] ?? display(s.value)" class="input text-xs ltr" :class="rowErrors[s.key] && 'input-error'" :aria-label="s.key" :aria-invalid="!!rowErrors[s.key]" :aria-describedby="rowErrors[s.key] ? `set-err-${s.key}` : undefined" @input="onEdit(s.key, ($event.target as HTMLInputElement).value)" @keydown.enter.prevent="edits[s.key] != null && saveKey(s)" /><p v-if="rowErrors[s.key]" :id="`set-err-${s.key}`" class="error-text" role="alert">{{ rowErrors[s.key] }}</p></td><td class="text-xs text-muted">{{ keyLabel(s) }}</td><td class="text-end"><AppButton size="sm" variant="outline" :disabled="edits[s.key] == null" :loading="savingKey === s.key" @click="saveKey(s)">{{ $t('admin.settings.saveKey') }}</AppButton></td></tr></tbody></table>
        </div>
      </SectionCard>
      <SectionCard v-if="features.length" :title="$t('admin.settings.features')">
        <ul class="flex flex-wrap gap-2"><li v-for="[k, v] in features" :key="k"><AppBadge kind="generic" :value="v ? 'active' : 'suspended'" :label="`${k}: ${v ? $t('common.yes') : $t('common.no')}`" /></li></ul>
      </SectionCard>
      <p v-if="payload && !settings.length" class="text-sm text-muted">{{ $t('common.noData') }}</p>
    </div>
    <div v-show="tab === 'taxonomies'" class="mt-5 grid lg:grid-cols-[260px_1fr] gap-5 items-start">
      <div class="card p-2 space-y-0.5"><button v-for="g in TAXONOMY_GROUPS" :key="g" type="button" class="w-full text-start rounded-full px-3 py-2 text-sm transition kbd-focus" :class="group === g ? 'bg-accent/15 text-ink font-medium' : 'text-text hover:bg-ink/5 hover:text-ink'" @click="group = g">{{ g }} <span class="num text-xs text-muted">({{ (tax.groups[g] ?? []).length }})</span></button></div>
      <div class="space-y-4">
        <SectionCard :title="$t('admin.settings.addTerm')">
          <form class="grid sm:grid-cols-4 gap-2 items-start" novalidate @submit.prevent="addTerm">
            <FormField v-model="termForm.values.term_key" name="term_key" :label="$t('admin.settings.termKey')" :error="termForm.errors.value.term_key" required ltr autocomplete="off" />
            <FormField v-model="termForm.values.label_ar" name="label_ar" :label="$t('admin.settings.labelAr')" :error="termForm.errors.value.label_ar" required />
            <FormField v-model="termForm.values.label_en" name="label_en" :label="$t('admin.settings.labelEn')" :error="termForm.errors.value.label_en" ltr />
            <AppButton type="submit" class="sm:mt-[1.625rem]" :loading="termForm.submitting.value">{{ $t('common.add') }}</AppButton>
            <FormAlert :message="termForm.formError.value" class="sm:col-span-4" />
          </form>
        </SectionCard>
        <div class="card overflow-x-auto"><table class="table-base"><thead><tr><th>{{ $t('admin.settings.sort') }}</th><th>{{ $t('admin.settings.termKey') }}</th><th>{{ $t('admin.settings.labelAr') }}</th><th>{{ $t('admin.settings.labelEn') }}</th><th>{{ $t('admin.settings.active') }}</th></tr></thead>
          <tbody><tr v-for="x in tax.groups[group] ?? []" :key="x.id" :class="!x.is_active && 'opacity-50'"><td class="num">{{ x.sort }}</td><td class="num text-xs ltr text-start">{{ x.key }}</td><td><input :value="x.label_ar" class="input text-sm" :aria-label="$t('admin.settings.labelAr')" @change="renameTerm(x, $event)" /></td><td class="text-muted ltr text-start">{{ x.label_en }}</td><td><input type="checkbox" :checked="x.is_active" class="h-4 w-4 rounded accent-accent" :aria-label="`${$t('admin.settings.active')} — ${termName(x)}`" @change="toggleTerm(x, $event)" /></td></tr></tbody></table></div>
      </div>
    </div>
    <!-- NTF-01 notification texts and the launch checklist load only when their tab opens -->
    <LazyAdminNotificationTemplates v-if="tab === 'templates'" class="mt-5" />
    <!-- FRM-01 — additional questions builder (versioned form schemas) -->
    <LazyAdminFormBuilder v-if="tab === 'forms'" class="mt-5" />
    <LazyAdminSystemStatus v-if="tab === 'system'" class="mt-5" />
  </div>
</template>

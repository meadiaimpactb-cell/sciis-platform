<script setup lang="ts">
/**
 * NTF-01 — notification texts (e-mail, SMS, in-app) editable without code.
 * GET /admin/notification-templates → {items: [{key, group, placeholders, ar: {default, value}, en: {default, value}}]}
 * PUT {key, locale, value|null} — an empty value restores the default; only the notification's own `:placeholders` are accepted.
 */
import { confirmDialog, toastApiError, toastSuccess } from '~/utils/notify'
import { parseApiError } from '~/utils/apiError'

type Locale = 'ar' | 'en'
type Text = { default: string | null; value: string | null }
type Item = { key: string; group: string; placeholders: string[]; ar: Text; en: Text }
const LOCALES: Locale[] = ['ar', 'en']

const { t } = useI18n()
const api = useApi()
const { data, loading, refresh } = useAsyncResource(() => api.get<{ data: { items: Item[] } }>('/admin/notification-templates').then((r) => r.data.items))

const search = ref('')
const drafts = reactive<Record<string, string>>({})
const errors = reactive<Record<string, string>>({})
const saving = ref<string | null>(null)
const id = (it: Item, l: Locale) => `${l}:${it.key}`
const current = (it: Item, l: Locale) => it[l].value ?? it[l].default ?? ''
const dirty = (it: Item, l: Locale) => drafts[id(it, l)] != null && drafts[id(it, l)] !== current(it, l)

const groups = computed(() => {
  const q = search.value.trim().toLowerCase()
  const items = (data.value ?? []).filter((it) => !q || it.key.toLowerCase().includes(q) || LOCALES.some((l) => current(it, l).toLowerCase().includes(q)))
  const byGroup = new Map<string, Item[]>()
  for (const it of items) byGroup.set(it.group, [...(byGroup.get(it.group) ?? []), it])
  const all = data.value ?? []
  // A group is named by its own title/subject text, so new notifications need no extra translation.
  const title = (g: string) => all.find((x) => x.key === `${g}.title`)?.ar.default ?? all.find((x) => x.key === `${g}.subject`)?.ar.default ?? g
  return Array.from(byGroup, ([group, rows]) => ({ group, title: title(group), rows, edited: rows.filter((r) => r.ar.value || r.en.value).length }))
})

const save = async (it: Item, l: Locale, value: string | null) => {
  const key = id(it, l)
  saving.value = key
  delete errors[key]
  try {
    await api.put('/admin/notification-templates', { key: it.key, locale: l, value })
    delete drafts[key]
    toastSuccess(t(value == null ? 'admin.templates.restored' : 'notify.savedItem', { item: t('admin.templates.item'), key: it.key }))
    await refresh()
  } catch (e) {
    const p = parseApiError(e)
    if (p.errors.value) errors[key] = p.errors.value
    else toastApiError(e)
  } finally {
    saving.value = null
  }
}
const restore = async (it: Item, l: Locale) => {
  if (!(await confirmDialog(t('admin.templates.restoreTitle'), t('admin.templates.restoreText', { key: it.key }), t('admin.templates.restore')))) return
  await save(it, l, null)
}
</script>

<template>
  <div class="space-y-4">
    <div class="card card-pad flex flex-col sm:flex-row sm:items-center gap-3">
      <p class="text-sm text-muted flex-1">{{ $t('admin.templates.help') }}</p>
      <input v-model="search" type="search" class="input sm:w-72" :placeholder="$t('admin.templates.search')" :aria-label="$t('admin.templates.search')">
    </div>
    <p v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</p>
    <details v-for="g in groups" :key="g.group" class="card group" :open="!!search.trim()">
      <summary class="flex items-center justify-between gap-3 px-5 py-4 cursor-pointer list-none">
        <span class="card-title">{{ g.title }}</span>
        <span class="flex items-center gap-2 text-xs text-muted">
          <span v-if="g.edited" class="rounded-full bg-teal-soft text-teal-text px-2 py-0.5">{{ $t('admin.templates.editedCount', { n: g.edited }) }}</span>
          <span class="num">{{ g.rows.length }}</span>
          <svg class="h-4 w-4 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.3 7.3a1 1 0 011.4 0L10 10.6l3.3-3.3a1 1 0 111.4 1.4l-4 4a1 1 0 01-1.4 0l-4-4a1 1 0 010-1.4z" clip-rule="evenodd" /></svg>
        </span>
      </summary>
      <ul class="divide-y divide-border border-t border-border">
        <li v-for="it in g.rows" :key="it.key" class="px-5 py-4">
          <div class="flex flex-wrap items-center gap-2 mb-3">
            <code class="text-xs text-muted ltr" dir="ltr">{{ it.key }}</code>
            <span v-for="p in it.placeholders" :key="p" class="rounded bg-bg border border-border px-1.5 text-[11px] ltr" dir="ltr">:{{ p }}</span>
          </div>
          <div class="grid lg:grid-cols-2 gap-4">
            <div v-for="l in LOCALES" :key="l">
              <label :for="`tpl-${l}-${it.key}`" class="label flex items-center gap-2">
                {{ $t(`admin.templates.locale.${l}`) }}
                <span v-if="it[l].value" class="rounded-full bg-teal-soft text-teal-text text-[11px] px-2">{{ $t('admin.templates.edited') }}</span>
              </label>
              <textarea
                :id="`tpl-${l}-${it.key}`"
                :value="drafts[id(it, l)] ?? current(it, l)"
                rows="2"
                class="input min-h-[2.75rem]"
                :class="[l === 'en' && 'ltr text-start', errors[id(it, l)] && 'input-error']"
                :dir="l === 'en' ? 'ltr' : 'rtl'"
                :aria-invalid="!!errors[id(it, l)]"
                @input="drafts[id(it, l)] = ($event.target as HTMLTextAreaElement).value"
              />
              <p v-if="errors[id(it, l)]" class="error-text" role="alert">{{ errors[id(it, l)] }}</p>
              <div class="mt-2 flex gap-2">
                <AppButton size="sm" :disabled="!dirty(it, l)" :loading="saving === id(it, l)" @click="save(it, l, drafts[id(it, l)] ?? '')">{{ $t('admin.settings.saveKey') }}</AppButton>
                <AppButton v-if="it[l].value" size="sm" variant="ghost" :disabled="!!saving" @click="restore(it, l)">{{ $t('admin.templates.restore') }}</AppButton>
              </div>
            </div>
          </div>
        </li>
      </ul>
    </details>
  </div>
</template>

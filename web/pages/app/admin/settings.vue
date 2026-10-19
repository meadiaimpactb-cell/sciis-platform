<script setup lang="ts">
import type { SettingItem, TaxonomyTerm } from '~/types/api'
import { TAXONOMY_GROUPS, type TaxonomyGroup } from '~/types/enums'
definePageMeta({ layout: 'portal', middleware: ['role'], roles: [] })
const { t } = useI18n()
useHead({ title: t('admin.settings.metaTitle') })
const api = useApi()
const toast = useToast()
const tax = useTaxonomyStore()
await tax.load().catch(() => {})
const tab = ref('keys')
const tabs = [{ key: 'keys', label: t('admin.settings.tabs.keys') }, { key: 'taxonomies', label: t('admin.settings.tabs.taxonomies') }]
const { data: settings, refresh } = useAsyncResource(() => api.get<{ data: SettingItem[] }>('/admin/settings').then((r) => r.data))
const edits = reactive<Record<string, string>>({})
const groups = computed(() => Array.from(new Set((settings.value ?? []).map((s) => s.group))))
const display = (v: unknown) => (typeof v === 'string' ? v : JSON.stringify(v))
const saveKey = async (s: SettingItem) => {
  const raw = edits[s.key]
  let value: unknown = raw
  try { value = JSON.parse(raw) } catch { value = raw }
  await api.put('/admin/settings', { values: { [s.key]: value } }); toast.success(t('common.saved')); delete edits[s.key]; await refresh()
}
const group = ref<TaxonomyGroup>('sectors')
const term = reactive({ key: '', label_ar: '', label_en: '' })
const addTerm = async () => { try { await api.post(`/admin/taxonomies/${group.value}`, term); term.key = ''; term.label_ar = ''; term.label_en = ''; toast.success(t('common.success')); await tax.load(true) } catch (e) { toast.error((e as Error).message) } }
const toggleTerm = async (x: TaxonomyTerm) => { await api.patch(`/admin/taxonomies/${group.value}/${x.id}`, { is_active: !x.is_active }); await tax.load(true) }
const renameTerm = async (x: TaxonomyTerm, label_ar: string) => { if (label_ar && label_ar !== x.label_ar) { await api.patch(`/admin/taxonomies/${group.value}/${x.id}`, { label_ar }); await tax.load(true); toast.success(t('common.saved')) } }
</script>
<template>
  <div>
    <PageHeader :title="$t('admin.settings.title')" :sub="$t('admin.settings.sub')" />
    <Tabs v-model="tab" :tabs="tabs" variant="pills" class="w-fit" />
    <div v-show="tab === 'keys'" class="mt-5 space-y-5">
      <SectionCard v-for="g in groups" :key="g" :title="$t(`admin.settings.groups.${g}`)" flush>
        <table class="table-base"><thead><tr><th>{{ $t('admin.settings.key') }}</th><th>{{ $t('admin.settings.value') }}</th><th>{{ $t('admin.settings.description') }}</th><th></th></tr></thead>
          <tbody><tr v-for="s in (settings ?? []).filter((x) => x.group === g)" :key="s.key"><td class="num text-xs">{{ s.key }} <span v-if="s.pending_legal" class="text-[10px] rounded-full bg-warning/10 text-warning px-1.5">{{ $t('app.pendingDecision') }}</span></td><td><input :value="edits[s.key] ?? display(s.value)" class="input text-xs ltr" :aria-label="s.key" @input="edits[s.key] = ($event.target as HTMLInputElement).value" /></td><td class="text-xs text-muted">{{ s.description }}</td><td class="text-end"><AppButton size="sm" variant="outline" :disabled="edits[s.key] == null" @click="saveKey(s)">{{ $t('admin.settings.saveKey') }}</AppButton></td></tr></tbody></table>
      </SectionCard>
    </div>
    <div v-show="tab === 'taxonomies'" class="mt-5 grid lg:grid-cols-[260px_1fr] gap-5 items-start">
      <div class="card p-2"><button v-for="g in TAXONOMY_GROUPS" :key="g" type="button" class="w-full text-start rounded-lg px-3 py-2 text-sm" :class="group === g ? 'bg-blue-soft text-blue font-medium' : 'hover:bg-bg'" @click="group = g">{{ g }} <span class="num text-xs text-muted">({{ (tax.groups[g] ?? []).length }})</span></button></div>
      <div class="space-y-4">
        <SectionCard :title="$t('admin.settings.addTerm')"><div class="grid sm:grid-cols-4 gap-2 items-end"><FormField v-model="term.key" name="key" :label="$t('admin.settings.termKey')" ltr /><FormField v-model="term.label_ar" name="label_ar" :label="$t('admin.settings.labelAr')" /><FormField v-model="term.label_en" name="label_en" :label="$t('admin.settings.labelEn')" ltr /><AppButton :disabled="!term.key || !term.label_ar" @click="addTerm">{{ $t('common.add') }}</AppButton></div></SectionCard>
        <div class="card overflow-hidden"><table class="table-base"><thead><tr><th>{{ $t('admin.settings.sort') }}</th><th>{{ $t('admin.settings.termKey') }}</th><th>{{ $t('admin.settings.labelAr') }}</th><th>{{ $t('admin.settings.labelEn') }}</th><th>{{ $t('admin.settings.active') }}</th></tr></thead>
          <tbody><tr v-for="x in tax.groups[group] ?? []" :key="x.id" :class="!x.is_active && 'opacity-50'"><td class="num">{{ x.sort }}</td><td class="num text-xs ltr text-start">{{ x.key }}</td><td><input :value="x.label_ar" class="input text-sm" :aria-label="$t('admin.settings.labelAr')" @change="renameTerm(x, ($event.target as HTMLInputElement).value)" /></td><td class="text-muted ltr text-start">{{ x.label_en }}</td><td><input type="checkbox" :checked="x.is_active" class="h-4 w-4 rounded text-blue" :aria-label="$t('admin.settings.active')" @change="toggleTerm(x)" /></td></tr></tbody></table></div>
      </div>
    </div>
  </div>
</template>

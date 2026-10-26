<script setup lang="ts">
/** RPT-04 custom report builder: dataset → columns (only those this user may see; confidential ones locked) →
 *  filters → optional grouping → preview (table + chart) → export xlsx/PDF (export roles) → save as a named report.
 *  POST /admin/report-builder/preview · GET /admin/report-builder/export · POST|PATCH /admin/saved-reports. */
import { z } from 'zod'
import type { BuilderCatalog, DatasetDef, DatasetResult, ReportDefinition, SavedReport } from '~/types/reporting'
import { requiredString } from '~/utils/validation'
import { toastApiError, toastError, toastSuccess } from '~/utils/notify'

const props = defineProps<{ catalog: BuilderCatalog; initial?: SavedReport | null }>()
const emit = defineEmits<{ (e: 'saved', r: SavedReport): void }>()
const { t } = useI18n()
const api = useApi()
const tax = useTaxonomyStore()
const { term } = useEnumLabel()
const { download } = useReportDownload()

const dataset = ref(props.initial?.dataset ?? props.catalog.datasets[0]?.key ?? 'opportunities')
const def = computed<DatasetDef | undefined>(() => props.catalog.datasets.find((d) => d.key === dataset.value))
const columns = ref<string[]>([])
const f = reactive({ status: [] as string[], sector: '', region: '', from: '', to: '', date_field: '', investor_type: '', overdue: false })
const groupBy = ref('')
const result = ref<DatasetResult | null>(null)
const running = ref(false)
const editing = ref<SavedReport | null>(null)

const reset = (d?: DatasetDef, saved?: SavedReport | null) => {
  const sv = saved?.definition
  columns.value = sv?.columns?.length ? [...sv.columns] : [...(d?.default_columns ?? [])].filter((k) => d?.columns.find((c) => c.key === k)?.allowed)
  const sf = (sv?.filters ?? {}) as Record<string, unknown>
  f.status = Array.isArray(sf.status) ? (sf.status as string[]) : sf.status ? [String(sf.status)] : []
  f.sector = String(sf.sector ?? ''); f.region = String(sf.region ?? ''); f.from = String(sf.from ?? ''); f.to = String(sf.to ?? '')
  f.date_field = String(sf.date_field ?? ''); f.investor_type = String(sf.investor_type ?? ''); f.overdue = sf.overdue === true || sf.overdue === '1'
  groupBy.value = sv?.group_by ?? ''
  result.value = null
}
watch(dataset, () => { if (!editing.value || editing.value.dataset !== dataset.value) editing.value = null; reset(def.value, editing.value) })
/** Opens a saved report in the builder (from the saved-reports tab). */
const load = (r: SavedReport) => { editing.value = r; dataset.value = r.dataset; reset(props.catalog.datasets.find((d) => d.key === r.dataset), r); void preview() }
defineExpose({ load })
onMounted(() => { if (props.initial) load(props.initial); else reset(def.value) })

const has = (k: string) => !!def.value && k in def.value.filters
const toggleColumn = (k: string) => { columns.value = columns.value.includes(k) ? columns.value.filter((x) => x !== k) : [...columns.value, k] }
const toggleStatus = (v: string) => { f.status = f.status.includes(v) ? f.status.filter((x) => x !== v) : [...f.status, v] }
const definition = (): ReportDefinition => ({
  columns: columns.value,
  filters: Object.fromEntries(Object.entries({ ...f, overdue: f.overdue || undefined, status: f.status.length ? f.status : undefined }).filter(([k, v]) => has(k) || ['from', 'to', 'date_field'].includes(k) ? v !== '' && v != null : false)),
  group_by: groupBy.value || null,
})
const groupable = computed(() => def.value?.columns.filter((c) => c.groupable && c.allowed) ?? [])
const sensitivityHint = (s: string) => t(`admin.reportBuilder.sensitivity.${s}`)

const preview = async () => {
  if (!columns.value.length && !groupBy.value) { toastError(t('admin.reportBuilder.pickColumns')); return }
  running.value = true
  try { result.value = (await api.post<{ data: DatasetResult }>('/admin/report-builder/preview', { dataset: dataset.value, ...definition() })).data }
  catch (e) { toastApiError(e) }
  finally { running.value = false }
}
const exportAs = (format: 'xlsx' | 'pdf') => {
  const d = definition()
  void download('/admin/report-builder/export', { format, dataset: dataset.value, columns: d.columns, filters: d.filters as Record<string, string | string[]>, group_by: d.group_by })
}
// Chart of a grouped result: a trend line for months, bars otherwise.
const chartSeries = computed(() => {
  if (!result.value?.grouped) return []
  const s = result.value.series.map((x) => ({ ...x }))
  return groupBy.value === 'month' ? s.sort((a, b) => a.label.localeCompare(b.label)) : s.slice(0, 15)
})

// ---------------------------------------------------------------- save (name) — new or update of an editable saved report
const saveOpen = ref(false)
const saveForm = useZodForm(z.object({ report_name: requiredString('admin.reportBuilder.name', 3, 150) }), { report_name: '' }, { aliases: { name: 'report_name' } })
const openSave = () => { saveForm.reset({ report_name: editing.value?.can_edit ? editing.value.name : '' }); saveOpen.value = true }
const save = () => saveForm.submit(async (v) => {
  const body = { name: v.report_name.trim(), dataset: dataset.value, definition: definition() }
  const r = editing.value?.can_edit
    ? (await api.patch<{ data: SavedReport }>(`/admin/saved-reports/${editing.value.uuid}`, body)).data
    : (await api.post<{ data: SavedReport }>('/admin/saved-reports', body)).data
  editing.value = r
  saveOpen.value = false
  toastSuccess(t('notify.savedItem', { item: `${t('admin.reportBuilder.savedReport')} «${r.name}»` }))
  emit('saved', r)
})
</script>

<template>
  <div class="grid xl:grid-cols-[360px_1fr] gap-5 items-start">
    <SectionCard :title="$t('admin.reportBuilder.title')" :sub="editing ? $t('admin.reportBuilder.editing', { name: editing.name }) : $t('admin.reportBuilder.sub')">
      <div class="space-y-4">
        <FormField v-model="dataset" name="dataset" type="select" :label="$t('admin.reportBuilder.dataset')" :options="catalog.datasets.map((d) => ({ value: d.key, label: d.label }))" required />

        <fieldset v-if="def">
          <legend class="label">{{ $t('admin.reportBuilder.columns') }}</legend>
          <div class="flex flex-wrap gap-1.5">
            <button v-for="c in def.columns" :key="c.key" type="button" class="chip kbd-focus" :class="[columns.includes(c.key) && 'chip-active', !c.allowed && 'opacity-50 cursor-not-allowed']" :disabled="!c.allowed" :aria-pressed="columns.includes(c.key)" :title="c.allowed ? sensitivityHint(c.sensitivity) : $t('admin.reportBuilder.lockedColumn')" @click="toggleColumn(c.key)">
              <svg v-if="!c.allowed" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2" /><path d="M8 11V7a4 4 0 118 0v4" /></svg>
              {{ c.label }}
            </button>
          </div>
          <p v-if="!def.domain_access" class="text-xs text-muted mt-1.5">{{ $t('admin.reportBuilder.lockedHint') }}</p>
        </fieldset>

        <fieldset v-if="def?.filters.status?.length">
          <legend class="label">{{ $t('admin.reportBuilder.status') }}</legend>
          <div class="flex flex-wrap gap-1.5"><button v-for="o in def.filters.status" :key="o.value" type="button" class="chip kbd-focus" :class="f.status.includes(o.value) && 'chip-active'" :aria-pressed="f.status.includes(o.value)" @click="toggleStatus(o.value)">{{ o.label }}</button></div>
        </fieldset>

        <div class="grid grid-cols-2 gap-3">
          <FormField v-model="f.from" name="rb_from" type="date" :label="$t('admin.reports.filters.from')" ltr />
          <FormField v-model="f.to" name="rb_to" type="date" :label="$t('admin.reports.filters.to')" ltr />
          <FormField v-if="def && def.date_fields.length > 1" v-model="f.date_field" name="rb_date_field" type="select" class="col-span-2" :label="$t('admin.reportBuilder.dateField')" :options="def.date_fields.map((d) => ({ value: d.key, label: d.label }))" />
          <FormField v-if="has('sector')" v-model="f.sector" name="rb_sector" type="select" :label="$t('admin.reports.filters.sector')" :options="tax.list('sectors').map((s) => ({ value: s.key, label: term(s) }))" />
          <FormField v-if="has('region')" v-model="f.region" name="rb_region" type="select" :label="$t('admin.reports.filters.region')" :options="tax.list('regions').map((s) => ({ value: s.key, label: term(s) }))" />
          <FormField v-if="has('investor_type')" v-model="f.investor_type" name="rb_investor_type" type="select" class="col-span-2" :label="$t('admin.reportBuilder.accountType')" :options="def!.filters.investor_type" />
        </div>
        <label v-if="has('overdue')" class="inline-flex items-center gap-2 text-sm"><input v-model="f.overdue" type="checkbox" class="h-4 w-4 rounded border-border text-blue" />{{ $t('admin.opportunities.filters.overdue') }}</label>

        <FormField v-model="groupBy" name="rb_group_by" type="select" :label="$t('admin.reportBuilder.groupBy')" :help="$t('admin.reportBuilder.groupHint')" :options="groupable.map((c) => ({ value: c.key, label: c.label }))" />

        <div class="flex flex-wrap gap-2 pt-1">
          <AppButton :loading="running" @click="preview">{{ $t('admin.reportBuilder.preview') }}</AppButton>
          <AppButton variant="outline" @click="openSave">{{ editing?.can_edit ? $t('admin.reportBuilder.update') : $t('admin.reportBuilder.save') }}</AppButton>
        </div>
      </div>
    </SectionCard>

    <SectionCard :title="result?.title ?? $t('admin.reportBuilder.result')" :sub="result ? $t('admin.reportBuilder.totalRows', { n: result.total }) : $t('admin.reportBuilder.resultHint')">
      <template #actions>
        <template v-if="catalog.can_export && result">
          <AppButton size="sm" variant="ghost" @click="exportAs('xlsx')">{{ $t('admin.reportBuilder.exportXlsx') }}</AppButton>
          <AppButton size="sm" variant="ghost" @click="exportAs('pdf')">{{ $t('admin.reportBuilder.exportPdf') }}</AppButton>
        </template>
      </template>
      <div v-if="result" class="space-y-4">
        <InlineAlert v-if="result.total > catalog.max_export_rows" tone="warning">{{ $t('admin.reportBuilder.exportCap', { n: catalog.max_export_rows }) }}</InlineAlert>
        <div v-if="result.grouped && chartSeries.length" class="rounded-xl border border-border p-4">
          <LineChart v-if="groupBy === 'month'" :series="chartSeries" />
          <BarChart v-else :series="chartSeries" />
        </div>
        <ReportRecords :result="result" :loading="running" dense />
      </div>
      <EmptyState v-else :title="$t('admin.reportBuilder.emptyTitle')" :text="$t('admin.reportBuilder.resultHint')" />
    </SectionCard>

    <Modal :open="saveOpen" :title="editing?.can_edit ? $t('admin.reportBuilder.update') : $t('admin.reportBuilder.save')" size="sm" @close="saveOpen = false">
      <form id="rb-save" novalidate @submit.prevent="save">
        <FormField v-model="saveForm.values.report_name" name="report_name" :label="$t('admin.reportBuilder.name')" :error="saveForm.errors.value.report_name" :maxlength="150" required />
        <FormAlert :message="saveForm.formError.value" class="mt-3" />
      </form>
      <template #footer>
        <AppButton variant="ghost" @click="saveOpen = false">{{ $t('common.cancel') }}</AppButton>
        <AppButton type="submit" form="rb-save" :loading="saveForm.submitting.value">{{ $t('common.save') }}</AppButton>
      </template>
    </Modal>
  </div>
</template>

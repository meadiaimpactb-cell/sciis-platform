<script setup lang="ts">
/** RPT-03/04 — a dataset result as a table: labelled columns, display-ready values, and a link to the record when
 *  the API returned one (`_link` — only for users allowed to open it). Notes the truncation / preview limit. */
import type { DatasetResult, RecordLink, ResultRow } from '~/types/reporting'
const props = defineProps<{ result: DatasetResult; loading?: boolean; dense?: boolean }>()
const { number } = useFormat()
const linkTo = (l: RecordLink | null | undefined) => {
  if (!l) return null
  return { opportunity: `/app/admin/opportunities/${l.id}`, membership: `/app/admin/memberships/${l.id}`, user: `/app/admin/users/${l.id}` }[l.type] ?? null
}
const columns = computed(() => [...props.result.columns.map((c) => ({ key: c.key, label: c.label })), ...(props.result.rows.some((r) => r._link) ? [{ key: '_open', label: '' }] : [])])
const rows = computed(() => props.result.rows.map((r, i) => ({ ...r, _i: i })) as (ResultRow & { _i: number })[])
const show = (v: unknown) => (typeof v === 'number' ? number(v, { maximumFractionDigits: 2 }) : v == null || v === '' ? '—' : String(v))
</script>

<template>
  <div>
    <DataTable :columns="columns" :rows="rows" row-key="_i" :loading="loading" :dense="dense">
      <template v-for="c in result.columns" :key="c.key" #[`cell-${c.key}`]="{ value }"><span :class="typeof value === 'number' && 'num'">{{ show(value) }}</span></template>
      <template #cell-_open="{ row }"><AppButton v-if="linkTo(row._link)" size="sm" variant="ghost" :to="linkTo(row._link)!">{{ $t('common.view') }}</AppButton></template>
    </DataTable>
    <p class="mt-2 text-xs text-muted">
      {{ $t('admin.reportBuilder.totalRows', { n: number(result.total) }) }}
      <span v-if="result.rows.length < result.total"> · {{ $t('admin.reportBuilder.showingFirst', { n: number(result.rows.length) }) }}</span>
    </p>
  </div>
</template>

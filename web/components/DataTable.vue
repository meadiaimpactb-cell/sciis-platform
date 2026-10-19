<script setup lang="ts" generic="T extends Record<string, any>">
/** Generic data table with optional sortable columns and row slots. Responsive: scrolls horizontally on small screens. */
export interface Column {
  key: string
  label: string
  sortable?: boolean
  class?: string
  width?: string
}
const props = withDefaults(defineProps<{ columns: Column[]; rows: T[]; rowKey?: string; loading?: boolean; emptyText?: string; dense?: boolean }>(), { rowKey: 'id', loading: false, dense: false })
const sortKey = ref<string | null>(null)
const sortDir = ref<'asc' | 'desc'>('asc')
const sorted = computed(() => {
  if (!sortKey.value) return props.rows
  const k = sortKey.value
  return [...props.rows].sort((a, b) => {
    const av = a[k], bv = b[k]
    if (av == null) return 1
    if (bv == null) return -1
    const r = typeof av === 'number' && typeof bv === 'number' ? av - bv : String(av).localeCompare(String(bv))
    return sortDir.value === 'asc' ? r : -r
  })
})
const toggleSort = (c: Column) => {
  if (!c.sortable) return
  if (sortKey.value === c.key) sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  else { sortKey.value = c.key; sortDir.value = 'asc' }
}
</script>

<template>
  <div class="card overflow-hidden">
    <div class="overflow-x-auto">
      <table class="table-base" :class="dense && 'text-xs'">
        <thead>
          <tr>
            <th v-for="c in columns" :key="c.key" :class="c.class" :style="c.width ? { width: c.width } : undefined" scope="col" :aria-sort="sortKey === c.key ? (sortDir === 'asc' ? 'ascending' : 'descending') : undefined">
              <button v-if="c.sortable" type="button" class="inline-flex items-center gap-1 kbd-focus rounded" @click="toggleSort(c)">
                {{ c.label }}
                <svg class="h-3 w-3" :class="sortKey === c.key ? 'text-blue' : 'text-muted/60'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 3l4 5H6l4-5zm0 14l-4-5h8l-4 5z" /></svg>
              </button>
              <template v-else>{{ c.label }}</template>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading">
            <td :colspan="columns.length" class="text-center text-muted py-8">{{ $t('common.loading') }}</td>
          </tr>
          <tr v-else-if="!rows.length">
            <td :colspan="columns.length" class="text-center text-muted py-8">{{ emptyText ?? $t('components.table.empty') }}</td>
          </tr>
          <tr v-for="row in sorted" v-else :key="String(row[rowKey])" class="hover:bg-bg/60 transition">
            <td v-for="c in columns" :key="c.key" :class="c.class">
              <slot :name="`cell-${c.key}`" :row="row" :value="row[c.key]">{{ row[c.key] ?? '—' }}</slot>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="$slots.footer" class="border-t border-border px-4 py-3 bg-bg/40"><slot name="footer" /></div>
  </div>
</template>

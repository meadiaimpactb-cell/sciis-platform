<script setup lang="ts">
/**
 * AGR-02 — line-level comparison of two versions (computed by the API: GET /admin/agreements/{uuid}/compare).
 * Long unchanged runs are folded to 2 lines of context on each side; a click expands them.
 */
import type { AgreementDiffLine, AgreementDiffPart } from '~/types/api'

const props = withDefaults(defineProps<{ part: AgreementDiffPart; lang?: 'ar' | 'en'; caption?: string }>(), { lang: 'ar', caption: undefined })
const CONTEXT = 2
type Row = { kind: 'line'; line: AgreementDiffLine } | { kind: 'fold'; id: number; count: number }
const expanded = ref<Set<number>>(new Set())

const rows = computed<Row[]>(() => {
  const lines = props.part.lines
  // distance (in lines) to the nearest change, computed in two passes
  const dist = lines.map(() => Number.POSITIVE_INFINITY)
  let last = Number.NEGATIVE_INFINITY
  lines.forEach((l, i) => { if (l.type !== 'same') last = i; dist[i] = i - last })
  last = Number.POSITIVE_INFINITY
  for (let i = lines.length - 1; i >= 0; i--) { if (lines[i].type !== 'same') last = i; dist[i] = Math.min(dist[i], last - i) }
  const near = (i: number) => dist[i] <= CONTEXT
  const out: Row[] = []
  let run: AgreementDiffLine[] = []
  let foldId = 0
  const flush = () => {
    if (!run.length) return
    const id = foldId++
    if (run.length > 3 && !expanded.value.has(id)) out.push({ kind: 'fold', id, count: run.length })
    else for (const l of run) out.push({ kind: 'line', line: l })
    run = []
  }
  lines.forEach((l, i) => {
    if (l.type === 'same' && !near(i)) run.push(l)
    else { flush(); out.push({ kind: 'line', line: l }) }
  })
  flush()
  return out
})
const expand = (id: number) => { expanded.value = new Set([...expanded.value, id]) }
</script>

<template>
  <div class="rounded-lg border border-border overflow-hidden">
    <p v-if="!part.added && !part.removed" class="p-4 text-sm text-muted">{{ $t('agreements.compare.identical') }}</p>
    <div v-else class="overflow-x-auto">
      <table class="w-full text-sm" :dir="lang === 'en' ? 'ltr' : 'rtl'" :lang="lang" :aria-label="caption">
        <tbody>
          <template v-for="(r, i) in rows" :key="i">
            <tr v-if="r.kind === 'fold'">
              <td colspan="4" class="bg-bg/70 text-center py-1"><button type="button" class="text-xs text-blue hover:underline kbd-focus rounded" @click="expand(r.id)">{{ $t('agreements.compare.folded', { n: r.count }) }}</button></td>
            </tr>
            <tr v-else :class="r.line.type === 'added' ? 'bg-success/10' : r.line.type === 'removed' ? 'bg-danger/10' : ''">
              <td class="w-10 px-2 py-1 text-xs text-muted num text-end align-top select-none" dir="ltr">{{ r.line.old ?? '' }}</td>
              <td class="w-10 px-2 py-1 text-xs text-muted num text-end align-top select-none" dir="ltr">{{ r.line.new ?? '' }}</td>
              <td class="w-6 py-1 text-center align-top font-semibold select-none" :class="r.line.type === 'added' ? 'text-success' : r.line.type === 'removed' ? 'text-danger' : 'text-muted'" aria-hidden="true">{{ r.line.type === 'added' ? '+' : r.line.type === 'removed' ? '−' : '' }}</td>
              <td class="px-2 py-1 whitespace-pre-wrap break-words leading-7" :class="r.line.type === 'removed' && 'line-through decoration-danger/50'">
                <span v-if="r.line.type !== 'same'" class="sr-only">{{ $t(`agreements.compare.${r.line.type}`) }}: </span>{{ r.line.text || ' ' }}
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </div>
</template>

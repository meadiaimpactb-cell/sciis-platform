<script setup lang="ts">
/**
 * FRM-01 — read-only view of stored additional answers (`extra_answers` on a profile / opportunity / membership),
 * labelled with the schema version they were answered against. Renders nothing when there are no answers.
 */
import type { ExtraAnswers } from '~/types/platform'

const props = defineProps<{ data: ExtraAnswers | null | undefined }>()
const { locale, t } = useI18n()
const { date, number } = useFormat()
const en = computed(() => locale.value === 'en')

const rows = computed(() => (props.data?.items ?? []).map((it) => {
  const label = (en.value && it.label_en) || it.label_ar
  let value: string
  if (it.choices.length) value = it.choices.map((c) => (en.value && c.en) || c.ar).join('، ')
  else if (it.type === 'yes_no') value = it.value ? t('common.yes') : t('common.no')
  else if (it.type === 'number') value = number(Number(it.value))
  else if (it.type === 'date') value = date(String(it.value))
  else value = String(it.value ?? '—')
  return { id: it.id, label, value, long: it.type === 'long_text', num: it.type === 'number' || it.type === 'date' }
}))
const outdated = computed(() => !!props.data && props.data.current_version > 0 && props.data.version !== props.data.current_version)
</script>

<template>
  <div v-if="rows.length">
    <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2 text-sm">
      <div v-for="r in rows" :key="r.id" :class="r.long && 'sm:col-span-2'">
        <dt class="text-xs text-muted">{{ r.label }}</dt>
        <dd class="mt-1 text-ink break-words whitespace-pre-line" :class="r.num && 'num'">{{ r.value }}</dd>
      </div>
    </dl>
    <p class="mt-4 text-xs text-muted">
      <span class="num">{{ $t('platform.extra.answeredOn', { date: date(data?.answered_at), version: data?.version }) }}</span>
      <span v-if="outdated"> · {{ $t('platform.extra.outdated', { version: data?.current_version }) }}</span>
    </p>
  </div>
  <p v-else class="text-sm text-muted">{{ $t('platform.extra.none') }}</p>
</template>

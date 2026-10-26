<script setup lang="ts">
/**
 * MTG-05 — "Add to Google / Outlook" links for a meeting or event, built client-side (no OAuth app): Google's
 * `calendar/render?action=TEMPLATE` and Outlook's compose deeplink, plus an .ics file (`icsHref` from the API,
 * or generated in the browser when none is given).
 */
import type { CalendarItem } from '~/types/collab'
import { toastSuccess } from '~/utils/notify'

const props = withDefaults(defineProps<{ item: CalendarItem; icsHref?: string | null; size?: 'sm' | 'md'; block?: boolean }>(), { icsHref: null, size: 'sm', block: false })
const { t } = useI18n()

const start = computed(() => new Date(props.item.starts_at))
// No end → one hour (calendars need an end time).
const end = computed(() => (props.item.ends_at ? new Date(props.item.ends_at) : new Date(start.value.getTime() + 3600_000)))
const utc = (d: Date) => d.toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '')

const googleUrl = computed(() => {
  const q = new URLSearchParams({ action: 'TEMPLATE', text: props.item.title, dates: `${utc(start.value)}/${utc(end.value)}` })
  if (props.item.details) q.set('details', props.item.details)
  if (props.item.location) q.set('location', props.item.location)
  return `https://calendar.google.com/calendar/render?${q.toString()}`
})
const outlookUrl = computed(() => {
  const q = new URLSearchParams({ path: '/calendar/action/compose', rru: 'addevent', subject: props.item.title, startdt: start.value.toISOString(), enddt: end.value.toISOString() })
  if (props.item.details) q.set('body', props.item.details)
  if (props.item.location) q.set('location', props.item.location)
  return `https://outlook.office.com/calendar/0/deeplink/compose?${q.toString()}`
})

const esc = (s: string) => s.replace(/\\/g, '\\\\').replace(/;/g, '\\;').replace(/,/g, '\\,').replace(/\n/g, '\\n')
const downloadIcs = () => {
  const lines = [
    'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//SCIIS//Platform//AR', 'BEGIN:VEVENT',
    `UID:${utc(start.value)}-${Math.random().toString(36).slice(2)}@sciis`, `DTSTAMP:${utc(new Date())}`,
    `DTSTART:${utc(start.value)}`, `DTEND:${utc(end.value)}`, `SUMMARY:${esc(props.item.title)}`,
    ...(props.item.location ? [`LOCATION:${esc(props.item.location)}`] : []),
    ...(props.item.details ? [`DESCRIPTION:${esc(props.item.details)}`] : []),
    'END:VEVENT', 'END:VCALENDAR',
  ]
  const a = document.createElement('a')
  a.href = `data:text/calendar;charset=utf-8,${encodeURIComponent(lines.join('\r\n'))}`
  a.download = 'event.ics'
  a.click()
  toastSuccess(t('notify.downloaded'))
}
</script>

<template>
  <div class="flex flex-wrap gap-2" :class="block && 'flex-col'" role="group" :aria-label="$t('collab.calendar.addTo')">
    <AppButton :href="googleUrl" :size="size" variant="secondary" :block="block">{{ $t('collab.calendar.google') }}</AppButton>
    <AppButton :href="outlookUrl" :size="size" variant="secondary" :block="block">{{ $t('collab.calendar.outlook') }}</AppButton>
    <a v-if="icsHref" :href="icsHref" download class="inline-flex items-center justify-center gap-2 font-medium rounded-md transition text-sm bg-surface text-ink border border-border hover:border-navy/40 hover:bg-navy-50 kbd-focus" :class="[size === 'sm' ? 'h-9 px-3.5' : 'h-10 px-4', block && 'w-full']">{{ $t('collab.calendar.ics') }}</a>
    <AppButton v-else :size="size" variant="secondary" :block="block" @click="downloadIcs">{{ $t('collab.calendar.ics') }}</AppButton>
  </div>
</template>

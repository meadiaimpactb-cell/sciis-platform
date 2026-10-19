<script setup lang="ts">
import type { Meeting } from '~/types/api'
const props = defineProps<{ meetings: Meeting[] }>()
const { dateTime } = useFormat()
const upcoming = computed(() => props.meetings.filter((m) => m.status === 'scheduled' && new Date(m.starts_at) >= new Date()).sort((a, b) => a.starts_at.localeCompare(b.starts_at)))
const past = computed(() => props.meetings.filter((m) => !upcoming.value.includes(m)).sort((a, b) => b.starts_at.localeCompare(a.starts_at)))
const ics = (m: Meeting) => {
  const fmt = (d: string) => new Date(d).toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '')
  const body = `BEGIN:VCALENDAR\nVERSION:2.0\nBEGIN:VEVENT\nDTSTART:${fmt(m.starts_at)}\nDTEND:${fmt(m.ends_at)}\nSUMMARY:${m.title}\nLOCATION:${m.video_url ?? m.location ?? ''}\nEND:VEVENT\nEND:VCALENDAR`
  const a = document.createElement('a'); a.href = `data:text/calendar;charset=utf-8,${encodeURIComponent(body)}`; a.download = `meeting-${m.id}.ics`; a.click()
}
</script>

<template>
  <div class="space-y-8">
    <section v-for="(group, key) in { upcoming, past }" :key="key">
      <h2 class="text-lg font-semibold mb-3">{{ $t(`investor.meetings.${key}`) }}</h2>
      <EmptyState v-if="!group.length" compact :title="$t('common.noData')" />
      <div v-else class="space-y-3">
        <article v-for="m in group" :key="m.id" class="card card-pad">
          <div class="flex flex-col sm:flex-row sm:items-start gap-3">
            <div class="flex-1 min-w-0">
              <div class="flex flex-wrap items-center gap-2"><AppBadge kind="meeting" :value="m.status" /><span class="text-sm text-muted num">{{ dateTime(m.starts_at) }}</span></div>
              <h3 class="mt-1 font-semibold">{{ m.title }}</h3>
              <p class="text-sm text-muted">{{ m.opportunity.name }} · {{ m.location ?? m.video_url ?? '' }}</p>
              <p class="text-xs text-muted mt-2">{{ $t('investor.meetings.participants') }}: {{ m.participants.map((p) => p.name).join('، ') }}</p>
              <div v-if="m.minutes" class="mt-3 rounded-lg bg-bg p-3 text-sm"><p class="text-xs font-medium text-muted mb-1">{{ $t('investor.meetings.minutes') }}</p><p class="leading-6">{{ m.minutes }}</p><ul v-if="m.actions?.length" class="mt-2 space-y-1"><li v-for="a in m.actions" :key="a.description" class="flex items-center gap-2 text-xs"><span class="h-1.5 w-1.5 rounded-full" :class="a.done_at ? 'bg-success' : 'bg-warning'" />{{ a.description }} — {{ a.owner }}</li></ul></div>
            </div>
            <div class="flex sm:flex-col gap-2 shrink-0">
              <AppButton v-if="m.video_url && m.status === 'scheduled'" :href="m.video_url" size="sm">{{ $t('investor.dashboard.joinMeeting') }}</AppButton>
              <AppButton v-if="m.status === 'scheduled'" size="sm" variant="secondary" @click="ics(m)">{{ $t('investor.meetings.addToCalendar') }}</AppButton>
            </div>
          </div>
        </article>
      </div>
    </section>
  </div>
</template>

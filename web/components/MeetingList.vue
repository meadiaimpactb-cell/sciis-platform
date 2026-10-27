<script setup lang="ts">
import type { Meeting } from '~/types/api'
const props = defineProps<{ meetings: Meeting[] }>()
/** MTG-02 — an action was marked done / reopened; the page reloads the list. */
defineEmits<{ (e: 'changed'): void }>()
const { date, dateTime } = useFormat()
const upcoming = computed(() => props.meetings.filter((m) => m.status === 'scheduled' && new Date(m.starts_at) >= new Date()).sort((a, b) => a.starts_at.localeCompare(b.starts_at)))
const past = computed(() => props.meetings.filter((m) => !upcoming.value.includes(m)).sort((a, b) => b.starts_at.localeCompare(a.starts_at)))
/** MTG-05 — Google / Outlook / .ics for a scheduled meeting. */
const calendarItem = (m: Meeting) => ({ title: m.title, starts_at: m.starts_at, ends_at: m.ends_at, location: m.video_url ?? m.location ?? null, details: m.opportunity.name })
</script>

<template>
  <div class="space-y-8">
    <section v-for="(group, key) in { upcoming, past }" :key="key">
      <h2 class="card-title mb-3">{{ $t(`investor.meetings.${key}`) }}</h2>
      <EmptyState v-if="!group.length" compact :title="$t('common.noData')" />
      <div v-else class="space-y-3">
        <article v-for="m in group" :key="m.id" class="card card-pad">
          <div class="flex flex-col sm:flex-row sm:items-start gap-3">
            <div class="flex-1 min-w-0">
              <div class="flex flex-wrap items-center gap-2"><AppBadge kind="meeting" :value="m.status" /><span class="text-sm text-muted num">{{ dateTime(m.starts_at) }}</span></div>
              <h3 class="mt-1.5 text-lg text-ink">{{ m.title }}</h3>
              <p class="text-sm text-muted">{{ m.opportunity.name }} · {{ m.location ?? m.video_url ?? '' }}</p>
              <p class="text-xs text-muted mt-2">{{ $t('investor.meetings.participants') }}: {{ m.participants.map((p) => p.name).join('، ') }}</p>
              <div v-if="m.minutes" class="mt-3 rounded-xl border border-border bg-glass p-3 text-sm"><p class="text-xs font-medium text-muted mb-1">{{ $t('investor.meetings.minutes') }}</p><p class="leading-6">{{ m.minutes }}</p><ul v-if="m.actions?.length" class="mt-3 space-y-2">
                <li v-for="a in m.actions" :key="a.id ?? a.description" class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                  <span class="h-1.5 w-1.5 rounded-full shrink-0" :class="a.done_at ? 'bg-success' : 'bg-warning'" aria-hidden="true" />
                  <span :class="a.done_at && 'line-through text-muted'">{{ a.description }}</span>
                  <span class="text-muted">— {{ a.owner ?? '—' }}<template v-if="a.is_mine"> ({{ $t('components.meetingActions.mine') }})</template></span>
                  <span v-if="a.due_at && !a.done_at" class="text-muted">· {{ $t('components.meetingActions.dueAt') }}: <span class="num">{{ date(a.due_at) }}</span></span>
                  <MeetingActionToggle v-if="a.can_toggle && a.id" :id="a.id" class="ms-auto" :done="!!a.done_at" :description="a.description" @changed="$emit('changed')" />
                </li>
              </ul></div>
            </div>
            <div class="flex sm:flex-col gap-2 shrink-0">
              <AppButton v-if="m.video_url && m.status === 'scheduled'" :href="m.video_url" size="sm">{{ $t('investor.dashboard.joinMeeting') }}</AppButton>
              <AddToCalendar v-if="m.status === 'scheduled'" :item="calendarItem(m)" class="sm:flex-col" />
            </div>
          </div>
        </article>
      </div>
    </section>
  </div>
</template>

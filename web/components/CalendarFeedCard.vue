<script setup lang="ts">
/**
 * MTG-05 — personal calendar subscription: a secret iCal URL (meetings + registered events) for Google Calendar /
 * Microsoft 365 "subscribe from URL". GET / POST (regenerate) / DELETE (revoke) /me/calendar-feed.
 */
import type { CalendarFeed } from '~/types/collab'
import { confirmDialog, toastApiError, toastError, toastSuccess } from '~/utils/notify'

const api = useApi()
const { t } = useI18n()
const { dateTime, relative } = useFormat()
const feed = ref<CalendarFeed | null>(null)
const loading = ref(true)
const busy = ref<'create' | 'revoke' | null>(null)

onMounted(async () => {
  try {
    feed.value = (await api.get<{ data: CalendarFeed }>('/me/calendar-feed')).data
  } catch {
    feed.value = null
  } finally {
    loading.value = false
  }
})

const regenerate = async () => {
  if (feed.value?.active && !(await confirmDialog(t('collab.calendarFeed.regenerateTitle'), t('collab.calendarFeed.regenerateText'), t('collab.calendarFeed.regenerate')))) return
  busy.value = 'create'
  try {
    const wasActive = !!feed.value?.active
    feed.value = (await api.post<{ data: CalendarFeed }>('/me/calendar-feed')).data
    toastSuccess(t(wasActive ? 'collab.calendarFeed.regenerated' : 'collab.calendarFeed.created'))
  } catch (e) {
    toastApiError(e)
  } finally {
    busy.value = null
  }
}
const revoke = async () => {
  if (!(await confirmDialog(t('collab.calendarFeed.revokeTitle'), t('collab.calendarFeed.revokeText'), t('collab.calendarFeed.revoke')))) return
  busy.value = 'revoke'
  try {
    feed.value = (await api.del<{ data: CalendarFeed }>('/me/calendar-feed')).data
    toastSuccess(t('collab.calendarFeed.revoked'))
  } catch (e) {
    toastApiError(e)
  } finally {
    busy.value = null
  }
}
const copy = async () => {
  if (!feed.value?.url) return
  try {
    await navigator.clipboard.writeText(feed.value.url)
    toastSuccess(t('notify.linkCopied'))
  } catch {
    toastError(t('collab.calendarFeed.copyFailed'))
  }
}
</script>

<template>
  <SectionCard :title="$t('collab.calendarFeed.title')" :sub="$t('collab.calendarFeed.sub')">
    <p v-if="loading" class="text-sm text-muted">{{ $t('common.loading') }}</p>
    <template v-else-if="feed?.active && feed.url">
      <label class="label" for="calendar-feed-url">{{ $t('collab.calendarFeed.url') }}</label>
      <div class="flex flex-col sm:flex-row gap-2">
        <input id="calendar-feed-url" :value="feed.url" readonly class="input ltr text-start text-xs flex-1" dir="ltr" @focus="($event.target as HTMLInputElement).select()">
        <AppButton variant="secondary" @click="copy">{{ $t('common.copy') }}</AppButton>
      </div>
      <p class="help">{{ $t('collab.calendarFeed.secretHint') }}</p>
      <div class="mt-4 flex flex-wrap gap-2">
        <AppButton v-if="feed.google_url" :href="feed.google_url" size="sm">{{ $t('collab.calendarFeed.google') }}</AppButton>
        <AppButton v-if="feed.outlook_url" :href="feed.outlook_url" size="sm" variant="secondary">{{ $t('collab.calendarFeed.outlook') }}</AppButton>
        <AppButton v-if="feed.outlook_live_url" :href="feed.outlook_live_url" size="sm" variant="secondary">{{ $t('collab.calendarFeed.outlookLive') }}</AppButton>
      </div>
      <dl class="mt-4 flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted">
        <div v-if="feed.created_at"><dt class="inline">{{ $t('collab.calendarFeed.createdAt') }}:</dt> <dd class="inline num text-ink">{{ dateTime(feed.created_at) }}</dd></div>
        <div><dt class="inline">{{ $t('collab.calendarFeed.lastAccess') }}:</dt> <dd class="inline num text-ink">{{ feed.last_accessed_at ? relative(feed.last_accessed_at) : $t('collab.calendarFeed.never') }}</dd></div>
      </dl>
      <div class="mt-4 pt-4 border-t border-border flex flex-wrap justify-end gap-2">
        <AppButton size="sm" variant="ghost" :loading="busy === 'create'" :disabled="!!busy" @click="regenerate">{{ $t('collab.calendarFeed.regenerate') }}</AppButton>
        <AppButton size="sm" variant="ghost" class="text-danger" :loading="busy === 'revoke'" :disabled="!!busy" @click="revoke">{{ $t('collab.calendarFeed.revoke') }}</AppButton>
      </div>
    </template>
    <div v-else class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <p class="text-sm text-muted leading-6">{{ $t('collab.calendarFeed.inactive') }}</p>
      <AppButton size="sm" :loading="busy === 'create'" @click="regenerate">{{ $t('collab.calendarFeed.create') }}</AppButton>
    </div>
  </SectionCard>
</template>

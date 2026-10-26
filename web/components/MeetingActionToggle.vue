<script setup lang="ts">
/** MTG-02 — marks a meeting follow-up action done or reopens it (assignee or meeting staff); emits `changed` once the server confirmed. */
import { toastApiError, toastSuccess } from '~/utils/notify'
const props = defineProps<{ id: number; done: boolean; description: string }>()
const emit = defineEmits<{ (e: 'changed', doneAt: string | null): void }>()
const api = useApi()
const { t } = useI18n()
const busy = ref(false)
const toggle = async () => {
  busy.value = true
  try {
    const r = await api.patch<{ data: { done_at: string | null } }>(`/meeting-actions/${props.id}`, { done: !props.done })
    toastSuccess(t(props.done ? 'notify.reopened' : 'notify.markedDone', { item: `${t('notify.entities.meetingAction')} «${props.description}»` }))
    emit('changed', r.data.done_at)
  } catch (e) { toastApiError(e) } finally { busy.value = false }
}
</script>

<template>
  <AppButton size="sm" :variant="done ? 'ghost' : 'outline'" :loading="busy" :aria-label="`${done ? $t('components.meetingActions.undo') : $t('components.meetingActions.markDone')} — ${description}`" @click="toggle">
    {{ done ? $t('components.meetingActions.undo') : $t('components.meetingActions.markDone') }}
  </AppButton>
</template>

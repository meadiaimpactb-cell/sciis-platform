<script setup lang="ts">
/**
 * Form-level message (rate limit 429) — placed just above a form's submit button:
 *   <FormAlert />                                  (reads the form(s) of the parent component via useZodForm)
 *   <FormAlert :message="someOtherError" />        (explicit message)
 * Mounting it tells useZodForm the form shows its own alert, so the 429 is not also toasted.
 */
import { ZOD_FORM_KEY } from '~/composables/useZodForm'

const props = defineProps<{ message?: string | null }>()
const form = inject(ZOD_FORM_KEY, null)
const text = computed(() => props.message ?? form?.formError.value ?? null)

onMounted(() => {
  if (form) form.alerts.value++
})
onBeforeUnmount(() => {
  if (form) form.alerts.value--
})
</script>

<template>
  <div v-if="text" class="flex items-start gap-3 rounded-xl border border-warning/40 bg-warning/5 px-4 py-3 text-sm text-ink" role="alert" aria-live="assertive">
    <svg class="mt-0.5 h-5 w-5 shrink-0 text-warning" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>
    <p class="leading-6">{{ text }}</p>
  </div>
</template>

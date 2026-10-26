<script setup lang="ts">
/**
 * One-field textarea form (answer an info request, staff reply, …) for lists where every row has its own input.
 * Validated by the central layer (`requiredString` + RULES lengths); `action(text)` performs the API call, so a 422
 * lands under the field and any other error becomes a toast (useZodForm). Emits `done` after a successful submit.
 */
import { z } from 'zod'
import { requiredString, RULES } from '~/utils/validation'

const props = withDefaults(
  defineProps<{
    /** i18n key of the label (also used in the "… is required" message) */
    labelKey: string
    placeholder?: string
    help?: string
    submitLabel: string
    action: (text: string) => Promise<unknown>
    min?: number
    max?: number
    rows?: number
    /** server field name of the text (422 errors are mapped back to the textarea) */
    serverField?: string
  }>(),
  { min: RULES.answerMin, max: RULES.answerMax, rows: 3, serverField: 'answer' },
)
const emit = defineEmits<{ (e: 'done'): void }>()
const form = useZodForm(z.object({ text: requiredString(props.labelKey, props.min, props.max) }), { text: '' }, { aliases: { [props.serverField]: 'text' } })
const submit = () =>
  form.submit(async (v) => {
    await props.action(v.text.trim())
    form.reset()
    emit('done')
  })
</script>

<template>
  <form class="space-y-2" novalidate @submit.prevent="submit">
    <FormField v-model="form.values.text" name="text" type="textarea" :label="$t(labelKey)" :placeholder="placeholder" :help="help" :error="form.errors.value.text" :rows="rows" :maxlength="max" required />
    <FormAlert :message="form.formError.value" />
    <AppButton type="submit" size="sm" :loading="form.submitting.value">{{ submitLabel }}</AppButton>
  </form>
</template>

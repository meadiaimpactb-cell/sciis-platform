<script setup lang="ts">
/**
 * Form field wrapper with label, help, error, and a11y wiring. Supports input/textarea/select/checkbox/chips via `type`.
 * Works with v-model. Options: {value,label}[]
 */
const props = withDefaults(
  defineProps<{
    modelValue?: unknown
    label?: string
    name: string
    type?: 'text' | 'email' | 'tel' | 'password' | 'number' | 'url' | 'date' | 'datetime-local' | 'textarea' | 'select' | 'checkbox' | 'chips' | 'radio-cards'
    placeholder?: string
    help?: string
    error?: string
    required?: boolean
    disabled?: boolean
    options?: { value: string | number; label: string; hint?: string }[]
    rows?: number
    maxlength?: number
    autocomplete?: string
    ltr?: boolean
    min?: number
    max?: number
    step?: number
  }>(),
  { type: 'text', required: false, disabled: false, rows: 4 },
)
const emit = defineEmits<{ (e: 'update:modelValue', v: unknown): void; (e: 'blur'): void }>()
const id = `f-${props.name.replace(/[^a-z0-9_-]/gi, '-')}-${useId()}`
const errId = `${id}-err`
const helpId = `${id}-help`
const describedBy = computed(() => [props.error ? errId : null, props.help ? helpId : null].filter(Boolean).join(' ') || undefined)

const onInput = (e: Event) => {
  const el = e.target as HTMLInputElement
  if (props.type === 'number') emit('update:modelValue', el.value === '' ? null : Number(el.value))
  else emit('update:modelValue', el.value)
}
const toggleChip = (v: string | number) => {
  const arr = Array.isArray(props.modelValue) ? [...(props.modelValue as (string | number)[])] : []
  const i = arr.indexOf(v)
  if (i >= 0) arr.splice(i, 1)
  else arr.push(v)
  emit('update:modelValue', arr)
}
const chipActive = (v: string | number) => Array.isArray(props.modelValue) && (props.modelValue as unknown[]).includes(v)
const charCount = computed(() => (typeof props.modelValue === 'string' ? props.modelValue.length : 0))
</script>

<template>
  <div class="min-w-0">
    <!-- checkbox -->
    <label v-if="type === 'checkbox'" :for="id" class="flex items-start gap-3 cursor-pointer select-none" :class="disabled && 'opacity-60 cursor-not-allowed'">
      <input :id="id" type="checkbox" :name="name" :checked="!!modelValue" :disabled="disabled" :aria-invalid="!!error" :aria-describedby="describedBy" class="mt-1 h-4 w-4 rounded border-border text-blue focus:ring-blue" @change="emit('update:modelValue', ($event.target as HTMLInputElement).checked)" />
      <span class="text-sm text-ink leading-6">
        <slot name="label">{{ label }}</slot>
        <span v-if="required" class="text-danger ms-0.5" aria-hidden="true">*</span>
        <span v-if="help" :id="helpId" class="block text-xs text-muted">{{ help }}</span>
        <span v-if="error" :id="errId" class="block text-xs text-danger" role="alert">{{ error }}</span>
      </span>
    </label>

    <template v-else>
      <label v-if="label" :for="type === 'chips' || type === 'radio-cards' ? undefined : id" :id="type === 'chips' || type === 'radio-cards' ? `${id}-label` : undefined" class="label">
        {{ label }}
        <span v-if="required" class="text-danger ms-0.5" aria-hidden="true">*</span>
        <span v-else-if="!required && type !== 'chips'" class="text-xs text-muted font-normal ms-1">({{ $t('common.optional') }})</span>
      </label>

      <!-- textarea -->
      <textarea v-if="type === 'textarea'" :id="id" :name="name" :value="(modelValue as string) ?? ''" :placeholder="placeholder" :rows="rows" :maxlength="maxlength" :disabled="disabled" :required="required" :aria-invalid="!!error" :aria-describedby="describedBy" class="input resize-y" :class="[error && 'input-error']" @input="onInput" @blur="emit('blur')" />

      <!-- select -->
      <select v-else-if="type === 'select'" :id="id" :name="name" :value="(modelValue as string | number) ?? ''" :disabled="disabled" :required="required" :aria-invalid="!!error" :aria-describedby="describedBy" class="input" :class="[error && 'input-error']" @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value || null)" @blur="emit('blur')">
        <option value="">{{ placeholder ?? $t('common.selectPlaceholder') }}</option>
        <option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>

      <!-- chips (multi-select) -->
      <div v-else-if="type === 'chips'" role="group" :aria-labelledby="`${id}-label`" :aria-describedby="describedBy" class="flex flex-wrap gap-2">
        <button v-for="o in options" :key="o.value" type="button" class="chip" :class="chipActive(o.value) && 'chip-active'" :aria-pressed="chipActive(o.value)" :disabled="disabled" @click="toggleChip(o.value)">
          <svg v-if="chipActive(o.value)" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
          {{ o.label }}
        </button>
      </div>

      <!-- radio cards -->
      <div v-else-if="type === 'radio-cards'" role="radiogroup" :aria-labelledby="`${id}-label`" :aria-describedby="describedBy" class="grid gap-3 sm:grid-cols-2">
        <button v-for="o in options" :key="o.value" type="button" role="radio" :aria-checked="modelValue === o.value" class="text-start rounded-xl border p-4 transition kbd-focus" :class="modelValue === o.value ? 'border-blue bg-blue-soft/60 ring-1 ring-blue' : 'border-border bg-surface hover:border-blue/50'" :disabled="disabled" @click="emit('update:modelValue', o.value)">
          <span class="flex items-center gap-2">
            <span class="h-4 w-4 rounded-full border-2 flex items-center justify-center" :class="modelValue === o.value ? 'border-blue' : 'border-border'" aria-hidden="true"><span v-if="modelValue === o.value" class="h-2 w-2 rounded-full bg-blue" /></span>
            <span class="font-medium text-ink">{{ o.label }}</span>
          </span>
          <span v-if="o.hint" class="block text-sm text-muted mt-1 ps-6">{{ o.hint }}</span>
        </button>
      </div>

      <!-- input -->
      <input v-else :id="id" :name="name" :type="type" :value="(modelValue as string | number) ?? ''" :placeholder="placeholder" :maxlength="maxlength" :disabled="disabled" :required="required" :autocomplete="autocomplete" :min="min" :max="max" :step="step" :aria-invalid="!!error" :aria-describedby="describedBy" class="input" :class="[error && 'input-error', ltr && 'ltr text-start']" :dir="ltr ? 'ltr' : undefined" @input="onInput" @blur="emit('blur')" />

      <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
          <p v-if="error" :id="errId" class="error-text" role="alert">{{ error }}</p>
          <p v-else-if="help" :id="helpId" class="help">{{ help }}</p>
        </div>
        <p v-if="maxlength && (type === 'textarea' || type === 'text')" class="help num shrink-0">{{ charCount }}/{{ maxlength }}</p>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
/**
 * International mobile field (vue-tel-input, default country SA). The model is ALWAYS E.164 (`+9665…`) or '' —
 * never the local spelling — so numbers compare and SMS reliably. Validity is decided by libphonenumber
 * (utils/validation.ts) and re-checked by the API (laravel-phone). Optional verification badge.
 */
import type { Component } from 'vue'
import { ZOD_FORM_KEY } from '~/composables/useZodForm'
import { RULES } from '~/utils/validation'

const props = withDefaults(
  defineProps<{
    modelValue?: string | null
    name: string
    label?: string
    help?: string
    error?: string
    required?: boolean
    disabled?: boolean
    /** null = don't show a badge; true/false = verified / not verified */
    verified?: boolean | null
  }>(),
  { modelValue: '', required: false, disabled: false, verified: null },
)
const emit = defineEmits<{ (e: 'update:modelValue', v: string): void; (e: 'blur'): void; (e: 'verify'): void }>()
const { locale } = useI18n()
const id = `phone-${props.name.replace(/[^a-z0-9_-]/gi, '-')}-${useId()}`
const errId = `${id}-err`
const helpId = `${id}-help`
const display = ref(props.modelValue ?? '')
watch(() => props.modelValue, (v) => { if ((v ?? '') !== '' && v !== display.value && !display.value) display.value = v ?? '' })

interface PhoneObject { number?: string; valid?: boolean; nationalNumber?: string; countryCallingCode?: string }
const onInput = (_formatted: string, phone?: PhoneObject) => {
  // vue-tel-input reports E.164 in `number`. With `showDialCode` the box always holds "+966": a dial code alone is
  // an EMPTY field (optional phones must stay valid, required ones show "required"), otherwise keep the raw digits
  // so the schema shows "invalid" while the number is incomplete.
  if (phone?.number && phone.valid) return emit('update:modelValue', phone.number)
  const digits = (display.value ?? '').replace(/\D/g, '')
  const onlyDialCode = !digits || (!phone?.nationalNumber && digits === (phone?.countryCallingCode ?? digits))
  emit('update:modelValue', onlyDialCode ? '' : display.value.replace(/[^\d+]/g, ''))
}
const zodForm = inject(ZOD_FORM_KEY, null)
const onBlur = () => {
  emit('blur')
  if (zodForm?.has(props.name)) void zodForm.validateField(props.name)
}
const describedBy = computed(() => [props.error ? errId : null, props.help ? helpId : null].filter(Boolean).join(' ') || undefined)

// vue-tel-input + its stylesheet (~110 KB of flag sprites) load in the browser after mount, so they never block the
// page's first paint; until then the same-size plain field below is shown (no layout shift).
const VueTelInput = shallowRef<Component | null>(null)
onMounted(async () => {
  const [mod] = await Promise.all([import('vue-tel-input'), import('vue-tel-input/vue-tel-input.css')])
  VueTelInput.value = mod.VueTelInput as Component
})
</script>

<template>
  <div>
    <div class="flex items-center justify-between gap-2 mb-1.5">
      <label :for="id" class="label !mb-0">
        {{ label }}<span v-if="required" class="text-danger ms-0.5" aria-hidden="true">*</span>
        <span v-else class="text-xs text-muted font-normal ms-1">({{ $t('common.optional') }})</span>
      </label>
      <span v-if="verified === true" class="inline-flex items-center gap-1 rounded-full bg-success/10 text-success text-[11px] font-medium px-2 py-0.5">
        <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
        {{ $t('phoneField.verified') }}
      </span>
      <span v-else-if="verified === false" class="inline-flex items-center gap-2 text-[11px]">
        <span class="rounded-full bg-warning/10 text-warning font-medium px-2 py-0.5">{{ $t('phoneField.unverified') }}</span>
        <button type="button" class="link text-[11px]" @click="emit('verify')">{{ $t('phoneField.verifyNow') }}</button>
      </span>
    </div>
    <component
      :is="VueTelInput"
      v-if="VueTelInput"
      v-model="display"
      :default-country="RULES.defaultCountry"
      mode="international"
      :disabled="disabled"
      :auto-default-country="false"
      :valid-characters-only="true"
      :input-options="{ id, name, placeholder: '+966 5X XXX XXXX', 'aria-describedby': describedBy, 'aria-invalid': !!error, autocomplete: 'tel', type: 'tel', showDialCode: true }"
      :dropdown-options="{ showFlags: true, showSearchBox: true, showDialCodeInSelection: false, searchBoxPlaceholder: locale === 'ar' ? 'ابحث عن دولة' : 'Search country' }"
      :preferred-countries="['SA', 'AE', 'KW', 'BH', 'QA', 'OM', 'EG', 'JO']"
      class="sciis-tel"
      :class="error && 'sciis-tel--error'"
      dir="ltr"
      @on-input="onInput"
      @blur="onBlur"
    />
    <input v-else :id="id" type="tel" class="input ltr" dir="ltr" :value="modelValue ?? ''" placeholder="+966 5X XXX XXXX" disabled>
    <p v-if="help && !error" :id="helpId" class="help">{{ help }}</p>
    <p v-if="error" :id="errId" class="error-text" role="alert">{{ error }}</p>
  </div>
</template>

<style>
/* Brand skin for vue-tel-input (same height/radius/focus ring as .input → no layout shift when it mounts) */
.sciis-tel.vue-tel-input { border: 1px solid #E3E7F0; border-radius: 0.5rem; background: #fff; min-height: 2.625rem; box-shadow: none; }
.sciis-tel.vue-tel-input:focus-within { border-color: #58B4A8; box-shadow: 0 0 0 4px rgb(88 180 168 / 0.15); }
.sciis-tel--error.vue-tel-input { border-color: #B91C1C; }
.sciis-tel .vti__input { font-size: 0.875rem; padding: 0.625rem 0.75rem; border-radius: 0 0.5rem 0.5rem 0; background: transparent; }
.sciis-tel .vti__dropdown { border-radius: 0.5rem 0 0 0.5rem; }
.sciis-tel .vti__dropdown-list { z-index: 50; border-radius: 0.5rem; }
</style>

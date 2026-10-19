<script setup lang="ts">
/** File picker + drop zone (DOC-01: size/mime from settings). Emits the File; the page posts it. */
const props = withDefaults(defineProps<{ label?: string; accept?: string; maxMb?: number; disabled?: boolean; error?: string }>(), { accept: '.pdf,.png,.jpg,.jpeg,.docx,.xlsx,.pptx', maxMb: 25 })
const emit = defineEmits<{ (e: 'file', f: File): void; (e: 'error', m: string): void }>()
const input = ref<HTMLInputElement | null>(null)
const over = ref(false)
const id = `u-${useId()}`
const { t } = useI18n()
const handle = (f: File | undefined) => {
  if (!f) return
  if (f.size > props.maxMb * 1024 * 1024) return emit('error', t('components.upload.maxSize', { n: props.maxMb }))
  emit('file', f)
}
</script>

<template>
  <div>
    <label v-if="label" :for="id" class="label">{{ label }}</label>
    <div
      class="rounded-xl border-2 border-dashed p-5 text-center text-sm transition"
      :class="[over ? 'border-blue bg-blue-soft/40' : 'border-border bg-bg/40', disabled && 'opacity-60']"
      @dragover.prevent="over = true"
      @dragleave="over = false"
      @drop.prevent="over = false; handle($event.dataTransfer?.files?.[0])"
    >
      <p class="text-muted">
        {{ $t('components.upload.drop') }}
        <button type="button" class="text-blue font-medium hover:underline kbd-focus rounded" :disabled="disabled" @click="input?.click()">{{ $t('components.upload.browse') }}</button>
      </p>
      <p class="text-xs text-muted mt-1">{{ $t('components.upload.maxSize', { n: maxMb }) }}</p>
      <input :id="id" ref="input" type="file" class="sr-only" :accept="accept" :disabled="disabled" @change="handle(($event.target as HTMLInputElement).files?.[0]); ($event.target as HTMLInputElement).value = ''" />
    </div>
    <p v-if="error" class="error-text" role="alert">{{ error }}</p>
  </div>
</template>

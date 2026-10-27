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
      class="rounded-2xl border-2 border-dashed p-5 text-center text-sm transition"
      :class="[over ? 'border-accent bg-accent/10' : 'border-ink/20 bg-ink/5 hover:border-accent/60', disabled && 'opacity-60']"
      @dragover.prevent="over = true"
      @dragleave="over = false"
      @drop.prevent="over = false; handle($event.dataTransfer?.files?.[0])"
    >
      <span class="mx-auto mb-2.5 flex h-10 w-10 items-center justify-center rounded-full bg-accent/10 text-accent-text" aria-hidden="true">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15.5V4.5M7.5 9 12 4.5 16.5 9" /><path d="M4.5 15v2.5a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V15" /></svg>
      </span>
      <p class="text-text">
        {{ $t('components.upload.drop') }}
        <button type="button" class="link rounded kbd-focus disabled:cursor-not-allowed" :disabled="disabled" @click="input?.click()">{{ $t('components.upload.browse') }}</button>
      </p>
      <p class="text-xs text-muted mt-1">{{ $t('components.upload.maxSize', { n: maxMb }) }}</p>
      <input :id="id" ref="input" type="file" class="sr-only" :accept="accept" :disabled="disabled" @change="handle(($event.target as HTMLInputElement).files?.[0]); ($event.target as HTMLInputElement).value = ''" />
    </div>
    <p v-if="error" class="error-text" role="alert">{{ error }}</p>
  </div>
</template>

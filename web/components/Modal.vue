<script setup lang="ts">
/** Accessible modal dialog: focus trap (basic), Escape to close, aria-modal; focus returns to the opener on close (UX-04). */
const props = withDefaults(defineProps<{ open: boolean; title?: string; size?: 'sm' | 'md' | 'lg' }>(), { size: 'md' })
const emit = defineEmits<{ (e: 'close'): void }>()
const panel = ref<HTMLElement | null>(null)
const titleId = `m-${useId()}`
let opener: HTMLElement | null = null

const onKey = (e: KeyboardEvent) => {
  if (e.key === 'Escape') emit('close')
  if (e.key === 'Tab' && panel.value) {
    const f = panel.value.querySelectorAll<HTMLElement>('a[href],button:not([disabled]),input:not([disabled]),select,textarea,[tabindex]:not([tabindex="-1"])')
    if (!f.length) return
    const first = f[0]
    const last = f[f.length - 1]
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus() }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus() }
  }
}
watch(
  () => props.open,
  (v) => {
    if (!import.meta.client) return
    if (v) {
      opener = document.activeElement instanceof HTMLElement ? document.activeElement : null
      document.addEventListener('keydown', onKey)
      document.body.style.overflow = 'hidden'
      nextTick(() => panel.value?.querySelector<HTMLElement>('input,textarea,select,button')?.focus())
    } else {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = ''
      if (opener?.isConnected) nextTick(() => opener?.focus())
      opener = null
    }
  },
)
onBeforeUnmount(() => {
  if (import.meta.client) { document.removeEventListener('keydown', onKey); document.body.style.overflow = '' }
})
const width = computed(() => ({ sm: 'max-w-md', md: 'max-w-lg', lg: 'max-w-3xl' })[props.size])
</script>

<template>
  <Teleport to="body">
    <Transition name="page">
      <div v-if="open" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4" role="presentation">
        <div class="absolute inset-0 bg-ink/50" @click="emit('close')" />
        <div ref="panel" role="dialog" aria-modal="true" :aria-labelledby="title ? titleId : undefined" class="relative w-full bg-surface rounded-t-2xl sm:rounded-2xl shadow-pop border border-border max-h-[92vh] flex flex-col" :class="width">
          <div class="flex items-center justify-between gap-4 px-5 py-4 border-b border-border">
            <h2 v-if="title" :id="titleId" class="text-lg font-semibold">{{ title }}</h2>
            <button type="button" class="h-9 w-9 rounded-lg hover:bg-bg flex items-center justify-center text-muted kbd-focus" :aria-label="$t('components.modal.close')" @click="emit('close')">
              <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
            </button>
          </div>
          <div class="px-5 py-4 overflow-y-auto"><slot /></div>
          <div v-if="$slots.footer" class="px-5 py-4 border-t border-border flex flex-wrap items-center justify-end gap-2 bg-bg/40 rounded-b-2xl"><slot name="footer" /></div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

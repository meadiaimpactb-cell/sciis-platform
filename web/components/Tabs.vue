<script setup lang="ts">
/** Keyboard-navigable tabs (UX-04): Arrow keys, Home/End; roving tabindex. */
const props = withDefaults(defineProps<{ tabs: { key: string; label: string; count?: number; locked?: boolean; icon?: string }[]; modelValue: string; variant?: 'underline' | 'pills' }>(), { variant: 'underline' })
const emit = defineEmits<{ (e: 'update:modelValue', v: string): void }>()
const btns = ref<HTMLButtonElement[]>([])
const idx = computed(() => props.tabs.findIndex((t) => t.key === props.modelValue))
// UX-04 — `aria-controls` only points at panels the page actually renders (`id="panel-<key>"`), never at a missing id.
const panels = ref<Record<string, boolean>>({})
onMounted(() => nextTick(() => { panels.value = Object.fromEntries(props.tabs.map((t) => [t.key, !!document.getElementById(`panel-${t.key}`)])) }))
const onKey = (e: KeyboardEvent) => {
  const { locale } = useNuxtApp().$i18n
  const rtl = locale.value === 'ar'
  const n = props.tabs.length
  let next = idx.value
  const fwd = rtl ? 'ArrowLeft' : 'ArrowRight'
  const back = rtl ? 'ArrowRight' : 'ArrowLeft'
  if (e.key === fwd) next = (idx.value + 1) % n
  else if (e.key === back) next = (idx.value - 1 + n) % n
  else if (e.key === 'Home') next = 0
  else if (e.key === 'End') next = n - 1
  else return
  e.preventDefault()
  emit('update:modelValue', props.tabs[next].key)
  nextTick(() => btns.value[next]?.focus())
}
</script>

<template>
  <div role="tablist" class="flex gap-1 overflow-x-auto scrollbar-thin" :class="variant === 'underline' ? 'border-b border-border' : 'bg-bg p-1 rounded-xl'" @keydown="onKey">
    <button
      v-for="(t, i) in tabs"
      :key="t.key"
      :ref="(el) => { if (el) btns[i] = el as HTMLButtonElement }"
      role="tab"
      type="button"
      :id="`tab-${t.key}`"
      :aria-selected="modelValue === t.key"
      :aria-controls="panels[t.key] ? `panel-${t.key}` : undefined"
      :tabindex="modelValue === t.key ? 0 : -1"
      class="inline-flex items-center gap-2 whitespace-nowrap text-sm font-medium transition kbd-focus"
      :class="[
        variant === 'underline' ? 'px-3 py-2.5 -mb-px border-b-2' : 'px-3 py-2 rounded-lg',
        modelValue === t.key
          ? variant === 'underline' ? 'border-blue text-blue' : 'bg-surface text-ink shadow-card'
          : variant === 'underline' ? 'border-transparent text-muted hover:text-ink hover:border-border' : 'text-muted hover:text-ink',
      ]"
      @click="emit('update:modelValue', t.key)"
    >
      <svg v-if="t.locked" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2" /><path d="M8 11V7a4 4 0 118 0v4" /></svg>
      {{ t.label }}
      <span v-if="t.count != null" class="num rounded-full bg-bg px-1.5 py-0.5 text-xs text-muted" :class="modelValue === t.key && variant === 'underline' && 'bg-blue-soft text-blue'">{{ t.count }}</span>
    </button>
  </div>
</template>

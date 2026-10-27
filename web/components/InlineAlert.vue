<script setup lang="ts">
withDefaults(defineProps<{ tone?: 'info' | 'warning' | 'danger' | 'success'; title?: string }>(), { tone: 'info' })
const tones = { info: 'border-accent/30 bg-accent/10 text-ink', warning: 'border-warning/30 bg-warning/10 text-ink', danger: 'border-danger/30 bg-danger/10 text-ink', success: 'border-success/30 bg-success/10 text-ink' }
const icons = { info: 'text-accent-text', warning: 'text-warning', danger: 'text-danger', success: 'text-success' }
</script>

<template>
  <div class="rounded-xl border px-4 py-3 text-sm flex gap-3" :class="tones[tone]" role="status">
    <svg class="h-5 w-5 shrink-0 mt-0.5" :class="icons[tone]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M18 10A8 8 0 11 2 10a8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" /></svg>
    <div class="min-w-0 flex-1">
      <p v-if="title" class="font-semibold text-ink mb-0.5">{{ title }}</p>
      <div class="text-text leading-6"><slot /></div>
    </div>
  </div>
</template>

<script setup lang="ts">
const { toasts, dismiss } = useToast()
const tone = { success: 'border-success/40 bg-success/10 text-success', error: 'border-danger/40 bg-danger/10 text-danger', info: 'border-blue/40 bg-blue-soft text-blue' }
</script>

<template>
  <div class="fixed bottom-4 inset-x-4 sm:inset-x-auto sm:end-4 z-[60] flex flex-col gap-2 sm:w-80" aria-live="polite">
    <TransitionGroup name="page">
      <div v-for="t in toasts" :key="t.id" class="rounded-xl border px-4 py-3 text-sm shadow-pop flex items-start gap-3 backdrop-blur bg-surface/95" :class="tone[t.kind]" role="status">
        <span class="flex-1">{{ t.message }}</span>
        <button type="button" class="text-current/70 hover:text-current" :aria-label="$t('common.close')" @click="dismiss(t.id)">×</button>
      </div>
    </TransitionGroup>
  </div>
</template>

<script setup lang="ts">
/** Persistent legal line: "المنصة لا تُجري عمليات مالية ولا تقدم توصية استثمارية" */
withDefaults(defineProps<{ variant?: 'line' | 'box' | 'dark' }>(), { variant: 'line' })
</script>

<template>
  <div
    v-if="variant === 'box'"
    class="rounded-xl border border-border bg-bg px-4 py-3 text-sm text-muted flex items-start gap-2"
    role="note"
  >
    <svg class="h-4 w-4 mt-0.5 shrink-0 text-muted" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M18 10A8 8 0 11 2 10a8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" /></svg>
    <span>{{ $t('home.disclaimer') }}</span>
  </div>
  <p v-else-if="variant === 'dark'" class="text-xs text-t3" role="note">{{ $t('home.disclaimer') }}</p>
  <p v-else class="text-xs text-muted" role="note">{{ $t('home.disclaimer') }}</p>
</template>

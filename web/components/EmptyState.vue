<script setup lang="ts">
withDefaults(defineProps<{ title?: string; text?: string; compact?: boolean }>(), { compact: false })
</script>

<template>
  <div class="flex flex-col items-center justify-center text-center rounded-2xl border border-dashed border-ink/20 bg-glass" :class="compact ? 'p-6' : 'p-10'">
    <div class="h-12 w-12 rounded-full border border-accent/40 bg-accent/10 flex items-center justify-center text-accent-text mb-3" aria-hidden="true">
      <slot name="icon">
        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 7a2 2 0 012-2h3l2 2h7a2 2 0 012 2v8a2 2 0 01-2 2H6a2 2 0 01-2-2V7z" /></svg>
      </slot>
    </div>
    <p class="font-display font-bold text-ink">{{ title ?? $t('components.empty.default') }}</p>
    <p v-if="text" class="text-sm text-muted mt-1 max-w-sm">{{ text }}</p>
    <div v-if="$slots.default" class="mt-4"><slot /></div>
  </div>
</template>

<script setup lang="ts">
defineProps<{ title: string; sub?: string; eyebrow?: string }>()
</script>

<template>
  <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between mb-6">
    <div class="min-w-0">
      <p v-if="eyebrow" class="text-xs font-medium text-blue uppercase tracking-wide mb-1">{{ eyebrow }}</p>
      <h1 class="text-2xl sm:text-3xl font-semibold text-ink">{{ title }}</h1>
      <p v-if="sub" class="text-muted mt-1 max-w-2xl">{{ sub }}</p>
    </div>
    <div v-if="$slots.actions" class="flex flex-wrap gap-2 shrink-0"><slot name="actions" /></div>
  </header>
</template>

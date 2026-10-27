<script setup lang="ts">
defineProps<{ title: string; sub?: string; eyebrow?: string }>()
</script>

<template>
  <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between mb-6">
    <div class="min-w-0">
      <SectionLabel v-if="eyebrow" class="mb-2">{{ eyebrow }}</SectionLabel>
      <h1 class="font-display text-2xl md:text-3xl font-extrabold text-ink">{{ title }}</h1>
      <p v-if="sub" class="text-desc text-muted mt-1.5 max-w-2xl">{{ sub }}</p>
    </div>
    <div v-if="$slots.actions" class="flex flex-wrap gap-2 shrink-0"><slot name="actions" /></div>
  </header>
</template>

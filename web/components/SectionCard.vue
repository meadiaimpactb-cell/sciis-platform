<script setup lang="ts">
defineProps<{ title?: string; sub?: string; flush?: boolean }>()
</script>

<template>
  <section class="card">
    <header v-if="title || $slots.actions" class="flex items-start justify-between gap-3 px-5 pt-5" :class="flush ? 'pb-3 border-b border-border' : ''">
      <div>
        <h2 v-if="title" class="text-base font-semibold text-ink">{{ title }}</h2>
        <p v-if="sub" class="text-sm text-muted mt-0.5">{{ sub }}</p>
      </div>
      <div v-if="$slots.actions" class="shrink-0 flex gap-2"><slot name="actions" /></div>
    </header>
    <div :class="flush ? '' : 'px-5 pb-5 pt-4'"><slot /></div>
  </section>
</template>

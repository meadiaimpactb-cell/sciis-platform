<script setup lang="ts">
defineProps<{ title?: string; sub?: string; flush?: boolean }>()
</script>

<template>
  <section class="card">
    <header v-if="title || $slots.actions" class="flex items-start justify-between gap-3 px-5 pt-5" :class="flush ? 'pb-3 border-b border-border' : ''">
      <div class="flex items-start gap-3 min-w-0">
        <span class="mt-0.5 h-5 w-1 rounded-full bg-teal shrink-0" aria-hidden="true" />
        <div class="min-w-0">
          <h2 v-if="title" class="card-title">{{ title }}</h2>
          <p v-if="sub" class="text-sm text-muted mt-0.5">{{ sub }}</p>
        </div>
      </div>
      <div v-if="$slots.actions" class="shrink-0 flex gap-2"><slot name="actions" /></div>
    </header>
    <div :class="flush ? '' : 'px-5 pb-5 pt-4'"><slot /></div>
  </section>
</template>

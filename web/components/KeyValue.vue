<script setup lang="ts">
defineProps<{ items: { label: string; value: string | number | null | undefined; num?: boolean; ltr?: boolean }[]; cols?: 1 | 2 | 3 }>()
</script>

<template>
  <dl class="grid gap-x-6 gap-y-3 text-sm" :class="cols === 1 ? 'grid-cols-1' : cols === 3 ? 'sm:grid-cols-3' : 'sm:grid-cols-2'">
    <div v-for="it in items" :key="it.label" class="min-w-0">
      <dt class="text-muted text-xs mb-0.5">{{ it.label }}</dt>
      <dd class="font-medium text-ink break-words" :class="[it.num && 'num', it.ltr && 'ltr text-start']">{{ it.value ?? '—' }}</dd>
    </div>
  </dl>
</template>

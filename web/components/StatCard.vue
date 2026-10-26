<script setup lang="ts">
withDefaults(defineProps<{ label: string; value: string | number; hint?: string; tone?: 'default' | 'blue' | 'teal' | 'warning' | 'success' | 'danger'; to?: string }>(), { tone: 'default' })
const tones = { default: 'text-ink', blue: 'text-navy', teal: 'text-teal-text', warning: 'text-warning', success: 'text-success', danger: 'text-danger' }
const bars = { default: 'bg-navy', blue: 'bg-navy', teal: 'bg-teal', warning: 'bg-warning', success: 'bg-success', danger: 'bg-danger' }
</script>

<template>
  <component :is="to ? 'NuxtLink' : 'div'" :to="to ? $localePath(to) : undefined" class="card card-pad relative overflow-hidden flex flex-col gap-1" :class="to && 'card-hover'">
    <span class="absolute inset-y-4 start-0 w-1 rounded-e-full" :class="bars[tone]" aria-hidden="true" />
    <span class="text-sm text-muted">{{ label }}</span>
    <span class="text-3xl font-semibold num" :class="tones[tone]">{{ value }}</span>
    <span v-if="hint" class="text-xs text-muted">{{ hint }}</span>
  </component>
</template>

<script setup lang="ts">
withDefaults(defineProps<{ label: string; value: string | number; hint?: string; tone?: 'default' | 'blue' | 'warning' | 'success' | 'danger'; to?: string }>(), { tone: 'default' })
const tones = { default: 'text-ink', blue: 'text-blue', warning: 'text-warning', success: 'text-success', danger: 'text-danger' }
</script>

<template>
  <component :is="to ? 'NuxtLink' : 'div'" :to="to ? $localePath(to) : undefined" class="card card-pad flex flex-col gap-1 transition" :class="to && 'hover:border-blue/50'">
    <span class="text-sm text-muted">{{ label }}</span>
    <span class="text-3xl font-semibold num" :class="tones[tone]">{{ value }}</span>
    <span v-if="hint" class="text-xs text-muted">{{ hint }}</span>
  </component>
</template>

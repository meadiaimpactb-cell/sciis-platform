<script setup lang="ts">
/** Frosted card (design v3 `.site-glass`): hero side panels, highlights and the marketing templates. */
withDefaults(defineProps<{ as?: string; pad?: 'none' | 'md' | 'lg' }>(), { as: 'div', pad: 'md' })
const pads = { none: '', md: 'p-6', lg: 'p-6 md:p-10' }
</script>

<template>
  <component :is="as" class="site-glass" :class="pads[pad]"><slot /></component>
</template>

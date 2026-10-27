<script setup lang="ts">
/** Section label (design v3): accent text after a small dot; `pill` frames it, as the hero eyebrows do. */
withDefaults(defineProps<{ pill?: boolean; as?: string }>(), { pill: false, as: 'p' })
</script>

<template>
  <component :is="as" class="eyebrow" :class="pill && 'eyebrow-pill'"><slot /></component>
</template>

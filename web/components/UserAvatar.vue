<script setup lang="ts">
/** Profile picture with an initials fallback on the brand gradient. Fixed box size → no layout shift. */
const props = withDefaults(defineProps<{ name?: string | null; src?: string | null; size?: 'sm' | 'md' | 'lg' | 'xl' }>(), { name: '', src: null, size: 'md' })
const sizes = { sm: 'h-8 w-8 text-xs', md: 'h-10 w-10 text-sm', lg: 'h-14 w-14 text-lg', xl: 'h-24 w-24 text-3xl' }
const px = { sm: 32, md: 40, lg: 56, xl: 96 }
const initials = computed(() => {
  const parts = (props.name ?? '').replace(/\(.*?\)/g, '').trim().split(/\s+/).filter(Boolean)
  return (parts.length > 1 ? parts[0]![0]! + parts[1]![0]! : (parts[0] ?? '?').slice(0, 1)).toUpperCase()
})
const failed = ref(false)
watch(() => props.src, () => (failed.value = false))
</script>

<template>
  <span class="relative inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-gradient-brand font-semibold text-white ring-2 ring-surface" :class="sizes[size]">
    <img v-if="src && !failed" :src="src" :alt="name ?? ''" :width="px[size]" :height="px[size]" class="h-full w-full object-cover" loading="lazy" decoding="async" @error="failed = true">
    <span v-else aria-hidden="true">{{ initials }}</span>
  </span>
</template>

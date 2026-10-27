<script setup lang="ts">
/**
 * Page hero of the inner public pages (design v3, template A): the glow surface with stars and orbit rings, a pill
 * label, the display title and its lead, and an optional actions row.
 * Slots: default = actions row · `bottom` = small print under the content.
 */
defineProps<{ title: string; lead?: string; kicker?: string }>()
</script>

<template>
  <section class="surface-hero">
    <OrbitBackdrop />
    <div class="container-x relative flex flex-col items-center text-center py-20 md:py-24">
      <SectionLabel v-if="kicker" pill>{{ kicker }}</SectionLabel>
      <h1 class="display text-ink text-balance max-w-4xl" :class="kicker ? 'mt-6' : ''">{{ title }}</h1>
      <p v-if="lead" class="lead mx-auto mt-6 max-w-3xl text-t2">{{ lead }}</p>
      <div v-if="$slots.default" class="mt-10 flex flex-wrap items-center justify-center gap-3"><slot /></div>
      <div v-if="$slots.bottom" class="w-full pt-12"><slot name="bottom" /></div>
    </div>
  </section>
</template>

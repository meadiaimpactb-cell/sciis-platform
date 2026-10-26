<script setup lang="ts">
/**
 * Dark, centred page hero (falak.sa pattern) for the inner public pages:
 *   kicker 30 px muted · title 50 px regular (30 on phones) · lead 20 px muted · optional actions slot.
 * Slots: default = actions row · `bottom` = small print under the content.
 */
defineProps<{ title: string; lead?: string; kicker?: string }>()
</script>

<template>
  <section class="surface-dark relative overflow-hidden">
    <NightSky />
    <div class="container-x relative text-center py-20 md:py-[6.25rem]">
      <div class="self-center">
        <p v-if="kicker" class="text-xl md:text-3xl text-white/60">{{ kicker }}</p>
        <h1 class="display text-white text-balance" :class="kicker ? 'mt-3' : ''">{{ title }}</h1>
        <p v-if="lead" class="lead mx-auto mt-6 max-w-3xl text-white/70">{{ lead }}</p>
        <div v-if="$slots.default" class="mt-10 flex flex-wrap items-center justify-center gap-3"><slot /></div>
      </div>
      <div v-if="$slots.bottom" class="pt-12"><slot name="bottom" /></div>
    </div>
  </section>
</template>

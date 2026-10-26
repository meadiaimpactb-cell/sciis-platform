<script setup lang="ts">
/**
 * Dark, centred page hero (falak.sa pattern) for every public page:
 *   kicker 30 px muted · title 50 px regular (30 on phones) · lead 20 px muted · optional actions slot.
 * `tall` fills the first screen (home); the public header overlays it there.
 * Slots: default = actions row · `bottom` = small print under the content · `backdrop` = full-bleed media behind the
 * text (e.g. the CMS hero images on the home page), rendered above the night sky and below the text.
 */
withDefaults(defineProps<{ title: string; lead?: string; kicker?: string; tall?: boolean }>(), { tall: false })
</script>

<template>
  <section class="surface-dark relative overflow-hidden">
    <NightSky />
    <slot name="backdrop" />
    <div class="container-x relative text-center" :class="tall ? 'min-h-[100svh] grid grid-rows-[1fr_auto] pt-[7.75rem] pb-12' : 'py-20 md:py-[6.25rem]'">
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

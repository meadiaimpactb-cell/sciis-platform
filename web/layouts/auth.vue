<script setup lang="ts">
const localePath = useLocalePath()
</script>

<template>
  <div class="min-h-screen grid lg:grid-cols-[1.05fr_1fr] bg-bg">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:start-2 focus:z-50 bg-surface text-ink px-3 py-2 rounded-lg shadow">{{ $t('app.skipToContent') }}</a>
    <aside class="hidden lg:flex flex-col justify-between surface-dark p-12 relative overflow-hidden">
      <div class="absolute inset-0 brand-grid" aria-hidden="true" />
      <div class="absolute -end-24 -top-24 h-[34rem] w-[34rem] rounded-full bg-teal/20 blur-3xl" aria-hidden="true" />
      <img src="/brand/mark-white.svg" width="100" height="136" alt="" aria-hidden="true" class="w-auto absolute -start-16 bottom-10 h-[30rem] opacity-[0.07] pointer-events-none select-none animate-float-slow">
      <NuxtLink :to="localePath('/')" class="relative kbd-focus rounded-lg w-fit"><BrandLogo dark size="lg" /></NuxtLink>
      <div class="relative max-w-md">
        <p class="eyebrow eyebrow-dark">{{ $t('home.heroEyebrow') }}</p>
        <p class="mt-4 text-3xl xl:text-4xl font-semibold leading-snug tracking-tight text-balance text-white">{{ $t('app.tagline') }}</p>
        <ul class="mt-8 space-y-4 text-white/85">
          <li v-for="(p, i) in ($tm('home.investorPath.points') as string[])" :key="i" class="flex items-start gap-3">
            <span class="mt-0.5 h-6 w-6 rounded-full bg-teal/20 text-teal-400 flex items-center justify-center shrink-0" aria-hidden="true"><svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd" /></svg></span>
            <span class="leading-7">{{ $rt(p) }}</span>
          </li>
        </ul>
      </div>
      <Disclaimer variant="dark" class="relative" />
    </aside>
    <div class="flex flex-col min-w-0">
      <DemoBanner />
      <div class="flex items-center justify-between p-4 lg:p-6">
        <NuxtLink :to="localePath('/')" class="lg:hidden kbd-focus rounded-lg"><BrandLogo size="sm" /></NuxtLink>
        <span class="hidden lg:block" />
        <LangSwitch />
      </div>
      <main id="main" tabindex="-1" class="flex-1 flex items-start justify-center px-4 pb-10 lg:px-10 focus:outline-none">
        <div class="w-full max-w-xl min-w-0 animate-fade-up"><slot /></div>
      </main>
    </div>
  </div>
</template>

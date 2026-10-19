<script setup lang="ts">
const localePath = useLocalePath()
const config = useRuntimeConfig()
</script>

<template>
  <div class="min-h-screen grid lg:grid-cols-[1.1fr_1fr] bg-bg">
    <aside class="hidden lg:flex flex-col justify-between bg-ink text-white p-10 relative overflow-hidden">
      <div class="absolute inset-0 opacity-30" style="background: radial-gradient(circle at 15% 20%, #1F5BFF 0, transparent 40%), radial-gradient(circle at 85% 80%, #0F766E 0, transparent 40%)" aria-hidden="true" />
      <NuxtLink :to="localePath('/')" class="relative flex items-center gap-2.5 kbd-focus rounded-lg w-fit">
        <span class="h-10 w-10 rounded-xl bg-blue flex items-center justify-center font-bold">S</span>
        <span class="font-bold text-xl">{{ config.public.platformShortName }}</span>
      </NuxtLink>
      <div class="relative max-w-md">
        <h2 class="text-3xl font-semibold leading-snug text-white">{{ $t('app.tagline') }}</h2>
        <ul class="mt-6 space-y-3 text-white/80">
          <li v-for="(p, i) in ($tm('home.investorPath.points') as string[])" :key="i" class="flex items-start gap-3"><span class="mt-2 h-1.5 w-1.5 rounded-full bg-blue shrink-0" />{{ $rt(p) }}</li>
        </ul>
      </div>
      <Disclaimer variant="dark" class="relative" />
    </aside>
    <div class="flex flex-col">
      <DemoBanner />
      <div class="flex items-center justify-between p-4 lg:p-6">
        <NuxtLink :to="localePath('/')" class="lg:hidden flex items-center gap-2 font-bold text-ink kbd-focus rounded-lg"><span class="h-8 w-8 rounded-lg bg-blue text-white flex items-center justify-center text-sm">S</span>{{ config.public.platformShortName }}</NuxtLink>
        <span class="hidden lg:block" />
        <LangSwitch />
      </div>
      <main id="main" class="flex-1 flex items-start justify-center px-4 pb-10 lg:px-10">
        <div class="w-full max-w-xl"><slot /></div>
      </main>
    </div>
  </div>
</template>

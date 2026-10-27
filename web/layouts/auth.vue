<script setup lang="ts">
const localePath = useLocalePath()
</script>

<!--
  Auth layout (design v3, template B): the brand side is a hero panel (glow + orbits, the logo, the section label, the
  tagline and the membership points) that stays in view while the form side scrolls; the form side sits on the page
  colour with a top bar (logo on phones, language, theme). Below 1024 px only the form side is shown.
-->
<template>
  <div class="min-h-screen grid lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)] bg-bg">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:start-2 focus:z-50 bg-surface text-ink px-3 py-2 rounded-lg shadow">{{ $t('app.skipToContent') }}</a>
    <aside class="surface-hero hidden lg:flex lg:sticky lg:top-0 lg:h-screen flex-col justify-between gap-10 p-12 xl:p-14 border-e border-border">
      <OrbitBackdrop />
      <NuxtLink :to="localePath('/')" class="relative kbd-focus rounded-lg w-fit"><BrandLogo size="lg" /></NuxtLink>
      <div class="relative max-w-[30rem]">
        <SectionLabel pill>{{ $t('home.heroEyebrow') }}</SectionLabel>
        <p class="mt-6 font-display font-extrabold text-3xl leading-[1.5] xl:text-4xl xl:leading-[1.45] text-ink text-balance">{{ $t('app.tagline') }}</p>
        <ul class="site-checks mt-10">
          <li v-for="(p, i) in ($tm('home.investorPath.points') as string[])" :key="i">
            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke="var(--accent-hi)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
            <span>{{ $rt(p) }}</span>
          </li>
        </ul>
      </div>
      <Disclaimer variant="dark" class="relative" />
    </aside>
    <div class="flex flex-col min-w-0">
      <DemoBanner />
      <div class="flex items-center justify-between gap-3 px-4 py-4 sm:px-6 lg:px-10 lg:py-6">
        <NuxtLink :to="localePath('/')" class="lg:hidden kbd-focus rounded-lg"><BrandLogo size="sm" /></NuxtLink>
        <span class="hidden lg:block" />
        <div class="flex items-center gap-2">
          <LangSwitch pill />
          <SiteThemeToggle />
        </div>
      </div>
      <main id="main" tabindex="-1" class="flex-1 flex items-start lg:items-center justify-center px-4 pb-12 sm:px-6 lg:px-10 lg:pb-16 focus:outline-none">
        <div class="w-full max-w-xl min-w-0 animate-fade-up"><slot /></div>
      </main>
    </div>
  </div>
</template>

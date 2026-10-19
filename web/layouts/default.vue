<script setup lang="ts">
/** Public layout — dark ink header with "SCII" logo text, nav, language switch, CTA. */
const auth = useAuthStore()
const localePath = useLocalePath()
const route = useRoute()
const open = ref(false)
const nav = [
  { to: '/about', key: 'nav.about' },
  { to: '/impact-investing', key: 'nav.impactInvesting' },
  { to: '/for-investors', key: 'nav.forInvestors' },
  { to: '/for-entrepreneurs', key: 'nav.forEntrepreneurs' },
  { to: '/faq', key: 'nav.faq' },
  { to: '/contact', key: 'nav.contact' },
]
watch(() => route.path, () => (open.value = false))
const year = new Date().getFullYear()
const config = useRuntimeConfig()
</script>

<template>
  <div class="min-h-screen flex flex-col bg-bg">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:start-2 focus:z-50 bg-surface text-ink px-3 py-2 rounded-lg shadow">{{ $t('app.skipToContent') }}</a>
    <DemoBanner />
    <header class="bg-ink text-white sticky top-0 z-40 border-b border-white/10">
      <div class="container-x flex items-center justify-between h-16 gap-4">
        <NuxtLink :to="localePath('/')" class="flex items-center gap-2.5 shrink-0 kbd-focus rounded-lg" :aria-label="$t('app.name')">
          <span class="h-9 w-9 rounded-xl bg-blue flex items-center justify-center font-bold text-sm tracking-tight">S</span>
          <span class="flex flex-col leading-none">
            <span class="font-bold text-lg tracking-tight">{{ config.public.platformShortName }}</span>
            <span class="text-[10px] text-white/60 hidden sm:block">{{ $t('app.name') }}</span>
          </span>
        </NuxtLink>
        <nav class="hidden lg:flex items-center gap-1" :aria-label="$t('nav.mainNavigation')">
          <NuxtLink v-for="n in nav" :key="n.to" :to="localePath(n.to)" class="px-3 h-9 inline-flex items-center rounded-lg text-sm text-white/80 hover:text-white hover:bg-white/10 transition kbd-focus" active-class="text-white bg-white/10">{{ $t(n.key) }}</NuxtLink>
        </nav>
        <div class="flex items-center gap-2">
          <LangSwitch dark class="hidden sm:inline-flex" />
          <template v-if="auth.isAuthenticated">
            <AppButton :to="auth.homePath" variant="secondary" size="sm" class="hidden sm:inline-flex">{{ $t('nav.portal') }}</AppButton>
          </template>
          <template v-else>
            <NuxtLink :to="localePath('/auth/login')" class="hidden sm:inline-flex px-3 h-9 items-center rounded-lg text-sm text-white/80 hover:text-white hover:bg-white/10 kbd-focus">{{ $t('nav.login') }}</NuxtLink>
            <AppButton to="/auth/register" size="sm" class="hidden sm:inline-flex">{{ $t('nav.joinInvestor') }}</AppButton>
          </template>
          <button type="button" class="lg:hidden h-10 w-10 rounded-lg hover:bg-white/10 flex items-center justify-center kbd-focus" :aria-expanded="open" aria-controls="mobile-nav" :aria-label="open ? $t('common.closeMenu') : $t('common.openMenu')" @click="open = !open">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path v-if="!open" d="M4 7h16M4 12h16M4 17h16" /><path v-else d="M6 6l12 12M18 6L6 18" /></svg>
          </button>
        </div>
      </div>
      <div v-show="open" id="mobile-nav" class="lg:hidden border-t border-white/10 bg-navy">
        <nav class="container-x py-3 flex flex-col gap-1" :aria-label="$t('nav.mainNavigation')">
          <NuxtLink v-for="n in nav" :key="n.to" :to="localePath(n.to)" class="px-3 py-2.5 rounded-lg text-white/85 hover:bg-white/10" active-class="bg-white/10 text-white">{{ $t(n.key) }}</NuxtLink>
          <div class="flex items-center gap-2 pt-3 mt-2 border-t border-white/10">
            <LangSwitch dark />
            <AppButton v-if="auth.isAuthenticated" :to="auth.homePath" variant="secondary" size="sm">{{ $t('nav.portal') }}</AppButton>
            <template v-else>
              <AppButton to="/auth/login" variant="secondary" size="sm">{{ $t('nav.login') }}</AppButton>
              <AppButton to="/auth/register" size="sm">{{ $t('nav.joinInvestor') }}</AppButton>
            </template>
          </div>
        </nav>
      </div>
    </header>

    <main id="main" class="flex-1">
      <slot />
    </main>

    <footer class="bg-ink text-white/80 mt-16">
      <div class="container-x py-12 grid gap-10 md:grid-cols-4">
        <div class="md:col-span-2">
          <div class="flex items-center gap-2.5 text-white">
            <span class="h-9 w-9 rounded-xl bg-blue flex items-center justify-center font-bold text-sm">S</span>
            <span class="font-bold text-lg">{{ config.public.platformShortName }}</span>
          </div>
          <p class="mt-4 text-sm leading-7 max-w-md">{{ $t('footer.about') }}</p>
          <Disclaimer variant="dark" class="mt-4" />
        </div>
        <div>
          <h3 class="text-white font-semibold mb-3 text-sm">{{ $t('footer.quickLinks') }}</h3>
          <ul class="space-y-2 text-sm">
            <li v-for="n in nav" :key="n.to"><NuxtLink :to="localePath(n.to)" class="hover:text-white">{{ $t(n.key) }}</NuxtLink></li>
            <li><NuxtLink :to="localePath('/prototype')" class="hover:text-white">{{ $t('nav.prototype') }}</NuxtLink></li>
          </ul>
        </div>
        <div>
          <h3 class="text-white font-semibold mb-3 text-sm">{{ $t('footer.legal') }}</h3>
          <ul class="space-y-2 text-sm">
            <li><NuxtLink :to="localePath('/legal/privacy')" class="hover:text-white">{{ $t('footer.privacy') }}</NuxtLink></li>
            <li><NuxtLink :to="localePath('/legal/terms')" class="hover:text-white">{{ $t('footer.terms') }}</NuxtLink></li>
            <li><NuxtLink :to="localePath('/legal/disclaimer')" class="hover:text-white">{{ $t('footer.disclaimer') }}</NuxtLink></li>
          </ul>
          <h3 class="text-white font-semibold mt-6 mb-3 text-sm">{{ $t('footer.contact') }}</h3>
          <ul class="space-y-1.5 text-sm">
            <li><a href="mailto:info@sciis.sa" class="hover:text-white ltr inline-block">info@sciis.sa</a></li>
            <li><a href="tel:+966110000000" class="hover:text-white ltr inline-block num">+966 11 000 0000</a></li>
          </ul>
        </div>
      </div>
      <div class="border-t border-white/10">
        <div class="container-x py-4 text-xs text-white/50 flex flex-col sm:flex-row items-center justify-between gap-2">
          <span>{{ $t('footer.rights', { year }) }}</span>
          <span>{{ $t('footer.disclaimerLine') }}</span>
        </div>
      </div>
    </footer>
  </div>
</template>

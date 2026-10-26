<script setup lang="ts">
/**
 * Public header (design v3): sticky translucent bar — navigation on the start side, the logo in the middle, language,
 * theme, sign in and "join the Council" on the end side. Below 1024 px the navigation moves into a menu panel.
 */
const auth = useAuthStore()
const localePath = useLocalePath()
const route = useRoute()
const join = useJoinChooser()
const open = ref(false)

const nav = [
  { to: '/about', key: 'nav.about', match: /\/(about|impact-investing|faq)(\/|$)/ },
  { to: '/for-investors', key: 'nav.forInvestors' },
  { to: '/for-entrepreneurs', key: 'nav.forEntrepreneurs' },
  { to: '/tools', key: 'nav.tools', match: /\/(tools|resources)(\/|$)/ },
  { to: '/news', key: 'nav.media', match: /\/(news|events|stories)(\/|$)/ },
]
const isActive = (item: (typeof nav)[number]) => (item.match ? item.match.test(route.path) : route.path === localePath(item.to))
watch(() => route.fullPath, () => (open.value = false))
</script>

<template>
  <header class="site-header site-surface" @keydown.esc="open = false">
    <DemoBanner />
    <div class="site-wrap site-header__bar">
      <div class="site-header__start">
        <button
          type="button"
          class="site-icon-btn site-header__menu kbd-focus"
          :aria-expanded="open"
          aria-controls="site-menu"
          :aria-label="open ? $t('common.closeMenu') : $t('common.openMenu')"
          @click="open = !open"
        >
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path v-if="!open" d="M4 7h16M4 12h16M4 17h16" /><path v-else d="M6 6l12 12M18 6L6 18" /></svg>
        </button>
        <nav class="site-header__nav" :aria-label="$t('nav.mainNavigation')">
          <NuxtLink v-for="item in nav" :key="item.to" :to="localePath(item.to)" class="site-header__link kbd-focus" :aria-current="isActive(item) ? 'page' : undefined">{{ $t(item.key) }}</NuxtLink>
        </nav>
      </div>

      <NuxtLink :to="localePath('/')" class="site-header__logo kbd-focus" :aria-label="$t('app.name')">
        <img class="on-dark" src="/brand/logo-white.webp" :alt="$t('app.name')" width="141" height="48" fetchpriority="high" decoding="async">
        <img class="on-light" src="/brand/logo.webp" :alt="$t('app.name')" width="141" height="48" loading="lazy" decoding="async">
      </NuxtLink>

      <div class="site-header__end">
        <LangSwitch pill class="site-header__wide" />
        <SiteThemeToggle />
        <!-- Session-dependent: public pages are cached for everyone, so this renders on the client; the fallback is the
             signed-out pair (same size) so nothing shifts. -->
        <ClientOnly>
          <NuxtLink v-if="auth.isAuthenticated" :to="localePath(auth.homePath)" class="site-btn site-btn--primary kbd-focus">{{ $t('nav.portal') }}</NuxtLink>
          <template v-else>
            <NuxtLink :to="localePath('/auth/login')" class="site-btn site-btn--outline site-header__wide kbd-focus">{{ $t('nav.login') }}</NuxtLink>
            <button type="button" class="site-btn site-btn--primary site-header__join kbd-focus" aria-haspopup="dialog" @click="join.open($event)">{{ $t('nav.joinCouncil') }}</button>
          </template>
          <template #fallback>
            <NuxtLink :to="localePath('/auth/login')" class="site-btn site-btn--outline site-header__wide">{{ $t('nav.login') }}</NuxtLink>
            <button type="button" class="site-btn site-btn--primary site-header__join" aria-haspopup="dialog" @click="join.open($event)">{{ $t('nav.joinCouncil') }}</button>
          </template>
        </ClientOnly>
      </div>
    </div>

    <!-- phone / tablet menu -->
    <div v-show="open" id="site-menu" class="site-header__panel">
      <nav class="site-wrap" :aria-label="$t('nav.mainNavigation')">
        <ul class="site-header__panel-list">
          <li v-for="item in nav" :key="item.to">
            <NuxtLink :to="localePath(item.to)" class="site-header__panel-link" :aria-current="isActive(item) ? 'page' : undefined">{{ $t(item.key) }}</NuxtLink>
          </li>
        </ul>
        <div class="site-header__panel-actions">
          <LangSwitch pill />
          <ClientOnly>
            <NuxtLink v-if="auth.isAuthenticated" :to="localePath(auth.homePath)" class="site-btn site-btn--primary">{{ $t('nav.portal') }}</NuxtLink>
            <template v-else>
              <NuxtLink :to="localePath('/auth/login')" class="site-btn site-btn--outline">{{ $t('nav.login') }}</NuxtLink>
              <button type="button" class="site-btn site-btn--primary" aria-haspopup="dialog" @click="join.open($event)">{{ $t('nav.joinCouncil') }}</button>
            </template>
          </ClientOnly>
        </div>
      </nav>
    </div>
  </header>
</template>

<style scoped>
.site-header {
  position: sticky;
  top: 0;
  z-index: 40;
  /* near-opaque so it reads the same over the dark home hero and the light inner pages */
  background: rgba(var(--bg0-rgb), 0.92);
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  border-bottom: 1px solid rgba(var(--fg-rgb), 0.08);
}
.site-header__bar {
  min-height: 80px;
  padding-block: 14px;
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
  align-items: center;
  gap: 24px;
}
.site-header__start { display: flex; align-items: center; gap: 12px; min-width: 0; }
.site-header__nav { display: flex; align-items: center; flex-wrap: wrap; gap: 6px 20px; min-width: 0; }
.site-header__link {
  padding-block: 6px;
  font: 500 14px var(--fb);
  color: var(--t2);
  white-space: nowrap;
  transition: color 0.15s ease;
}
.site-header__link:hover,
.site-header__link[aria-current='page'] { color: var(--fg); }
.site-header__logo { display: flex; align-items: center; justify-content: center; border-radius: 6px; }
.site-header__logo img { height: 48px; width: auto; }
.site-header__end {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 8px;
  min-width: 0;
}
.site-header__menu { display: none; }

.site-header__panel {
  border-top: 1px solid rgba(var(--fg-rgb), 0.1);
  background: var(--bg0);
  max-height: calc(100svh - 80px);
  overflow-y: auto;
  padding-block: 12px 20px;
}
.site-header__panel-list { list-style: none; margin: 0; padding: 0; }
.site-header__panel-list li + li { border-top: 1px solid rgba(var(--fg-rgb), 0.08); }
.site-header__panel-link {
  display: block;
  padding: 14px 4px;
  font: 500 16px var(--fb);
  color: var(--t2);
}
.site-header__panel-link[aria-current='page'],
.site-header__panel-link:hover { color: var(--fg); }
.site-header__panel-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 12px;
  padding-top: 16px;
  border-top: 1px solid rgba(var(--fg-rgb), 0.1);
}

@media (max-width: 1023px) {
  .site-header__nav,
  .site-header__wide { display: none; }
  .site-header__menu { display: inline-flex; }
  .site-header__bar { min-height: 72px; gap: 12px; }
  .site-header__logo img { height: 40px; }
}
@media (min-width: 1024px) {
  .site-header__panel { display: none !important; }
}
@media (max-width: 480px) {
  .site-header__join { display: none; }
}
</style>

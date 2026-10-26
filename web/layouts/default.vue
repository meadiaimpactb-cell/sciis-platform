<script setup lang="ts">
/**
 * Public layout (falak.sa structure on the SCII identity):
 *  - header 124 px: centred logo, navigation split on both sides — the language button on the start side, the call
 *    to action (underlined, bold) on the end side; dropdowns for grouped pages. On the home page it overlays the dark
 *    hero (transparent, white text) and turns white once the page scrolls; everywhere else it is a white sticky bar.
 *  - footer: dark, link groups in three columns + a contact column, centred copyright.
 */
const auth = useAuthStore()
const localePath = useLocalePath()
const route = useRoute()
const open = ref(false)
const scrolled = ref(false)
const menu = ref<string | null>(null)
const navRef = ref<HTMLElement | null>(null)
const join = useJoinChooser()
/** The join chooser is mounted on its first opening (keeps its code out of the initial load). */
const joinMounted = ref(false)
watch(join.isOpen, (v) => { if (v) joinMounted.value = true }, { immediate: true })
const { data: resources } = usePublicResources()
const { data: stories } = usePublicStories()

type NavLink = { to: string; key: string; sub?: string }
type NavItem = { id: string; key: string; to?: string; items?: NavLink[]; match?: RegExp }

const aboutNav: NavLink[] = [
  { to: '/about', key: 'nav.aboutUs' },
  { to: '/impact-investing', key: 'nav.impactInvesting' },
  { to: '/faq', key: 'nav.faq' },
]
// Self-assessment tools, plus knowledge resources once some are published.
const toolsNav = computed<NavLink[]>(() => [
  { to: '/tools/investor-readiness', key: 'tools.investor.title', sub: 'tools.investor.short' },
  { to: '/tools/project-readiness', key: 'tools.project.title', sub: 'tools.project.short' },
  ...(resources.value.length ? [{ to: '/resources', key: 'nav.resources', sub: 'resources.navSub' }] : []),
])
const mediaNav = computed<NavLink[]>(() => [
  { to: '/news', key: 'nav.news' },
  { to: '/events', key: 'nav.events' },
  ...(stories.value.length ? [{ to: '/stories', key: 'nav.stories' }] : []),
])
const startNav = computed<NavItem[]>(() => [
  { id: 'about', key: 'nav.about', items: aboutNav, match: /\/(about|impact-investing|faq)(\/|$)/ },
  { id: 'investors', key: 'nav.forInvestors', to: '/for-investors' },
  { id: 'entrepreneurs', key: 'nav.forEntrepreneurs', to: '/for-entrepreneurs' },
  { id: 'contact', key: 'nav.contact', to: '/contact' },
])
const endNav = computed<NavItem[]>(() => [
  { id: 'tools', key: 'nav.tools', items: [...toolsNav.value, { to: '/tools', key: 'tools.allTools' }], match: /\/(tools|resources)(\/|$)/ },
  { id: 'media', key: 'nav.media', items: mediaNav.value, match: /\/(news|events|stories)(\/|$)/ },
])
const footerGroups = computed(() => [
  { key: 'footer.groups.about', links: aboutNav },
  { key: 'footer.groups.investors', links: [{ to: '/for-investors', key: 'nav.forInvestors' }, { to: '/tools/investor-readiness', key: 'tools.investor.title' }, { to: '/auth/register?type=investor_individual', key: 'nav.joinInvestor' }] },
  { key: 'footer.groups.entrepreneurs', links: [{ to: '/for-entrepreneurs', key: 'nav.forEntrepreneurs' }, { to: '/tools/project-readiness', key: 'tools.project.title' }, { to: '/auth/register?type=entrepreneur_entity', key: 'nav.submitProject' }] },
  { key: 'footer.groups.media', links: [...mediaNav.value, ...(resources.value.length ? [{ to: '/resources', key: 'nav.resources' }] : [])] },
  { key: 'footer.legal', links: [{ to: '/legal/privacy', key: 'footer.privacy' }, { to: '/legal/terms', key: 'footer.terms' }, { to: '/legal/disclaimer', key: 'footer.disclaimer' }] },
])

const isHome = computed(() => route.path === localePath('/'))
/** Transparent header over the home hero until the page scrolls (or the phone menu opens). */
const overlay = computed(() => isHome.value && !scrolled.value && !open.value)
const isActive = (item: NavItem) => (item.match ? item.match.test(route.path) : !!item.to && route.path === localePath(item.to))
const linkClass = computed(() => [
  'relative inline-flex h-10 items-center gap-1 whitespace-nowrap text-base font-medium transition kbd-focus',
  'after:absolute after:inset-x-0 after:bottom-0.5 after:h-0.5 after:origin-center after:scale-x-0 after:bg-teal after:transition',
  overlay.value ? 'text-white/90 hover:text-white' : 'text-ink hover:text-navy',
])
const ctaClass = computed(() => ['inline-flex items-center whitespace-nowrap border-b-2 border-current pb-1.5 text-base font-bold transition kbd-focus', overlay.value ? 'text-white hover:text-teal-400' : 'text-ink hover:text-teal-text'])
const plainClass = computed(() => ['inline-flex h-10 items-center whitespace-nowrap text-base font-medium transition kbd-focus', overlay.value ? 'text-white/90 hover:text-white' : 'text-ink hover:text-navy'])

const toggle = (id: string) => (menu.value = menu.value === id ? null : id)
/** Keyboard: tabbing out of an open dropdown closes it. */
const onMenuFocusOut = (e: FocusEvent) => {
  const wrap = e.currentTarget as HTMLElement
  if (!(e.relatedTarget instanceof Node && wrap.contains(e.relatedTarget))) menu.value = null
}
onClickOutside(navRef, () => (menu.value = null))
watch(() => route.fullPath, () => { open.value = false; menu.value = null })
const onScroll = () => (scrolled.value = window.scrollY > 8)
onMounted(() => { onScroll(); window.addEventListener('scroll', onScroll, { passive: true }) })
onBeforeUnmount(() => window.removeEventListener('scroll', onScroll))
</script>

<template>
  <div class="min-h-screen flex flex-col bg-bg">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:start-2 focus:z-50 bg-surface text-ink px-3 py-2 rounded-lg shadow">{{ $t('app.skipToContent') }}</a>
    <header
      ref="navRef"
      class="z-40 transition-colors duration-300"
      :class="[isHome ? 'fixed inset-x-0 top-0' : 'sticky top-0', overlay ? 'bg-transparent' : 'bg-white', !overlay && scrolled ? 'shadow-card' : '', !overlay && !scrolled ? 'border-b border-border' : '']"
      @keydown.esc="menu = null; open = false"
    >
      <DemoBanner />
      <div class="container-x grid h-24 grid-cols-[1fr_auto_1fr] items-center gap-4 lg:h-[7.75rem]">
        <!-- start side: phone menu button · language · first navigation group -->
        <div class="flex items-center gap-6 justify-self-start">
          <button
            type="button"
            class="xl:hidden inline-flex h-9 w-11 items-center justify-center rounded-md transition kbd-focus"
            :class="overlay ? 'bg-white/90 text-navy-950 hover:bg-white' : 'bg-navy-50 text-ink hover:bg-navy-100'"
            :aria-expanded="open" aria-controls="mobile-nav" :aria-label="open ? $t('common.closeMenu') : $t('common.openMenu')"
            @click="open = !open"
          >
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path v-if="!open" d="M4 7h16M4 12h16M4 17h16" /><path v-else d="M6 6l12 12M18 6L6 18" /></svg>
          </button>
          <LangSwitch outlined :dark="overlay" class="hidden xl:inline-flex" />
          <nav class="hidden xl:flex items-center gap-6" :aria-label="$t('nav.mainNavigation')">
            <template v-for="item in startNav" :key="item.id">
              <div v-if="item.items" class="relative" @focusout="onMenuFocusOut">
                <button type="button" :class="[linkClass, isActive(item) && 'after:scale-x-100']" :aria-expanded="menu === item.id" :aria-controls="`nav-menu-${item.id}`" @click="toggle(item.id)">
                  {{ $t(item.key) }}
                  <svg class="h-3.5 w-3.5 transition" :class="menu === item.id && 'rotate-180'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
                </button>
                <div v-show="menu === item.id" :id="`nav-menu-${item.id}`" class="absolute start-0 top-full z-50 mt-3 w-72 rounded-md border border-border bg-white p-2 shadow-pop">
                  <NuxtLink v-for="x in item.items" :key="x.to" :to="localePath(x.to)" class="block rounded px-3 py-2.5 text-ink hover:bg-navy-50 kbd-focus" active-class="bg-navy-50">
                    <span class="block text-sm font-medium">{{ $t(x.key) }}</span>
                    <span v-if="x.sub" class="mt-0.5 block text-xs text-muted">{{ $t(x.sub) }}</span>
                  </NuxtLink>
                </div>
              </div>
              <NuxtLink v-else :to="localePath(item.to!)" :class="[linkClass, isActive(item) && 'after:scale-x-100']">{{ $t(item.key) }}</NuxtLink>
            </template>
          </nav>
        </div>

        <!-- centred logo (white over the home hero, full colour elsewhere; both reserve the same box) -->
        <NuxtLink :to="localePath('/')" class="relative block justify-self-center rounded kbd-focus" :aria-label="$t('app.name')">
          <BrandLogo size="md" eager class="transition-opacity duration-300" :class="overlay ? 'opacity-0' : 'opacity-100'" />
          <BrandLogo v-if="isHome" dark size="md" eager class="absolute inset-0 transition-opacity duration-300" :class="overlay ? 'opacity-100' : 'opacity-0'" />
        </NuxtLink>

        <!-- end side: second navigation group · sign in · call to action -->
        <div class="flex items-center gap-6 justify-self-end">
          <nav class="hidden xl:flex items-center gap-6" :aria-label="$t('nav.mainNavigation')">
            <div v-for="item in endNav" :key="item.id" class="relative" @focusout="onMenuFocusOut">
              <button type="button" :class="[linkClass, isActive(item) && 'after:scale-x-100']" :aria-expanded="menu === item.id" :aria-controls="`nav-menu-${item.id}`" @click="toggle(item.id)">
                {{ $t(item.key) }}
                <svg class="h-3.5 w-3.5 transition" :class="menu === item.id && 'rotate-180'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
              </button>
              <div v-show="menu === item.id" :id="`nav-menu-${item.id}`" class="absolute end-0 top-full z-50 mt-3 w-72 rounded-md border border-border bg-white p-2 shadow-pop">
                <NuxtLink v-for="x in item.items" :key="x.to" :to="localePath(x.to)" class="block rounded px-3 py-2.5 text-ink hover:bg-navy-50 kbd-focus" active-class="bg-navy-50">
                  <span class="block text-sm font-medium">{{ $t(x.key) }}</span>
                  <span v-if="x.sub" class="mt-0.5 block text-xs text-muted">{{ $t(x.sub) }}</span>
                </NuxtLink>
              </div>
            </div>
          </nav>
          <!-- Session-dependent: public pages are cached once for everyone, so this renders on the client only.
               The fallback is the logged-out pair (same size) to avoid a hydration mismatch or layout shift. -->
          <div class="hidden sm:flex items-center gap-6">
            <ClientOnly>
              <NuxtLink v-if="auth.isAuthenticated" :to="localePath(auth.homePath)" :class="ctaClass">{{ $t('nav.portal') }}</NuxtLink>
              <template v-else>
                <NuxtLink :to="localePath('/auth/login')" :class="plainClass">{{ $t('nav.login') }}</NuxtLink>
                <button type="button" :class="ctaClass" aria-haspopup="dialog" @click="join.open($event)">{{ $t('nav.joinCouncil') }}</button>
              </template>
              <template #fallback>
                <NuxtLink :to="localePath('/auth/login')" :class="plainClass">{{ $t('nav.login') }}</NuxtLink>
                <button type="button" :class="ctaClass" aria-haspopup="dialog" @click="join.open($event)">{{ $t('nav.joinCouncil') }}</button>
              </template>
            </ClientOnly>
          </div>
        </div>
      </div>

      <!-- phone / tablet menu -->
      <div v-show="open" id="mobile-nav" class="xl:hidden max-h-[calc(100svh-6rem)] overflow-y-auto border-t border-border bg-white shadow-pop">
        <nav class="container-x py-4" :aria-label="$t('nav.mainNavigation')">
          <ul class="divide-y divide-border">
            <li v-for="item in [...startNav, ...endNav]" :key="item.id" class="py-2">
              <template v-if="item.items">
                <p class="px-1 pt-1 pb-1.5 text-sm font-bold text-muted">{{ $t(item.key) }}</p>
                <NuxtLink v-for="x in item.items" :key="x.to" :to="localePath(x.to)" class="block rounded px-1 py-2 text-base text-ink hover:text-navy" active-class="text-navy font-bold">{{ $t(x.key) }}</NuxtLink>
              </template>
              <NuxtLink v-else :to="localePath(item.to!)" class="block rounded px-1 py-2 text-base text-ink hover:text-navy" active-class="text-navy font-bold">{{ $t(item.key) }}</NuxtLink>
            </li>
          </ul>
          <div class="mt-3 flex flex-wrap items-center gap-3 border-t border-border pt-4">
            <LangSwitch outlined />
            <ClientOnly>
              <AppButton v-if="auth.isAuthenticated" :to="auth.homePath" size="sm">{{ $t('nav.portal') }}</AppButton>
              <template v-else>
                <AppButton to="/auth/login" variant="secondary" size="sm">{{ $t('nav.login') }}</AppButton>
                <AppButton size="sm" aria-haspopup="dialog" @click="join.open($event)">{{ $t('nav.joinCouncil') }}</AppButton>
              </template>
              <template #fallback>
                <AppButton to="/auth/login" variant="secondary" size="sm">{{ $t('nav.login') }}</AppButton>
                <AppButton size="sm" aria-haspopup="dialog" @click="join.open($event)">{{ $t('nav.joinCouncil') }}</AppButton>
              </template>
            </ClientOnly>
          </div>
        </nav>
      </div>
    </header>

    <main id="main" tabindex="-1" class="flex-1 focus:outline-none">
      <slot />
    </main>
    <!-- Not needed for the first paint: the cookie notice activates when the browser is idle, the join chooser loads
         on its first opening, and the footer hydrates when it scrolls into view (its HTML is server-rendered). -->
    <LazyCookieConsent hydrate-on-idle />
    <LazyJoinChooserModal v-if="joinMounted" />
    <LazySiteFooter hydrate-on-visible :groups="footerGroups" :class="isHome ? '' : 'mt-16'" />
  </div>
</template>

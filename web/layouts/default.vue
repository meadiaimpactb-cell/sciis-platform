<script setup lang="ts">
/**
 * Public layout (design v3): sticky header (SiteHeader), page content, footer with five numbered link groups
 * (SiteFooter). Header and footer follow the site theme (dark by default, light on request — theme-site.css).
 */
const localePath = useLocalePath()
const route = useRoute()
const join = useJoinChooser()
/** The join chooser is mounted on its first opening (keeps its code out of the initial load). */
const joinMounted = ref(false)
watch(join.isOpen, (v) => { if (v) joinMounted.value = true }, { immediate: true })
const { data: resources } = usePublicResources()
const { data: stories } = usePublicStories()

const footerGroups = computed(() => [
  { key: 'footer.groups.about', links: [{ to: '/about', key: 'nav.aboutUs' }, { to: '/impact-investing', key: 'nav.impactInvesting' }, { to: '/faq', key: 'nav.faq' }] },
  { key: 'footer.groups.investors', links: [{ to: '/for-investors', key: 'nav.forInvestors' }, { to: '/tools/investor-readiness', key: 'tools.investor.title' }, { to: '/auth/register?type=investor_individual', key: 'nav.joinInvestor' }] },
  { key: 'footer.groups.entrepreneurs', links: [{ to: '/for-entrepreneurs', key: 'nav.forEntrepreneurs' }, { to: '/tools/project-readiness', key: 'tools.project.title' }, { to: '/auth/register?type=entrepreneur_entity', key: 'nav.submitProject' }] },
  {
    key: 'footer.groups.media',
    links: [
      { to: '/news', key: 'nav.news' },
      { to: '/events', key: 'nav.events' },
      // Shown once published content exists.
      ...(stories.value.length ? [{ to: '/stories', key: 'nav.stories' }] : []),
      ...(resources.value.length ? [{ to: '/resources', key: 'nav.resources' }] : []),
    ],
  },
  { key: 'footer.legal', links: [{ to: '/legal/privacy', key: 'footer.privacy' }, { to: '/legal/terms', key: 'footer.terms' }, { to: '/legal/disclaimer', key: 'footer.disclaimer' }] },
])
const isHome = computed(() => route.path === localePath('/'))
</script>

<template>
  <div class="min-h-screen flex flex-col bg-bg">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:start-2 focus:z-50 bg-surface text-ink px-3 py-2 rounded-lg shadow">{{ $t('app.skipToContent') }}</a>
    <SiteHeader />
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

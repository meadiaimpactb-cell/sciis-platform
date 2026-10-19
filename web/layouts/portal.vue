<script setup lang="ts">
/** Portal layout — sidebar per role (investor / entrepreneur / admin), collapsible on mobile. */
const auth = useAuthStore()
const localePath = useLocalePath()
const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const config = useRuntimeConfig()
const open = ref(false)
watch(() => route.path, () => (open.value = false))

interface Item { to: string; label: string; icon: string; roles?: string[] }
const icons: Record<string, string> = {
  dashboard: 'M4 5h7v7H4zM13 5h7v4h-7zM13 11h7v8h-7zM4 14h7v5H4z',
  opportunities: 'M3 7h18M3 12h18M3 17h12',
  interests: 'M12 21s-7-4.5-7-10a4 4 0 017-2.6A4 4 0 0119 11c0 5.5-7 10-7 10z',
  nominations: 'M12 3l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.3 6.8 19.1l1-5.8-4.3-4.1 5.9-.9z',
  info: 'M12 8h.01M11 12h1v4h1M12 21a9 9 0 110-18 9 9 0 010 18z',
  meetings: 'M4 7h16v13H4zM4 11h16M8 4v3M16 4v3',
  saved: 'M6 4h12v17l-6-4-6 4V4z',
  profile: 'M12 12a4 4 0 100-8 4 4 0 000 8zM4 21a8 8 0 0116 0',
  projects: 'M4 7a2 2 0 012-2h3l2 2h7a2 2 0 012 2v8a2 2 0 01-2 2H6a2 2 0 01-2-2V7z',
  access: 'M5 11h14v10H5zM8 11V7a4 4 0 118 0v4',
  memberships: 'M16 11a4 4 0 10-8 0 4 4 0 008 0zM3 21a9 9 0 0118 0M19 8l2 2 3-3',
  users: 'M9 12a4 4 0 100-8 4 4 0 000 8zM2 21a7 7 0 0114 0M17 3a4 4 0 010 8M22 21a7 7 0 00-5-6.7',
  settings: 'M12 15a3 3 0 100-6 3 3 0 000 6zM19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.8 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1.1 1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z',
  criteria: 'M9 11l3 3L22 4M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11',
  reports: 'M4 20V10M10 20V4M16 20v-6M22 20H2',
  audit: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2M9 12h6M9 16h6',
  content: 'M4 4h16v16H4zM8 8h8M8 12h8M8 16h5',
  back: 'M10 19l-7-7 7-7M3 12h18',
}

const items = computed<Item[]>(() => {
  if (auth.isStaff) {
    return [
      { to: '/app/admin/dashboard', label: t('portal.nav.dashboard'), icon: 'dashboard' },
      { to: '/app/admin/opportunities', label: t('portal.nav.opportunities'), icon: 'opportunities' },
      { to: '/app/admin/memberships', label: t('portal.nav.memberships'), icon: 'memberships', roles: ['membership_officer'] },
      { to: '/app/admin/interests', label: t('portal.nav.interests'), icon: 'interests', roles: ['matching_officer'] },
      { to: '/app/admin/info-requests', label: t('portal.nav.infoRequests'), icon: 'info', roles: ['matching_officer'] },
      { to: '/app/admin/meetings', label: t('portal.nav.meetings'), icon: 'meetings', roles: ['matching_officer', 'impact_officer'] },
      { to: '/app/admin/users', label: t('portal.nav.users'), icon: 'users', roles: [] },
      { to: '/app/admin/settings', label: t('portal.nav.settings'), icon: 'settings', roles: [] },
      { to: '/app/admin/evaluation-criteria', label: t('portal.nav.criteria'), icon: 'criteria', roles: ['evaluator', 'reviewer'] },
      { to: '/app/admin/reports', label: t('portal.nav.reports'), icon: 'reports', roles: ['reports_viewer', 'impact_officer', 'membership_officer'] },
      { to: '/app/admin/audit-logs', label: t('portal.nav.audit'), icon: 'audit', roles: [] },
      { to: '/app/admin/content', label: t('portal.nav.content'), icon: 'content', roles: [] },
    ].filter((i) => !i.roles || auth.hasRole(...(i.roles as never[])))
  }
  if (auth.isEntrepreneur) {
    return [
      { to: '/app/entrepreneur/dashboard', label: t('portal.nav.dashboard'), icon: 'dashboard' },
      { to: '/app/entrepreneur/opportunities/new', label: t('portal.nav.newOpportunity'), icon: 'projects' },
      { to: '/app/entrepreneur/access-requests', label: t('portal.nav.accessRequests'), icon: 'access' },
      { to: '/app/entrepreneur/info-requests', label: t('portal.nav.infoRequests'), icon: 'info' },
      { to: '/app/entrepreneur/meetings', label: t('portal.nav.meetings'), icon: 'meetings' },
    ]
  }
  return [
    { to: '/app/investor/dashboard', label: t('portal.nav.dashboard'), icon: 'dashboard' },
    { to: '/app/opportunities', label: t('portal.nav.opportunities'), icon: 'opportunities' },
    { to: '/app/investor/nominations', label: t('portal.nav.nominations'), icon: 'nominations' },
    { to: '/app/investor/interests', label: t('portal.nav.interests'), icon: 'interests' },
    { to: '/app/investor/info-requests', label: t('portal.nav.infoRequests'), icon: 'info' },
    { to: '/app/investor/meetings', label: t('portal.nav.meetings'), icon: 'meetings' },
    { to: '/app/investor/saved', label: t('portal.nav.saved'), icon: 'saved' },
    { to: '/app/investor/profile', label: t('portal.nav.profile'), icon: 'profile' },
  ]
})
const portalTitle = computed(() => (auth.isStaff ? t('portal.admin') : auth.isEntrepreneur ? t('portal.entrepreneur') : t('portal.investor')))
const isActive = (to: string) => route.path === localePath(to) || route.path.startsWith(localePath(to) + '/')
const { label: enumLabel } = useEnumLabel()
const roleLabel = computed(() => (auth.isStaff ? auth.roles.map((r) => enumLabel('role', r)).join('، ') : enumLabel('userType', auth.me?.type)))

const logout = async () => {
  await auth.logout()
  router.push(localePath('/'))
}
</script>

<template>
  <div class="min-h-screen bg-bg lg:grid lg:grid-cols-[260px_minmax(0,1fr)]">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:start-2 focus:z-50 bg-surface text-ink px-3 py-2 rounded-lg shadow">{{ $t('app.skipToContent') }}</a>

    <!-- mobile top bar -->
    <header class="lg:hidden sticky top-0 z-40 bg-ink text-white border-b border-white/10">
      <DemoBanner />
      <div class="flex items-center justify-between h-14 px-4">
        <button type="button" class="h-10 w-10 -ms-2 rounded-lg hover:bg-white/10 flex items-center justify-center kbd-focus" :aria-expanded="open" aria-controls="portal-sidebar" :aria-label="$t('nav.menu')" @click="open = !open">
          <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path v-if="!open" d="M4 7h16M4 12h16M4 17h16" /><path v-else d="M6 6l12 12M18 6L6 18" /></svg>
        </button>
        <span class="font-semibold">{{ portalTitle }}</span>
        <LangSwitch dark />
      </div>
    </header>

    <!-- sidebar -->
    <aside id="portal-sidebar" class="bg-ink text-white lg:sticky lg:top-0 lg:h-screen flex flex-col" :class="open ? 'block fixed inset-0 top-14 z-30 overflow-y-auto' : 'hidden lg:flex'" :aria-label="$t('nav.portalNavigation')">
      <div class="hidden lg:block">
        <DemoBanner />
        <NuxtLink :to="localePath('/')" class="flex items-center gap-2.5 px-5 h-16 border-b border-white/10 kbd-focus">
          <span class="h-9 w-9 rounded-xl bg-blue flex items-center justify-center font-bold text-sm">S</span>
          <span class="flex flex-col leading-tight"><span class="font-bold">{{ config.public.platformShortName }}</span><span class="text-[11px] text-white/60">{{ portalTitle }}</span></span>
        </NuxtLink>
      </div>
      <div class="px-5 py-4 border-b border-white/10">
        <p class="font-medium truncate">{{ auth.me?.name }}</p>
        <p class="text-xs text-white/60 truncate">{{ roleLabel }}</p>
        <AppBadge v-if="auth.isInvestor && auth.me?.membership" kind="membership" :value="auth.me.membership.status" :soft="false" class="mt-2" />
      </div>
      <nav class="flex-1 px-3 py-3 space-y-0.5">
        <NuxtLink v-for="it in items" :key="it.to" :to="localePath(it.to)" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition kbd-focus" :class="isActive(it.to) ? 'bg-white/10 text-white font-medium' : 'text-white/75 hover:bg-white/5 hover:text-white'" :aria-current="isActive(it.to) ? 'page' : undefined">
          <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="icons[it.icon]" /></svg>
          {{ it.label }}
        </NuxtLink>
      </nav>
      <div class="px-3 py-3 border-t border-white/10 space-y-0.5">
        <NuxtLink :to="localePath('/')" class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm text-white/70 hover:bg-white/5 hover:text-white kbd-focus">
          <svg class="h-5 w-5 rtl:-scale-x-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path :d="icons.back" /></svg>{{ $t('portal.nav.backToSite') }}
        </NuxtLink>
        <div class="hidden lg:block"><LangSwitch dark /></div>
        <button type="button" class="w-full flex items-center gap-3 rounded-lg px-3 py-2 text-sm text-white/70 hover:bg-white/5 hover:text-white kbd-focus" @click="logout">
          <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9" /></svg>{{ $t('common.logout') }}
        </button>
      </div>
    </aside>

    <main id="main" class="min-w-0">
      <div class="container-x py-6 lg:py-8 max-w-8xl">
        <slot />
      </div>
    </main>
  </div>
</template>

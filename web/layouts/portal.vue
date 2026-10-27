<script setup lang="ts">
/** Portal layout (design v3, templates C/D) — sidebar per role (investor / entrepreneur / admin), collapsible on mobile. */
const auth = useAuthStore()
const localePath = useLocalePath()
const route = useRoute()
const { t } = useI18n()
const config = useRuntimeConfig()
const open = ref(false)
watch(() => route.path, () => (open.value = false))
// ACC-07 — sign out after the configured idle time (settings come with the taxonomy cache).
if (import.meta.client) void useTaxonomyStore().load().catch(() => {})
useIdleLogout()
// One theme for the site and the portals (useSiteTheme); a light / dark choice saved on the account wins over this
// browser's ('system' is also the account default, so it leaves the browser's choice alone).
const siteTheme = useSiteTheme()
onMounted(() => {
  siteTheme.sync()
  watch(() => auth.me?.preferences?.theme, (p) => { if ((p === 'light' || p === 'dark') && p !== siteTheme.pref.value) siteTheme.apply(p) }, { immediate: true })
})

// Staff items are shown by permission (ACC-03), so custom roles see exactly what their permissions allow.
interface Item { to: string; label: string; icon: string; perms?: string[] }
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
  impact: 'M3 17l6-6 4 4 8-8M15 7h6v6',
  audit: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2M9 12h6M9 16h6',
  content: 'M4 4h16v16H4zM8 8h8M8 12h8M8 16h5',
  back: 'M10 19l-7-7 7-7M3 12h18',
  agreements: 'M7 3h7l5 5v13H7V3zM14 3v5h5M10 12h6M10 16h3M15 18.5l1.5 1.5 3-3',
  messages: 'M4 5h16v11H8l-4 4V5zM8 9h8M8 12h5',
  team: 'M8 11a3 3 0 100-6 3 3 0 000 6zM16 11a3 3 0 100-6 3 3 0 000 6zM2 20a6 6 0 0112 0M12 20a6 6 0 0110 0',
  events: 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4M9 14h2v2H9z',
}
// MTG-03 — unread record messages (sidebar badge), refreshed on load and at most once a minute while navigating.
const unreadMessages = useMessageUnread()
let unreadAt = 0
const refreshUnread = () => { if (Date.now() - unreadAt > 60_000) { unreadAt = Date.now(); void unreadMessages.refresh() } }
onMounted(refreshUnread)
watch(() => route.path, refreshUnread)
// ACC-08 — organisation accounts (representative + colleagues) get the team page.
const teamItem = computed<Item[]>(() => (auth.me?.organization_access ? [{ to: '/app/team', label: t('portal.nav.team'), icon: 'team' }] : []))

const items = computed<Item[]>(() => {
  if (auth.isStaff) {
    return [
      { to: '/app/admin/dashboard', label: t('portal.nav.dashboard'), icon: 'dashboard' },
      { to: '/app/admin/opportunities', label: t('portal.nav.opportunities'), icon: 'opportunities', perms: ['opportunities.view'] },
      { to: '/app/admin/memberships', label: t('portal.nav.memberships'), icon: 'memberships', perms: ['memberships.review'] },
      { to: '/app/admin/interests', label: t('portal.nav.interests'), icon: 'interests', perms: ['nominations.manage'] },
      { to: '/app/admin/info-requests', label: t('portal.nav.infoRequests'), icon: 'info', perms: ['nominations.manage'] },
      { to: '/app/admin/meetings', label: t('portal.nav.meetings'), icon: 'meetings', perms: ['meetings.manage'] },
      { to: '/app/admin/agreements', label: t('agreements.nav'), icon: 'agreements', perms: ['agreements.manage', 'agreements.approve'] },
      { to: '/app/admin/follow-up', label: t('portal.nav.followUp'), icon: 'impact', perms: ['impact.verify'] },
      { to: '/app/admin/messages', label: t('portal.nav.messages'), icon: 'messages', perms: ['opportunities.transition', 'nominations.manage'] },
      { to: '/app/admin/events', label: t('portal.nav.events'), icon: 'events', perms: ['settings.manage', 'meetings.manage'] },
      { to: '/app/admin/users', label: t('portal.nav.users'), icon: 'users', perms: ['users.manage'] },
      { to: '/app/admin/settings', label: t('portal.nav.settings'), icon: 'settings', perms: ['settings.manage'] },
      { to: '/app/admin/evaluation-criteria', label: t('portal.nav.criteria'), icon: 'criteria', perms: ['settings.manage', 'evaluations.score', 'evaluations.assign'] },
      { to: '/app/admin/reports', label: t('portal.nav.reports'), icon: 'reports', perms: ['reports.view'] },
      { to: '/app/admin/audit-logs', label: t('portal.nav.audit'), icon: 'audit', perms: ['audit.view'] },
      { to: '/app/admin/content', label: t('portal.nav.content'), icon: 'content', perms: ['settings.manage'] },
    ].filter((i) => !i.perms || auth.canAny(...i.perms))
  }
  if (auth.isEntrepreneur) {
    return [
      { to: '/app/entrepreneur/dashboard', label: t('portal.nav.dashboard'), icon: 'dashboard' },
      { to: '/app/entrepreneur/opportunities/new', label: t('portal.nav.newOpportunity'), icon: 'projects' },
      { to: '/app/entrepreneur/access-requests', label: t('portal.nav.accessRequests'), icon: 'access' },
      { to: '/app/entrepreneur/info-requests', label: t('portal.nav.infoRequests'), icon: 'info' },
      { to: '/app/entrepreneur/meetings', label: t('portal.nav.meetings'), icon: 'meetings' },
      { to: '/app/messages', label: t('portal.nav.messages'), icon: 'messages' },
      ...teamItem.value,
      { to: '/app/entrepreneur/follow-up', label: t('portal.nav.myFollowUp'), icon: 'impact' },
      { to: '/app/entrepreneur/agreements', label: t('agreements.nav'), icon: 'agreements' },
    ]
  }
  return [
    { to: '/app/investor/dashboard', label: t('portal.nav.dashboard'), icon: 'dashboard' },
    { to: '/app/opportunities', label: t('portal.nav.opportunities'), icon: 'opportunities' },
    { to: '/app/investor/nominations', label: t('portal.nav.nominations'), icon: 'nominations' },
    { to: '/app/investor/interests', label: t('portal.nav.interests'), icon: 'interests' },
    { to: '/app/investor/info-requests', label: t('portal.nav.infoRequests'), icon: 'info' },
    { to: '/app/investor/meetings', label: t('portal.nav.meetings'), icon: 'meetings' },
    { to: '/app/messages', label: t('portal.nav.messages'), icon: 'messages' },
    { to: '/app/investor/saved', label: t('portal.nav.saved'), icon: 'saved' },
    { to: '/app/investor/profile', label: t('portal.nav.profile'), icon: 'profile' },
    ...teamItem.value,
    { to: '/app/investor/portfolio', label: t('portal.nav.portfolio'), icon: 'impact' },
    { to: '/app/investor/agreements', label: t('agreements.nav'), icon: 'agreements' },
  ]
})
const portalTitle = computed(() => (auth.isStaff ? t('portal.admin') : auth.isEntrepreneur ? t('portal.entrepreneur') : t('portal.investor')))
const isActive = (to: string) => route.path === localePath(to) || route.path.startsWith(localePath(to) + '/')
const { label: enumLabel } = useEnumLabel()
const roleLabel = computed(() => (auth.isStaff ? auth.roles.map((r) => enumLabel('role', r)).join('، ') : enumLabel('userType', auth.me?.type)))

</script>

<template>
  <div class="min-h-screen bg-bg lg:grid lg:grid-cols-[260px_minmax(0,1fr)]">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:start-2 focus:z-50 bg-surface text-ink px-3 py-2 rounded-lg shadow">{{ $t('app.skipToContent') }}</a>

    <!-- sidebar -->
    <aside id="portal-sidebar" class="bg-deep text-ink border-e border-border lg:sticky lg:top-0 lg:h-screen flex flex-col relative overflow-hidden" :class="open ? 'block fixed inset-0 top-16 z-40 overflow-y-auto' : 'hidden lg:flex'" :aria-label="$t('nav.portalNavigation')">
      <div class="absolute inset-x-0 top-0 h-56 bg-gradient-to-b from-[var(--tint2)] to-transparent pointer-events-none" aria-hidden="true" />
      <div class="absolute -end-10 -bottom-10 opacity-[0.06] pointer-events-none select-none" aria-hidden="true"><BrandMark :width="164" :framed="false" /></div>
      <div class="hidden lg:block relative">
        <DemoBanner />
        <NuxtLink :to="localePath('/')" class="flex items-center gap-3 px-5 h-[4.5rem] border-b border-border kbd-focus">
          <BrandLogo variant="mark" size="sm" />
          <span class="flex flex-col leading-tight"><span class="font-display font-extrabold tracking-wide">{{ config.public.platformShortName }}</span><span class="text-xs text-accent-text">{{ portalTitle }}</span></span>
        </NuxtLink>
      </div>
      <div class="relative px-5 py-4 border-b border-border flex items-center gap-3">
        <NuxtLink :to="localePath('/app/account')" class="shrink-0 rounded-full kbd-focus" :aria-label="$t('topbar.profile')"><UserAvatar :name="auth.me?.name" :src="auth.me?.avatar_url" size="md" /></NuxtLink>
        <div class="min-w-0">
          <p class="font-medium truncate">{{ auth.me?.name }}</p>
          <p class="text-xs text-t3 truncate">{{ roleLabel }}</p>
          <AppBadge v-if="auth.isInvestor && auth.me?.membership" kind="membership" :value="auth.me.membership.status" :soft="false" class="mt-1.5" />
        </div>
      </div>
      <nav class="relative flex-1 px-3 py-3 space-y-0.5">
        <NuxtLink v-for="it in items" :key="it.to" :to="localePath(it.to)" class="relative flex items-center gap-3 rounded-full px-3 py-2.5 text-sm transition kbd-focus" :class="isActive(it.to) ? 'bg-accent/15 text-ink font-medium' : 'text-t2 hover:bg-ink/5 hover:text-ink'" :aria-current="isActive(it.to) ? 'page' : undefined">
          <span v-if="isActive(it.to)" class="absolute start-0 inset-y-2 w-1 rounded-e-full bg-accent-hi" aria-hidden="true" />
          <svg class="h-5 w-5 shrink-0" :class="isActive(it.to) ? 'text-accent-text' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="icons[it.icon]" /></svg>
          {{ it.label }}
          <span v-if="it.icon === 'messages' && unreadMessages.count.value" class="ms-auto min-w-5 h-5 px-1.5 rounded-full bg-accent text-on-accent text-xs font-semibold flex items-center justify-center num" :aria-label="$t('collab.messages.unreadCount', { n: unreadMessages.count.value })">{{ unreadMessages.count.value > 99 ? '99+' : unreadMessages.count.value }}</span>
        </NuxtLink>
      </nav>
      <div class="relative px-3 py-3 border-t border-border space-y-0.5">
        <NuxtLink :to="localePath('/app/account')" class="flex items-center gap-3 rounded-full px-3 py-2 text-sm kbd-focus" :class="isActive('/app/account') ? 'bg-accent/15 text-ink font-medium' : 'text-t2 hover:bg-ink/5 hover:text-ink'" :aria-current="isActive('/app/account') ? 'page' : undefined">
          <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="icons.profile" /></svg>{{ $t('topbar.profile') }}
        </NuxtLink>
        <NuxtLink :to="localePath('/app/security')" class="flex items-center gap-3 rounded-full px-3 py-2 text-sm kbd-focus" :class="isActive('/app/security') ? 'bg-accent/15 text-ink font-medium' : 'text-t2 hover:bg-ink/5 hover:text-ink'" :aria-current="isActive('/app/security') ? 'page' : undefined">
          <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 3v6c0 5-3.5 8.5-8 9-4.5-.5-8-4-8-9V6l8-3zM9 12l2 2 4-4" /></svg>{{ $t('portal.nav.security') }}
          <span v-if="auth.me && !auth.me.two_factor_enabled" class="ms-auto h-2 w-2 rounded-full bg-warning" :title="$t('security.off')" aria-hidden="true" />
        </NuxtLink>
      </div>
    </aside>

    <!-- UX-04 — the header sits outside <main>, so the skip link lands on the page content -->
    <div class="min-w-0">
      <DemoBanner class="lg:hidden" />
      <PortalTopbar :portal-title="portalTitle" :role-label="roleLabel" :menu-open="open" @toggle-menu="open = !open" />
      <main id="main" tabindex="-1" class="container-x py-6 lg:py-8 max-w-8xl focus:outline-none">
        <slot />
      </main>
    </div>
  </div>
</template>

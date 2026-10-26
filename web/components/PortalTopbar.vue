<script setup lang="ts">
/**
 * Portal header (all roles): page context on the start side; on the end side "view site", notifications bell with
 * unread badge + dropdown, language switch, user menu (profile, security, logout) and a direct logout button.
 */
import { toastError, toastInfo } from '~/utils/notify'
defineProps<{ portalTitle: string; roleLabel: string; menuOpen?: boolean }>()
const emit = defineEmits<{ (e: 'toggle-menu'): void }>()

const auth = useAuthStore()
const router = useRouter()
const localePath = useLocalePath()
const { t } = useI18n()
const { relative, date } = useFormat()
const notifications = useNotifications()
notifications.startPolling()

const bellOpen = ref(false)
const userOpen = ref(false)
const bellRef = ref<HTMLElement | null>(null)
const userRef = ref<HTMLElement | null>(null)
onClickOutside(bellRef, () => (bellOpen.value = false))
onClickOutside(userRef, () => (userOpen.value = false))
onKeyStroke('Escape', () => { bellOpen.value = false; userOpen.value = false })

const toggleBell = async () => {
  bellOpen.value = !bellOpen.value
  userOpen.value = false
  if (bellOpen.value) await notifications.load()
}
const openNotification = (n: PortalNotification) => {
  const to = notifications.linkFor(n)
  bellOpen.value = false
  if (to) router.push(localePath(to))
}

const route = useRoute()
watch(() => route.fullPath, () => { bellOpen.value = false; userOpen.value = false })

const logout = async () => {
  if (!(await auth.logout())) return void toastError(t('notify.logoutFailed'))
  toastInfo(t('notify.loggedOut'))
  router.push(localePath('/'))
}
const siteUrl = computed(() => localePath('/'))
// UX-05 — follows the calendar preference (Gregorian / Hijri / both), Latin digits.
const today = computed(() => date(new Date().toISOString(), { weekday: 'long', day: 'numeric', month: 'long' }))
</script>

<template>
  <header class="sticky top-0 z-30 bg-surface/90 backdrop-blur-md border-b border-border">
    <div class="h-16 px-4 lg:px-8 flex items-center gap-3">
      <!-- start: menu (mobile) + context -->
      <button type="button" class="lg:hidden h-10 w-10 -ms-1 rounded-lg hover:bg-navy-50 text-ink flex items-center justify-center kbd-focus" :aria-label="$t('nav.menu')" aria-controls="portal-sidebar" :aria-expanded="!!menuOpen" @click="emit('toggle-menu')">
        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" /></svg>
      </button>
      <div class="min-w-0 hidden sm:block">
        <p class="text-sm font-semibold text-ink truncate">{{ portalTitle }}</p>
        <p class="text-xs text-muted truncate"><ClientOnly>{{ today }}</ClientOnly></p>
      </div>

      <div class="ms-auto flex items-center gap-1.5 sm:gap-2">
        <!-- view site -->
        <a :href="siteUrl" target="_blank" rel="noopener" class="hidden md:inline-flex items-center gap-2 h-10 px-3 rounded-lg border border-border text-sm text-ink/80 hover:text-navy hover:border-navy/40 hover:bg-navy-50 transition kbd-focus">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1h5" /></svg>
          {{ $t('topbar.viewSite') }}
        </a>

        <!-- notifications -->
        <div ref="bellRef" class="relative">
          <button type="button" class="relative h-10 w-10 rounded-lg border border-border text-ink/80 hover:text-navy hover:border-navy/40 hover:bg-navy-50 flex items-center justify-center transition kbd-focus" :aria-expanded="bellOpen" aria-haspopup="dialog" :aria-label="$t('topbar.notifications')" @click="toggleBell">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 8a6 6 0 1112 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 003.4 0" /></svg>
            <span v-if="notifications.unread.value > 0" class="absolute -top-1.5 -end-1.5 min-w-[1.25rem] h-5 px-1 rounded-full bg-teal text-navy-900 text-[11px] font-bold flex items-center justify-center num ring-2 ring-white">{{ notifications.unread.value > 99 ? '99+' : notifications.unread.value }}</span>
          </button>
          <Transition name="page">
            <div v-if="bellOpen" class="absolute end-0 mt-2 w-[min(22rem,calc(100vw-2rem))] card shadow-pop overflow-hidden z-50" role="dialog" :aria-label="$t('topbar.notifications')">
              <div class="flex items-center justify-between px-4 py-3 border-b border-border">
                <p class="font-semibold text-ink">{{ $t('topbar.notifications') }}</p>
                <button v-if="notifications.unread.value > 0" type="button" class="text-xs link" @click="notifications.markAllRead()">{{ $t('topbar.markAllRead') }}</button>
              </div>
              <div v-if="notifications.loading.value" class="p-6 text-center text-sm text-muted">{{ $t('common.loading') }}</div>
              <ul v-else-if="notifications.items.value.length" class="max-h-96 overflow-y-auto divide-y divide-border">
                <li v-for="n in notifications.items.value" :key="n.id">
                  <button type="button" class="w-full text-start px-4 py-3 flex gap-3 hover:bg-navy-50/60 transition" @click="openNotification(n)">
                    <span class="mt-1.5 h-2 w-2 rounded-full shrink-0" :class="n.read_at ? 'bg-border' : 'bg-teal'" aria-hidden="true" />
                    <span class="min-w-0">
                      <span class="block text-sm font-medium text-ink">{{ n.title || $t('topbar.notification') }}</span>
                      <span v-if="n.body" class="block text-xs text-muted leading-5 line-clamp-2">{{ n.body }}</span>
                      <span v-if="n.created_at" class="block text-[11px] text-muted mt-1">{{ relative(n.created_at) }}</span>
                    </span>
                  </button>
                </li>
              </ul>
              <div v-else class="p-8 text-center">
                <svg class="h-8 w-8 mx-auto text-muted/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M6 8a6 6 0 1112 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 003.4 0" /></svg>
                <p class="mt-2 text-sm text-muted">{{ $t('topbar.noNotifications') }}</p>
              </div>
            </div>
          </Transition>
        </div>

        <!-- language -->
        <!-- BRD-04 — light / dark (only while FEATURE_DARK_MODE is on) -->
        <ThemeToggle />
        <LangSwitch class="h-10 border border-border hover:border-navy/40" />

        <!-- user menu -->
        <div ref="userRef" class="relative">
          <button type="button" class="h-10 ps-1 pe-1 sm:pe-3 rounded-full border border-border hover:border-navy/40 hover:bg-navy-50 flex items-center gap-2 transition kbd-focus" :aria-expanded="userOpen" :aria-label="$t('topbar.account')" @click="userOpen = !userOpen; bellOpen = false">
            <UserAvatar :name="auth.me?.name" :src="auth.me?.avatar_url" size="sm" />
            <span class="hidden sm:flex flex-col items-start leading-tight max-w-[10rem]">
              <span class="text-sm font-medium text-ink truncate max-w-full">{{ auth.me?.name }}</span>
              <span class="text-[11px] text-muted truncate max-w-full">{{ roleLabel }}</span>
            </span>
            <svg class="hidden sm:block h-4 w-4 text-muted transition" :class="userOpen && 'rotate-180'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
          </button>
          <Transition name="page">
            <div v-if="userOpen" class="absolute end-0 mt-2 w-64 card shadow-pop overflow-hidden z-50">
              <div class="px-4 py-4 flex items-center gap-3 bg-navy-50/60 border-b border-border">
                <UserAvatar :name="auth.me?.name" :src="auth.me?.avatar_url" size="md" />
                <div class="min-w-0">
                  <p class="font-medium text-ink truncate">{{ auth.me?.name }}</p>
                  <p class="text-xs text-muted truncate ltr text-start">{{ auth.me?.email }}</p>
                </div>
              </div>
              <nav class="py-1.5 text-sm">
                <NuxtLink :to="localePath('/app/account')" class="flex items-center gap-3 px-4 py-2.5 text-ink hover:bg-navy-50">
                  <svg class="h-4 w-4 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 12a4 4 0 100-8 4 4 0 000 8zM4 21a8 8 0 0116 0" /></svg>{{ $t('topbar.profile') }}
                </NuxtLink>
                <NuxtLink v-if="auth.isInvestor" :to="localePath('/app/investor/profile')" class="flex items-center gap-3 px-4 py-2.5 text-ink hover:bg-navy-50">
                  <svg class="h-4 w-4 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 20V10M10 20V4M16 20v-6M22 20H2" /></svg>{{ $t('topbar.investmentProfile') }}
                </NuxtLink>
                <NuxtLink :to="localePath('/app/security')" class="flex items-center gap-3 px-4 py-2.5 text-ink hover:bg-navy-50">
                  <svg class="h-4 w-4 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3l8 3v6c0 5-3.5 8.5-8 9-4.5-.5-8-4-8-9V6l8-3z" /></svg>{{ $t('topbar.security') }}
                  <span v-if="auth.me && !auth.me.two_factor_enabled" class="ms-auto text-[10px] rounded-full bg-warning/10 text-warning px-2 py-0.5">{{ $t('topbar.off') }}</span>
                </NuxtLink>
                <a :href="siteUrl" target="_blank" rel="noopener" class="md:hidden flex items-center gap-3 px-4 py-2.5 text-ink hover:bg-navy-50">
                  <svg class="h-4 w-4 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1h5" /></svg>{{ $t('topbar.viewSite') }}
                </a>
              </nav>
              <div class="border-t border-border py-1.5">
                <button type="button" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-danger hover:bg-danger/5" @click="logout">
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9" /></svg>{{ $t('common.logout') }}
                </button>
              </div>
            </div>
          </Transition>
        </div>

        <!-- direct logout -->
        <button type="button" class="hidden md:inline-flex items-center gap-2 h-10 px-3 rounded-lg border border-danger/30 text-sm text-danger hover:bg-danger/5 hover:border-danger/50 transition kbd-focus" @click="logout">
          <svg class="h-4 w-4 rtl:-scale-x-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9" /></svg>
          {{ $t('common.logout') }}
        </button>
      </div>
    </div>
  </header>
</template>

/**
 * ACC-07 — signs the user out after `idle_timeout_minutes` (public setting, 0 = off) without activity.
 * Activity is shared across tabs through localStorage. Reading or typing makes no request, so while the user is
 * active the server session is kept alive with POST /auth/keepalive (at most every 5 minutes).
 */
import { toastWarning } from '~/utils/notify'
import { signedInHint } from '~/stores/auth'

const KEY = 'sciis.last-activity'
const EVENTS = ['pointerdown', 'keydown', 'scroll', 'touchstart', 'mousemove'] as const
const KEEPALIVE_MS = 5 * 60_000
const WARN_BEFORE_MS = 60_000

export const useIdleLogout = () => {
  const auth = useAuthStore()
  const tax = useTaxonomyStore()
  const api = useApi()
  const { t } = useI18n()
  const localePath = useLocalePath()
  const route = useRoute()
  const router = useRouter()

  let last = Date.now()
  let lastWrite = 0
  let lastPing = Date.now()
  let warned = false
  let timer: ReturnType<typeof setInterval> | null = null

  const limitMs = () => Math.max(0, Number(tax.settings?.idle_timeout_minutes ?? 30)) * 60_000
  const sharedLast = () => {
    try {
      return Number(localStorage.getItem(KEY)) || last
    } catch {
      return last
    }
  }

  const touch = () => {
    const now = Date.now()
    if (now - lastWrite < 5000) return
    lastWrite = last = now
    warned = false
    try {
      localStorage.setItem(KEY, String(now))
    } catch {}
    if (now - lastPing > KEEPALIVE_MS && auth.isAuthenticated) {
      lastPing = now
      api.post('/auth/keepalive').catch(() => {})
    }
  }

  const stop = () => {
    if (timer) clearInterval(timer)
    timer = null
    for (const e of EVENTS) window.removeEventListener(e, touch)
  }

  const check = async () => {
    const limit = limitMs()
    if (!limit || !auth.isAuthenticated) return
    const idle = Date.now() - Math.max(last, sharedLast())
    if (idle >= limit) {
      stop()
      // The server session may already be gone; the local sign-out happens either way.
      if (!(await auth.logout())) {
        auth.me = null
        signedInHint.set(false)
      }
      toastWarning(t('notify.idleSignedOut'))
      await router.push({ path: localePath('/auth/login'), query: { redirect: route.fullPath } })
    } else if (!warned && idle >= limit - WARN_BEFORE_MS) {
      warned = true
      toastWarning(t('notify.idleSoon'))
    }
  }

  onMounted(() => {
    touch()
    for (const e of EVENTS) window.addEventListener(e, touch, { passive: true })
    timer = setInterval(check, 15_000)
  })
  onBeforeUnmount(stop)
}

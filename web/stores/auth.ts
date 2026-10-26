/**
 * Auth store — ACC-01..05. Holds `me`, login (with 2FA challenge), logout, register, OTP verify.
 *
 * Contract with the Laravel API (SPA / cookie session):
 *  - POST /auth/login       → { data: { user, two_factor_required, token? } }   (no device_name for the web)
 *  - POST /auth/2fa/challenge {code} → { data: { two_factor_passed } }          (then re-fetch /me)
 *  - POST /auth/register    → { data: user, message }
 *  - POST /auth/otp/send {channel: email|phone} → { data: { channel, expires_in, resend_in } }
 *  - POST /auth/verify-otp {channel, code}       → { data: { email_verified, phone_verified, … } } (then re-fetch /me)
 *  - GET  /me               → { data: user + two_factor_passed + unread_notifications }
 */
import { defineStore } from 'pinia'
import type { InvestorProfilePayload, Me } from '~/types/api'
import type { StaffRole, UserType } from '~/types/enums'

export interface RegisterPayload {
  type: UserType
  name: string
  email: string
  /** E.164 (`+9665xxxxxxxx`) — see `normalizePhone()` */
  phone: string
  password: string
  password_confirmation?: string
  locale: 'ar' | 'en'
  /** investors also send `data_accuracy` (INV-01 — required by RegisterRequest) */
  consents: ('privacy_policy' | 'terms' | 'data_accuracy')[]
  consents_version?: string
  preferred_contact?: 'email' | 'phone' | 'whatsapp' | string
  organization_name?: string
  organization?: { institution_type?: string; legal_form?: string; cr_number?: string; city?: string }
  /** same sectioned shape as PUT /investor/profile */
  profile?: InvestorProfilePayload
  /** entrepreneur: first draft opportunity (at least `name`) */
  project?: { name: string; short_description?: string; sector_id?: number | null; stage?: string | null }
}

/**
 * "This browser has a signed-in session" hint (localStorage). The static site can't tell from cookies — Laravel sets
 * them for anonymous visitors too — so public pages only ask GET /me when the hint is there: anonymous visitors never
 * trigger a 401. Set whenever `me` is known, cleared on logout / session end.
 */
const SIGNED_IN_HINT = 'sciis.signed-in'
export const signedInHint = {
  get(): boolean {
    if (!import.meta.client) return false
    try {
      return localStorage.getItem(SIGNED_IN_HINT) === '1'
    } catch {
      return false
    }
  },
  set(on: boolean) {
    if (!import.meta.client) return
    try {
      if (on) localStorage.setItem(SIGNED_IN_HINT, '1')
      else localStorage.removeItem(SIGNED_IN_HINT)
    } catch {
      /* storage blocked (private mode) — the hint is only an optimisation */
    }
  },
}

export const useAuthStore = defineStore('auth', () => {
  const me = ref<Me | null>(null)
  const loaded = ref(false)
  const loading = ref(false)
  if (import.meta.client) {
    watch(me, (m) => {
      if (m) signedInHint.set(true)
      else if (loaded.value) signedInHint.set(false)
    })
  }

  const isAuthenticated = computed(() => !!me.value)
  const isInvestor = computed(() => !!me.value && me.value.type.startsWith('investor'))
  const isEntrepreneur = computed(() => !!me.value && me.value.type.startsWith('entrepreneur'))
  const isStaff = computed(() => me.value?.type === 'staff')
  const emailVerified = computed(() => !!(me.value?.email_verified ?? me.value?.email_verified_at))
  const phoneVerified = computed(() => !!(me.value?.phone_verified ?? me.value?.phone_verified_at))
  /** ACC-01: the phone step is required unless the server turned it off (no SMS provider yet); staff never need it. */
  const phoneRequired = computed(() => !isStaff.value && useTaxonomyStore().settings?.phone_verification !== false)
  const isVerified = computed(() => emailVerified.value && (phoneVerified.value || !phoneRequired.value))
  /** Local-dev only: `GET /settings/public` → `verification_optional` lets unverified users through (API + web guards). */
  const verificationOptional = computed(() => !!useTaxonomyStore().settings?.verification_optional)
  /** Unverified and the dev flag is off → must go through /auth/verify first. */
  const needsVerification = computed(() => isAuthenticated.value && !isVerified.value && !verificationOptional.value)
  /** Logged in with 2FA enabled but the TOTP challenge for this session is still open (login step 2). */
  const twoFactorPending = computed(() => !!me.value && me.value.two_factor_passed === false)
  const isApprovedMember = computed(() => me.value?.membership?.status === 'approved')
  const roles = computed<StaffRole[]>(() => me.value?.roles ?? [])
  const hasRole = (...r: StaffRole[]) => roles.value.includes('super_admin') || r.some((x) => roles.value.includes(x))
  const can = (perm: string) => !!me.value?.permissions?.includes(perm)
  /** Staff UI gates follow permissions (ACC-03), so custom roles work too; super_admin sees everything. */
  const canAny = (...perms: string[]) => roles.value.includes('super_admin') || perms.some(can)

  /** Where this user's portal home is */
  const homePath = computed(() => {
    if (!me.value) return '/auth/login'
    if (isStaff.value) return '/app/admin/dashboard'
    if (isEntrepreneur.value) return '/app/entrepreneur/dashboard'
    return '/app/investor/dashboard'
  })

  /**
   * Common portal gate used by the route middleware: a pending 2FA challenge goes back to the login (step 2),
   * an unverified account (when the dev flag is off) goes to the OTP screen. Returns null when nothing blocks.
   */
  async function gate(): Promise<{ path: string; query?: Record<string, string> } | null> {
    if (!me.value) return null
    if (twoFactorPending.value) return { path: '/auth/login', query: { step: '2fa' } }
    if (!isVerified.value) {
      await useTaxonomyStore().load().catch(() => {})
      if (needsVerification.value) return { path: '/auth/verify' }
    }
    return null
  }

  // One /me request at a time: the boot plugin and a route middleware may both ask while it is in flight.
  let inflight: Promise<Me | null> | null = null
  async function fetchMe(force = false) {
    if (loaded.value && !force) return me.value
    if (inflight && !force) return inflight
    const api = useApi()
    loading.value = true
    inflight = (async () => {
      try {
        const res = await api.get<{ data: Me }>('/me')
        me.value = res.data
      } catch (e) {
        me.value = null
        // No session (anymore): stop asking on every public page.
        if ((e as { status?: number })?.status === 401) signedInHint.set(false)
      } finally {
        loaded.value = true
        loading.value = false
      }
      return me.value
    })().finally(() => {
      inflight = null
    })
    return inflight
  }

  async function login(email: string, password: string) {
    const api = useApi()
    // No `device_name`: that would make the API issue a bearer token instead of the cookie session the SPA relies on.
    const res = await api.post<{ data: { user: Me; two_factor_required?: boolean; token?: string } }>('/auth/login', { email, password })
    const pending = !!res.data.two_factor_required
    me.value = { ...res.data.user, two_factor_passed: !pending }
    loaded.value = true
    // /me carries the full self view (permissions, unread count, session 2FA flag); keep the login payload if it fails.
    const full = await fetchMe(true).catch(() => null)
    if (!full) me.value = { ...res.data.user, two_factor_passed: !pending }
    else if (pending) me.value = { ...full, two_factor_passed: false }
    if (pending) return { twoFactor: true as const }
    return { twoFactor: false as const, me: me.value }
  }

  /** ACC-05 — TOTP (or recovery code) after login; the session flag is set server-side, so /me is re-fetched. */
  async function challenge2fa(code: string) {
    const api = useApi()
    await api.post<{ data: { two_factor_passed: boolean } }>('/auth/2fa/challenge', { code })
    await fetchMe(true)
    if (me.value) me.value.two_factor_passed = true
    return me.value
  }

  async function register(payload: RegisterPayload) {
    const api = useApi()
    const res = await api.post<{ data: Me; message?: string }>('/auth/register', payload)
    me.value = res.data
    loaded.value = true
    return me.value
  }

  /** Email / phone OTP (standard contract `POST /auth/verify-otp {channel, code}`); the user is reloaded afterwards. */
  async function verify(channel: 'email' | 'phone', code: string) {
    const api = useApi()
    await api.post<{ data?: { email_verified?: boolean; phone_verified?: boolean }; message?: string }>('/auth/verify-otp', { channel, code })
    await fetchMe(true)
    return me.value
  }

  /** `POST /auth/otp/send {channel}` → seconds until another code may be requested (`resend_in`, default 60). */
  async function sendOtp(channel: 'email' | 'phone') {
    const api = useApi()
    const res = await api.post<{ data?: { resend_in?: number; expires_in?: number } }>('/auth/otp/send', { channel })
    return { resendIn: Number(res?.data?.resend_in) || 60, expiresIn: Number(res?.data?.expires_in) || 0 }
  }

  /**
   * Resolves `true` once the server ended the session (or it had already ended: 401 / 419). On any other failure
   * (offline, 5xx) the user stays signed in locally and `false` is returned, so the UI can say so instead of
   * pretending the session is closed.
   */
  async function logout(): Promise<boolean> {
    const api = useApi()
    try {
      await api.post('/auth/logout')
    } catch (e) {
      const status = (e as { status?: number })?.status
      if (status !== 401 && status !== 419) return false
    }
    me.value = null
    loaded.value = true
    signedInHint.set(false)
    return true
  }

  async function updateMe(patch: Partial<Pick<Me, 'name' | 'locale' | 'preferred_contact'>>) {
    const api = useApi()
    const res = await api.patch<{ data: Me }>('/me', patch)
    me.value = { ...me.value, ...res.data }
    return me.value
  }

  return {
    me,
    loaded,
    loading,
    isAuthenticated,
    isInvestor,
    isEntrepreneur,
    isStaff,
    emailVerified,
    phoneVerified,
    isVerified,
    phoneRequired,
    verificationOptional,
    needsVerification,
    twoFactorPending,
    isApprovedMember,
    roles,
    hasRole,
    canAny,
    can,
    homePath,
    gate,
    fetchMe,
    login,
    challenge2fa,
    register,
    verify,
    sendOtp,
    logout,
    updateMe,
  }
})

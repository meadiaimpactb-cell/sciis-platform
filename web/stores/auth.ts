/**
 * Auth store — ACC-01..05. Holds `me`, login (with 2FA challenge), logout, register, OTP verify.
 */
import { defineStore } from 'pinia'
import type { Me } from '~/types/api'
import type { StaffRole, UserType } from '~/types/enums'

export interface RegisterPayload {
  type: UserType
  name: string
  email: string
  phone: string
  password: string
  locale: 'ar' | 'en'
  consents: string[]
  organization?: Record<string, unknown>
  profile?: Record<string, unknown>
  project?: Record<string, unknown>
  preferred_contact?: string
}

export const useAuthStore = defineStore('auth', () => {
  const me = ref<Me | null>(null)
  const loaded = ref(false)
  const loading = ref(false)
  const twoFactorChallenge = ref<string | null>(null)

  const isAuthenticated = computed(() => !!me.value)
  const isInvestor = computed(() => !!me.value && me.value.type.startsWith('investor'))
  const isEntrepreneur = computed(() => !!me.value && me.value.type.startsWith('entrepreneur'))
  const isStaff = computed(() => me.value?.type === 'staff')
  const isVerified = computed(() => !!me.value?.email_verified_at && !!me.value?.phone_verified_at)
  const isApprovedMember = computed(() => me.value?.membership?.status === 'approved')
  const roles = computed<StaffRole[]>(() => me.value?.roles ?? [])
  const hasRole = (...r: StaffRole[]) => roles.value.includes('super_admin') || r.some((x) => roles.value.includes(x))
  const can = (perm: string) => !!me.value?.permissions?.includes(perm)

  /** Where this user's portal home is */
  const homePath = computed(() => {
    if (!me.value) return '/auth/login'
    if (isStaff.value) return '/app/admin/dashboard'
    if (isEntrepreneur.value) return '/app/entrepreneur/dashboard'
    return '/app/investor/dashboard'
  })

  async function fetchMe(force = false) {
    if (loaded.value && !force) return me.value
    const api = useApi()
    loading.value = true
    try {
      const res = await api.get<{ data: Me }>('/me')
      me.value = res.data
    } catch {
      me.value = null
    } finally {
      loaded.value = true
      loading.value = false
    }
    return me.value
  }

  async function login(email: string, password: string) {
    const api = useApi()
    const res = await api.post<{ data?: Me; two_factor_required?: boolean; challenge_token?: string }>('/auth/login', { email, password, device_name: 'web' })
    if (res.two_factor_required) {
      twoFactorChallenge.value = res.challenge_token ?? null
      return { twoFactor: true as const }
    }
    me.value = res.data ?? null
    loaded.value = true
    return { twoFactor: false as const, me: me.value }
  }

  async function challenge2fa(code: string) {
    const api = useApi()
    const res = await api.post<{ data: Me }>('/auth/2fa/challenge', { code, challenge_token: twoFactorChallenge.value })
    me.value = res.data
    loaded.value = true
    twoFactorChallenge.value = null
    return me.value
  }

  async function register(payload: RegisterPayload) {
    const api = useApi()
    const res = await api.post<{ data: Me }>('/auth/register', payload)
    me.value = res.data
    loaded.value = true
    return me.value
  }

  async function verify(channel: 'email' | 'phone', code: string) {
    const api = useApi()
    const res = await api.post<{ data: Me }>(channel === 'email' ? '/auth/verify-email' : '/auth/verify-phone', { code })
    me.value = res.data
    return me.value
  }

  async function resendOtp(channel: 'email' | 'sms') {
    const api = useApi()
    await api.post('/auth/resend-otp', { channel })
  }

  async function logout() {
    const api = useApi()
    try {
      await api.post('/auth/logout')
    } catch {
      /* ignore */
    }
    me.value = null
    loaded.value = true
  }

  async function updateMe(patch: Partial<Pick<Me, 'name' | 'locale' | 'preferred_contact'>>) {
    const api = useApi()
    const res = await api.patch<{ data: Me }>('/me', patch)
    me.value = res.data
    return me.value
  }

  return {
    me,
    loaded,
    loading,
    twoFactorChallenge,
    isAuthenticated,
    isInvestor,
    isEntrepreneur,
    isStaff,
    isVerified,
    isApprovedMember,
    roles,
    hasRole,
    can,
    homePath,
    fetchMe,
    login,
    challenge2fa,
    register,
    verify,
    resendOtp,
    logout,
    updateMe,
  }
})

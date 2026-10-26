/**
 * API client — SPEC §6. $fetch wrapper with:
 *  - baseURL from runtimeConfig.public.apiBase (or `/api/v1` in mock mode)
 *  - credentials: 'include' (Sanctum SPA cookies)
 *  - Sanctum CSRF bootstrap (GET /sanctum/csrf-cookie on the API origin) before first mutating call
 *  - SSR: forwards the visitor's cookie plus Origin/Referer of the web app so Sanctum treats the request as stateful
 *  - Accept-Language from the current locale
 *  - normalized error handling: { message, errors{field: [...]}, code, status }
 *  - 2FA gates (403 `two_factor_challenge_required` / `two_factor_enrolment_required`) → redirect client-side
 *  - 401 for a signed-in user → "session expired" toast + login redirect (back to the same page afterwards)
 */
import type { FetchOptions } from 'ofetch'
import type { ApiError } from '~/types/api'
import { toastError } from '~/utils/notify'

export class ApiRequestError extends Error implements ApiError {
  status: number
  errors?: Record<string, string[]>
  code?: string
  constructor(message: string, status: number, errors?: Record<string, string[]>, code?: string) {
    super(message)
    this.name = 'ApiRequestError'
    this.status = status
    this.errors = errors
    this.code = code
  }

  /** First message for a field, or undefined */
  field(name: string): string | undefined {
    return this.errors?.[name]?.[0]
  }
}

let csrfReady = false
let csrfPromise: Promise<void> | null = null

export const useApiBase = () => {
  const config = useRuntimeConfig()
  const mock = String(config.public.apiMock) === 'true'
  const baseURL = mock ? '/api/v1' : (config.public.apiBase as string)
  // API origin (strip the /api/v1 suffix) — Sanctum's csrf-cookie lives on the origin, not under /api
  const origin = mock ? '' : baseURL.replace(/\/api\/v\d+\/?$/, '')
  return { mock, baseURL, origin }
}

const readCookie = (name: string): string | null => {
  if (import.meta.server) return null
  const m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'))
  return m ? decodeURIComponent(m[1]) : null
}

/** Where the API sends a user whose session is gated by 2FA (ACC-05). */
const TWO_FACTOR_REDIRECTS: Record<string, string> = {
  two_factor_challenge_required: '/auth/login?step=2fa',
  two_factor_enrolment_required: '/app/security',
}

export const useApi = () => {
  const { baseURL, origin, mock } = useApiBase()
  // Read the locale from the app instance (safe inside stores, middleware and plugins — useI18n() is setup-only).
  const nuxtApp = useNuxtApp()
  const locale = computed<string>(() => (nuxtApp.$i18n as { locale?: { value?: string } } | undefined)?.locale?.value ?? 'ar')
  const localePath = (p: string) => (nuxtApp.$localePath as ((p: string) => string) | undefined)?.(p) ?? p

  // SSR: the visitor's cookie + the web app's own origin as Origin/Referer (Sanctum's stateful-domain check needs
  // one of them; Nitro would otherwise send a bare server-to-server request and /me answers 401 → logged-out header).
  // Captured synchronously here (inside the Nuxt context), not later inside an awaited request.
  const ssrHeaders: Record<string, string> = (() => {
    if (!import.meta.server) return {}
    const incoming = useRequestHeaders(['cookie', 'referer', 'x-forwarded-for'])
    // Visitor IP for the API's per-IP rate limits (Laravel trusts proxies); without it every SSR call shares the
    // Nuxt server's IP and the whole site is throttled as one client.
    let clientIp = ''
    try {
      clientIp = incoming['x-forwarded-for'] || useRequestEvent()?.node.req.socket.remoteAddress || ''
    } catch {
      clientIp = ''
    }
    const config = useRuntimeConfig()
    let site = ((config.public as { siteUrl?: string }).siteUrl || process.env.NUXT_PUBLIC_SITE_URL || '').replace(/\/$/, '')
    if (!site) {
      try {
        site = useRequestURL().origin
      } catch {
        site = 'http://localhost:3000'
      }
    }
    const prerenderToken = (config as { prerenderToken?: string }).prerenderToken
    return {
      ...(incoming.cookie ? { cookie: incoming.cookie } : {}),
      Origin: site,
      Referer: incoming.referer ?? `${site}/`,
      ...(clientIp ? { 'X-Forwarded-For': clientIp } : {}),
      // Static build only: the server-side secret that exempts the build's GETs from the per-IP API limits.
      ...(prerenderToken ? { 'X-Prerender-Token': prerenderToken } : {}),
    }
  })()

  const ensureCsrf = async () => {
    if (import.meta.server || csrfReady) return
    if (!csrfPromise) {
      csrfPromise = $fetch(`${origin}/sanctum/csrf-cookie`, { credentials: 'include', method: 'GET' })
        .then(() => {
          csrfReady = true
        })
        .catch(() => {
          // In mock mode or when the API is unreachable we still continue; the server will reject if needed.
          csrfReady = mock
        })
        .finally(() => {
          csrfPromise = null
        })
    }
    await csrfPromise
  }

  const normalizeError = (e: unknown): ApiRequestError => {
    // Laravel: { message, errors, code } at top level. h3/Nitro createError (mock): { message, data: { message, errors, code } }.
    const err = e as { response?: { status?: number; _data?: Partial<ApiError> & { data?: Partial<ApiError> } }; data?: Partial<ApiError>; message?: string; statusCode?: number }
    const raw = err?.response?._data ?? err?.data
    const data = { ...(raw ?? {}), ...((raw as { data?: Partial<ApiError> } | undefined)?.data ?? {}) } as Partial<ApiError>
    const status = err?.response?.status ?? err?.statusCode ?? 0
    const message = data?.message ?? err?.message ?? 'Request failed'
    return new ApiRequestError(message, status, data?.errors, data?.code)
  }

  /** ACC-05 — a 403 from the 2FA middleware sends the user to the challenge / enrolment screen. */
  const handleTwoFactorGate = (error: ApiRequestError) => {
    if (!import.meta.client || error.status !== 403 || !error.code) return
    const target = TWO_FACTOR_REDIRECTS[error.code]
    if (!target) return
    const [path, query] = target.split('?')
    const current = useRoute()
    if (current.path === localePath(path)) return
    navigateTo({ path: localePath(path), query: Object.fromEntries(new URLSearchParams(query ?? '')) })
  }

  /**
   * A signed-in user whose session ended (expired cookie, revoked token) gets one clear toast and is sent to the
   * login page, coming back to the same screen afterwards. Guests and the auth endpoints themselves are left to
   * the calling page (a 401 there is expected, not an expiry). Concurrent 401s toast once: the first one clears `me`.
   */
  const handleSessionExpired = (error: ApiRequestError, path: string) => {
    if (!import.meta.client || error.status !== 401) return
    if (path === '/me' || path.startsWith('/auth/')) return
    nuxtApp.runWithContext(() => {
      const auth = useAuthStore()
      if (!auth.me) return
      auth.me = null
      void toastError(nuxtApp.$i18n.t('notify.sessionExpired'))
      navigateTo({ path: localePath('/auth/login'), query: { redirect: useRoute().fullPath } })
    })
  }

  const request = async <T>(path: string, opts: FetchOptions<'json'> = {}): Promise<T> => {
    const method = (opts.method ?? 'GET').toString().toUpperCase()
    if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) await ensureCsrf()
    const xsrf = readCookie('XSRF-TOKEN')
    try {
      const options = {
        baseURL,
        credentials: 'include',
        ...opts,
        headers: {
          Accept: 'application/json',
          'Accept-Language': locale.value,
          ...ssrHeaders,
          ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
          ...(opts.headers as Record<string, string> | undefined),
        },
      }
      return (await $fetch(path, options as never)) as T
    } catch (e) {
      const error = normalizeError(e)
      handleTwoFactorGate(error)
      handleSessionExpired(error, path)
      throw error
    }
  }

  return {
    baseURL,
    mock,
    get: <T>(path: string, query?: Record<string, unknown>) => request<T>(path, { method: 'GET', query }),
    post: <T>(path: string, body?: unknown, query?: Record<string, unknown>) => request<T>(path, { method: 'POST', body: body as never, query }),
    put: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PUT', body: body as never }),
    patch: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PATCH', body: body as never }),
    del: <T>(path: string, body?: unknown) => request<T>(path, { method: 'DELETE', body: body as never }),
    request,
  }
}

/** Convenience: unwrap `{ data }` envelopes */
export type Envelope<T> = { data: T }

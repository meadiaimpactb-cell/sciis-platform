/**
 * API client — SPEC §6. $fetch wrapper with:
 *  - baseURL from runtimeConfig.public.apiBase (or `/api/v1` in mock mode)
 *  - credentials: 'include' (Sanctum SPA cookies)
 *  - Sanctum CSRF bootstrap (GET /sanctum/csrf-cookie on the API origin) before first mutating call
 *  - Accept-Language from the current locale
 *  - normalized error handling: { message, errors{field: [...]}, code, status }
 */
import type { FetchOptions } from 'ofetch'
import type { ApiError } from '~/types/api'

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

export const useApi = () => {
  const { baseURL, origin, mock } = useApiBase()
  // Read the locale from the app instance (safe inside stores, middleware and plugins — useI18n() is setup-only).
  const nuxtApp = useNuxtApp()
  const locale = computed<string>(() => (nuxtApp.$i18n as { locale?: { value?: string } } | undefined)?.locale?.value ?? 'ar')
  const headers = useRequestHeaders(['cookie'])

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
          ...(import.meta.server ? headers : {}),
          ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
          ...(opts.headers as Record<string, string> | undefined),
        },
      }
      return (await $fetch(path, options as never)) as T
    } catch (e) {
      throw normalizeError(e)
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

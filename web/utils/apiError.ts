/**
 * API error → user-facing text. Deliberately tiny (no zod, no libphonenumber): it is used by the notification layer
 * and the API client, which load on every page, while the form validation stack (utils/validation.ts) only loads
 * with the pages that render forms.
 */
import { ApiRequestError } from '~/composables/useApi'

export interface ParsedApiError { message: string; errors: Record<string, string>; status: number | null; code?: string }

/** Translate with the app's i18n at call time (current locale); the key itself if i18n isn't available. */
function tr(key: string): string {
  try {
    return (useNuxtApp().$i18n as { t: (k: string) => string }).t(key)
  } catch {
    return key
  }
}

/** 422 `{ message, errors: {field: [msg]} }` → `{ message, errors: {field: msg}, status }` for `setErrors`. */
export function parseApiError(e: unknown): ParsedApiError {
  if (e instanceof ApiRequestError) {
    const errors: Record<string, string> = {}
    for (const [k, v] of Object.entries(e.errors ?? {})) if (v?.[0]) errors[k] = v[0]
    return { message: friendlyMessage(e), errors, status: e.status || null, code: e.code }
  }
  return { message: tr('notify.network'), errors: {}, status: null }
}

/** User-facing text for a failed request: no raw fetch / exception text ever reaches a toast. */
function friendlyMessage(e: ApiRequestError): string {
  if (!e.status) return tr('notify.network') // no response (offline, CORS, DNS)
  if (e.status === 401) return tr('notify.sessionExpired')
  if (e.status === 403 && (!e.code || e.code === 'forbidden')) return tr('notify.forbidden')
  if (e.status >= 500) return tr('notify.serverError')
  return e.message || tr('notify.genericError')
}

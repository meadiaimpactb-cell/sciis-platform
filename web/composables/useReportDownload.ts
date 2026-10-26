/**
 * RPT-02/04/06, ADM-08 — binary report downloads (xlsx / PDF). The API answers with an attachment, so the file opens
 * in a new tab carrying the Sanctum cookie session; the server checks the export rule and audits every download.
 * `query` values: arrays become repeated `key[]=` params, nested objects `key[sub]=`.
 */
import { toastApiError, toastInfo } from '~/utils/notify'

type QueryValue = string | number | boolean | null | undefined | QueryValue[] | { [k: string]: QueryValue }

export const useReportDownload = () => {
  const api = useApi()
  const { t } = useI18n()

  const appendParams = (qs: URLSearchParams, key: string, value: QueryValue) => {
    if (value == null || value === '') return
    if (Array.isArray(value)) { for (const v of value) if (v != null && v !== '') qs.append(`${key}[]`, String(v)); return }
    if (typeof value === 'object') { for (const [k, v] of Object.entries(value)) appendParams(qs, `${key}[${k}]`, v); return }
    qs.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value))
  }

  const download = async (path: string, query: Record<string, QueryValue> = {}) => {
    try {
      if (api.mock) { toastInfo(t('notify.preparing')); return }
      const qs = new URLSearchParams()
      for (const [k, v] of Object.entries(query)) appendParams(qs, k, v)
      const s = qs.toString()
      window.open(`${api.baseURL}${path}${s ? `?${s}` : ''}`, '_blank', 'noopener')
      toastInfo(t('notify.preparing'))
    } catch (e) { toastApiError(e) }
  }

  return { download }
}

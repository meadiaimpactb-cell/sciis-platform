/**
 * Minimal path router for the mock API (Nitro catch-all). Matches `METHOD /path/:param`.
 */
import type { H3Event } from 'h3'
import { createError } from 'h3'

export type Handler = (ctx: { event: H3Event; params: Record<string, string>; query: Record<string, string>; body: Record<string, any> }) => unknown | Promise<unknown>

interface Route {
  method: string
  pattern: RegExp
  keys: string[]
  handler: Handler
}

export class MockRouter {
  private routes: Route[] = []

  private add(method: string, path: string, handler: Handler) {
    const keys: string[] = []
    const pattern = new RegExp(
      '^' +
        path
          .replace(/\//g, '\\/')
          .replace(/:([a-zA-Z_]+)/g, (_m, k) => {
            keys.push(k)
            return '([^\\/]+)'
          }) +
        '\\/?$',
    )
    this.routes.push({ method, pattern, keys, handler })
    return this
  }

  get(path: string, h: Handler) {
    return this.add('GET', path, h)
  }

  post(path: string, h: Handler) {
    return this.add('POST', path, h)
  }

  put(path: string, h: Handler) {
    return this.add('PUT', path, h)
  }

  patch(path: string, h: Handler) {
    return this.add('PATCH', path, h)
  }

  delete(path: string, h: Handler) {
    return this.add('DELETE', path, h)
  }

  match(method: string, path: string) {
    for (const r of this.routes) {
      if (r.method !== method) continue
      const m = r.pattern.exec(path)
      if (m) {
        const params: Record<string, string> = {}
        r.keys.forEach((k, i) => (params[k] = decodeURIComponent(m[i + 1])))
        return { handler: r.handler, params }
      }
    }
    return null
  }
}

export const apiError = (status: number, message: string, errors?: Record<string, string[]>, code?: string) =>
  createError({ statusCode: status, statusMessage: message, data: { message, errors, code } })

export const paginate = <T>(items: T[], query: Record<string, string>) => {
  const page = Math.max(1, Number(query.page ?? 1))
  const perPage = Math.min(100, Math.max(1, Number(query.per_page ?? 20)))
  const start = (page - 1) * perPage
  return {
    data: items.slice(start, start + perPage),
    meta: { current_page: page, per_page: perPage, total: items.length, last_page: Math.max(1, Math.ceil(items.length / perPage)) },
  }
}

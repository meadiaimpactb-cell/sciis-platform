/**
 * Nitro catch-all for the mock API (SPEC §6) — active only when NUXT_PUBLIC_API_MOCK=true.
 * The client points its baseURL at `/api/v1` in mock mode, so every request lands here.
 */
import { defineEventHandler, getQuery, readBody, setResponseHeader, getHeader, createError } from 'h3'
import { router } from '~/server/mock/handlers'

export default defineEventHandler(async (event) => {
  const config = useRuntimeConfig()
  if (String(config.public.apiMock) !== 'true') {
    throw createError({ statusCode: 404, statusMessage: 'Mock API disabled' })
  }
  const method = event.method.toUpperCase()
  const path = '/' + ((event.context.params?.path as string) ?? '').replace(/^\/+/, '')
  const match = router.match(method, path)
  setResponseHeader(event, 'X-Request-Id', getHeader(event, 'x-request-id') ?? `mock-${Date.now().toString(36)}`)
  setResponseHeader(event, 'X-Mock-Api', 'true')
  if (!match) {
    throw createError({ statusCode: 404, statusMessage: `Mock route not found: ${method} ${path}`, data: { message: `Mock route not found: ${method} ${path}`, code: 'not_found' } })
  }
  const query = Object.fromEntries(Object.entries(getQuery(event)).map(([k, v]) => [k, Array.isArray(v) ? String(v[0]) : String(v ?? '')]))
  let body: Record<string, unknown> = {}
  if (!['GET', 'HEAD'].includes(method)) {
    try {
      body = ((await readBody(event)) as Record<string, unknown>) ?? {}
    } catch {
      body = {}
    }
  }
  // small artificial latency so loading states are visible to the tester
  await new Promise((r) => setTimeout(r, 120))
  return match.handler({ event, params: match.params, query, body: body as Record<string, any> })
})

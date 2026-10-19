// Mock of Laravel Sanctum's CSRF bootstrap endpoint (GET /sanctum/csrf-cookie → sets XSRF-TOKEN cookie).
import { defineEventHandler, setCookie, setResponseStatus } from 'h3'

export default defineEventHandler((event) => {
  setCookie(event, 'XSRF-TOKEN', `mock-${Date.now().toString(36)}`, { path: '/', sameSite: 'lax', httpOnly: false })
  setResponseStatus(event, 204)
  return null
})

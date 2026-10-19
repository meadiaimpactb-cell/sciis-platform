/** Shared zod schemas (localized messages are injected via `makeSchemas(t)`). */
import { z } from 'zod'

export const SAUDI_PHONE = /^(\+9665\d{8}|05\d{8})$/

export const makeSchemas = (t: (k: string, p?: Record<string, unknown>) => string) => {
  const required = (v: string) => v.trim().length > 0
  const str = (min = 1) => z.string().min(min, min === 1 ? t('validation.required') : t('validation.minLength', { n: min }))
  const email = z.string().refine(required, t('validation.required')).refine((v) => /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v), t('validation.email'))
  const phone = z.string().refine(required, t('validation.required')).refine((v) => SAUDI_PHONE.test(v.replace(/\s/g, '')), t('validation.phone'))
  const password = z.string().min(8, t('validation.passwordMin'))
  const code6 = z.string().regex(/^\d{6}$/, t('validation.code6'))
  const accept = z.boolean().refine((v) => v === true, t('validation.mustAccept'))
  const nonEmptyArray = <T>(s: z.ZodType<T>) => z.array(s).min(1, t('validation.selectOne'))
  const url = z.string().url(t('validation.url')).or(z.literal(''))
  return { str, email, phone, password, code6, accept, nonEmptyArray, url, required }
}

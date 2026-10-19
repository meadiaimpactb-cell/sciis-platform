/**
 * Zod-backed form state. Maps zod issues and API field errors ({field: [msg]}) onto `errors`.
 */
import type { ZodType } from 'zod'
import { ApiRequestError } from '~/composables/useApi'

export const useZodForm = <T extends Record<string, unknown>>(schema: ZodType<T>, initial: T) => {
  const values = reactive({ ...initial }) as T
  const errors = ref<Record<string, string>>({})
  const submitting = ref(false)
  const touched = ref<Set<string>>(new Set())

  const validate = (): boolean => {
    const result = schema.safeParse(values)
    if (result.success) {
      errors.value = {}
      return true
    }
    const next: Record<string, string> = {}
    for (const issue of result.error.issues) {
      const path = issue.path.join('.')
      if (!next[path]) next[path] = issue.message
    }
    errors.value = next
    return false
  }

  const validateField = (name: string) => {
    touched.value.add(name)
    const result = schema.safeParse(values)
    if (result.success) {
      delete errors.value[name]
      return
    }
    const issue = result.error.issues.find((i) => i.path.join('.') === name)
    if (issue) errors.value[name] = issue.message
    else delete errors.value[name]
  }

  const applyApiError = (e: unknown) => {
    if (e instanceof ApiRequestError && e.errors) {
      const next: Record<string, string> = {}
      for (const [k, v] of Object.entries(e.errors)) next[k] = v[0]
      errors.value = { ...errors.value, ...next }
      return true
    }
    return false
  }

  const reset = (next?: Partial<T>) => {
    Object.assign(values, initial, next ?? {})
    errors.value = {}
    touched.value = new Set()
  }

  const submit = async (fn: (v: T) => Promise<void> | void) => {
    if (!validate()) return false
    submitting.value = true
    try {
      await fn(values)
      return true
    } catch (e) {
      if (!applyApiError(e)) throw e
      return false
    } finally {
      submitting.value = false
    }
  }

  return { values, errors, submitting, touched, validate, validateField, applyApiError, reset, submit }
}

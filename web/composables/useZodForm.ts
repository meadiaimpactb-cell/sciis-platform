/**
 * Form binding for every form of the platform — vee-validate (`useForm`) driven by a zod 4 schema from
 * utils/validation.ts (adapter `toTypedSchema`). Keeps the original API used by the pages:
 *   const form = useZodForm(schema, initial)
 *   <FormField v-model="form.values.email" name="email" :error="form.errors.value.email" />
 *   <AppButton type="submit" :loading="form.submitting.value">  ← disabled while submitting (no double submit)
 *   const submit = () => form.submit(async (v) => { … })
 *
 * Behaviour (standard verification layer):
 *  - validates on blur (FormField injects this form and calls `validateField(name)`) and on submit — not per keystroke;
 *  - API 422 field errors are injected under the same fields (`setErrors`); server keys that differ from the form's
 *    field names are mapped with `{ aliases: { organization_name: 'org_name' } }`;
 *  - a 422 error on a key the form doesn't render, and anything else (network, 403, suspended…), becomes an
 *    error toast, never a hidden field error;
 *  - 429 (rate limit) is a form-level message in `formError`, rendered by `<FormAlert />` next to the submit
 *    button (a warning toast only when the form renders no FormAlert);
 *  - `submit()` resolves `true` on success, `false` otherwise (pass `{ rethrow: true }` to handle errors yourself).
 */
import type { ZodType } from 'zod'
import { useForm } from 'vee-validate'
import type { InjectionKey, Ref } from 'vue'
import { parseApiError, toTypedSchema } from '~/utils/validation'
import { toastError, toastWarning } from '~/utils/notify'

export interface ZodFormContext {
  validateField: (name: string) => Promise<void>
  has: (name: string) => boolean
  /** Form-level message (429 rate limit) shown by `<FormAlert />`. */
  formError: Readonly<Ref<string | null>>
  /** `<FormAlert />` instances mounted for the form(s) of this component; 0 → the message falls back to a toast. */
  alerts: Ref<number>
}
export const ZOD_FORM_KEY: InjectionKey<ZodFormContext> = Symbol('sciis-zod-form')

export interface ZodFormOptions {
  /** server error key → form field name */
  aliases?: Record<string, string>
}

export const useZodForm = <T extends Record<string, unknown>>(schema: ZodType<T>, initial: T, options: ZodFormOptions = {}) => {
  // The schema is NOT handed to vee-validate: with fields bound by v-model (no `useField`), its initial "silent"
  // validation still writes an error for every unrendered path, so a fresh form would open in red. vee-validate keeps
  // the values and the error bag; the zod schema runs here, on blur and on submit only.
  const typed = toTypedSchema(schema)
  const form = useForm<T>({
    initialValues: structuredCloneSafe(initial) as never,
    keepValuesOnUnmount: true,
  })

  // vee-validate's values are the reactive source of truth and v-model writes straight into them. `useForm` only
  // exposes them as `readonly(formValues)` (writes are dropped with a Vue warning), so take the raw object back and
  // re-wrap it: `reactive()` returns vee-validate's own cached proxy.
  const values = reactive(toRaw(form.values)) as T
  const errors = computed<Record<string, string | undefined>>(() => form.errors.value as Record<string, string | undefined>)
  const submitting = ref(false)
  const formError = ref<string | null>(null)
  const touched = ref<Set<string>>(new Set())
  const { t } = useI18n()
  const covers = (names: string[], path: string) => names.some((n) => path === n || path.startsWith(`${n}.`))

  /** Dotted paths (`organization.name`, `founders.0.name`) are owned when every segment exists. */
  const has = (name: string) => {
    let cur: unknown = values
    for (const seg of name.split('.')) {
      if (cur === null || typeof cur !== 'object' || !(seg in (cur as object))) return false
      cur = (cur as Record<string, unknown>)[seg]
    }
    return true
  }

  /** Message of a page-level `.refine()` without `path` (no field to show it under) from the last `validate()`. */
  let rootError: string | undefined

  /** Whole form: every error is replaced by the current result. Resolves `true` when the values are valid. */
  const validate = async (): Promise<boolean> => {
    const res = await typed.parse(values as never)
    clearErrors()
    rootError = res.errors.find((e) => !e.path)?.errors[0]
    for (const e of res.errors) if (e.path) form.setFieldError(e.path as never, e.errors[0])
    return !res.errors.length
  }

  /** Validate one field against the whole schema (so cross-field refinements like password match work). */
  const validateField = async (name: string) => {
    touched.value.add(name)
    const res = await typed.parse(values as never)
    const err = res.errors.find((e) => e.path === name)
    form.setFieldError(name as never, err?.errors[0])
  }

  /**
   * Validate only some fields (multi-step forms: the current step). A name also covers its nested paths
   * (`founders` → `founders.0.name`). Errors of other fields are left untouched. Resolves `true` when they are valid.
   */
  const validateFields = async (names: string[]): Promise<boolean> => {
    const res = await typed.parse(values as never)
    for (const path of Object.keys(form.errors.value)) if (covers(names, path)) form.setFieldError(path as never, undefined)
    const hits = res.errors.filter((e) => covers(names, e.path ?? ''))
    for (const e of hits) form.setFieldError(e.path as never, e.errors[0])
    return !hits.length
  }

  /** Clear every error (or only the given fields and their nested paths). */
  const clearErrors = (names?: string[]) => {
    for (const path of Object.keys(form.errors.value)) if (!names || covers(names, path)) form.setFieldError(path as never, undefined)
  }

  /**
   * 422 → errors under the fields the form renders (keys mapped through `aliases`). Returns true when the error was
   * a validation error; keys the form doesn't own are shown as a toast so nothing is ever silently swallowed.
   */
  const applyApiError = (e: unknown): boolean => {
    const parsed = parseApiError(e)
    const entries = Object.entries(parsed.errors)
    if (!entries.length) return false
    const mapped: Record<string, string> = {}
    const orphans: string[] = []
    for (const [key, message] of entries) {
      const field = options.aliases?.[key] ?? key
      if (has(field) || has(field.split('.')[0])) mapped[field] = message
      else orphans.push(message)
    }
    if (Object.keys(mapped).length) form.setErrors(mapped as never)
    if (orphans.length) toastError(orphans[0])
    return true
  }

  /**
   * Back to the initial values (merged with `next`) with NO error shown. Not vee-validate's `resetForm()`: it
   * runs a silent validation on the next tick that flags every invalid field of an unrendered path (we bind
   * values directly, without `useField`), so a freshly reset form would light up red.
   */
  const reset = (next?: Partial<T>) => {
    const fresh = { ...structuredCloneSafe(initial), ...structuredCloneSafe((next ?? {}) as Partial<T>) } as Record<string, unknown>
    const target = values as Record<string, unknown>
    for (const k of Object.keys(target)) if (!(k in fresh)) delete target[k]
    for (const [k, v] of Object.entries(fresh)) target[k] = v
    clearErrors()
    touched.value = new Set()
  }

  const submit = async (fn: (v: T) => Promise<void> | void, opts: { rethrow?: boolean } = {}): Promise<boolean> => {
    if (submitting.value) return false
    formError.value = null
    if (!(await validate())) {
      toastError(rootError ?? t('validation.fixErrors'))
      return false
    }
    submitting.value = true
    try {
      await fn(values)
      return true
    } catch (e) {
      const parsed = parseApiError(e)
      if (parsed.status === 429) {
        // The server's text carries the wait time when it knows it ("try again in 42 seconds").
        formError.value = parsed.message || t('notify.tooMany')
        if (!alerts.value) toastWarning(formError.value)
        return false
      }
      if (applyApiError(e)) return false
      if (opts.rethrow) throw e
      toastError(parsed.message)
      return false
    } finally {
      submitting.value = false
    }
  }

  // Several forms can live in one component (multi-step register): register into a per-component list so a
  // FormField's blur validates the form(s) that actually own that field name.
  const inst = getCurrentInstance() as (ReturnType<typeof getCurrentInstance> & { __zodForms?: ZodFormContext[]; __zodAlerts?: Ref<number> }) | null
  const alerts = (inst ? (inst.__zodAlerts ??= ref(0)) : ref(0))
  const ctx: ZodFormContext = { validateField, has, formError, alerts }
  if (inst) {
    const forms = (inst.__zodForms ??= [])
    forms.push(ctx)
    if (forms.length === 1) {
      provide(ZOD_FORM_KEY, {
        has: (name) => forms.some((f) => f.has(name)),
        validateField: async (name) => {
          await Promise.all(forms.filter((f) => f.has(name)).map((f) => f.validateField(name)))
        },
        formError: computed(() => forms.map((f) => f.formError.value).find(Boolean) ?? null),
        alerts,
      })
    }
  }

  return { values, errors, submitting, formError, touched, validate, validateField, validateFields, clearErrors, applyApiError, reset, submit, setErrors: form.setErrors, setFieldValue: form.setFieldValue, vee: form }
}

/** Deep copy for initial values (arrays/objects must not be shared between resets). */
function structuredCloneSafe<V>(v: V): V {
  try {
    return structuredClone(toRaw(v))
  } catch {
    return JSON.parse(JSON.stringify(v)) as V
  }
}

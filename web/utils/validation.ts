/**
 * Central validation layer — EVERY validation rule of the platform is defined here once and imported elsewhere
 * (no regex / min / max scattered in components). Mirrored 1:1 by the Laravel requests.
 *
 *  - zod 4 (latest): the global localized error map (`z.config({ customError })`, `installZodLocale`) is installed
 *    when this module is first imported, so no English default message ever appears. Only form pages import it:
 *    keep it out of anything every page loads (API client, notify, layouts) — see utils/apiError.ts.
 *  - Messages resolve at validation time through i18n (`validation.*`), so Arabic/English follow the UI language.
 *  - Forms bind through vee-validate (`useZodForm` → `useForm` + `toTypedSchema` below).
 */
import { z } from 'zod'
import { isValidPhoneNumber, parsePhoneNumberFromString, type CountryCode } from 'libphonenumber-js'
import type { TypedSchema, TypedSchemaError } from 'vee-validate'

// ───────────────────────────── rules (single source of truth) ─────────────────────────────
export const RULES = {
  nameMin: 3,
  nameMax: 255,
  /** ACC-06 — same policy as the API (`Password::defaults()`): 10+, upper, lower, digit, symbol */
  passwordMin: 10,
  codeLength: 6,
  messageMin: 10,
  messageMax: 2000,
  defaultCountry: 'SA' as CountryCode,
  /** Saudi commercial registration / unified number */
  crPattern: /^\d{10}$/,
  /** RFC-ish e-mail (the server re-checks with Laravel's `email` rule) */
  emailPattern: /^[^@\s]+@[^@\s]+\.[^@\s]+$/,
  upper: /[A-Z]/,
  lower: /[a-z]/,
  digit: /\d/,
  symbol: /[^A-Za-z0-9]/,
  digitsOnly: /^\d+$/,
  e164: /^\+[1-9]\d{7,14}$/,
  /** 2FA recovery code (TwoFactorController::generateRecoveryCodes — 10 × [A-Z0-9]) */
  recoveryCode: /^[A-Z0-9]{10}$/i,
  recoveryCodeLength: 10,
  /** CMS slug (CmsContentRequest), taxonomy term key (TaxonomyTermRequest), criterion key, role name (RoleRequest) */
  slugPattern: /^[a-z0-9-]+$/,
  slugMax: 160,
  termKeyPattern: /^[a-z0-9_-]+$/,
  termKeyMax: 80,
  criterionKeyPattern: /^[a-z0-9_]+$/,
  criterionKeyMax: 60,
  rolePattern: /^[a-z][a-z0-9_]*$/,
  roleMin: 3,
  roleMax: 60,
  httpPrefix: /^https?:\/\//i,
  httpWww: /^https?:\/\/(www\.)?/i,
  // ── lengths mirrored from the Laravel FormRequests ──
  titleMin: 3,
  titleMax: 190,
  staffNameMax: 120,
  shortDescMin: 20,
  shortDescMax: 280,
  shortTextMax: 500,
  noteMin: 2,
  noteMax: 2000,
  descMax: 3000,
  longTextMax: 5000,
  answerMin: 5,
  answerMax: 5000,
  infoBodyMin: 10,
  infoBodyMax: 4000,
  minutesMin: 5,
  minutesMax: 10000,
  rationaleMin: 10,
  teaserMin: 20,
  teaserMax: 600,
  highlightsMax: 6,
  highlightMax: 160,
  completionItemsMax: 30,
  completionFieldMax: 80,
  completionNoteMax: 1000,
  /** NominateRequest: 1..10 investors per call */
  nominationsMax: 10,
  /** ReviewDocumentRequest / ScoreRequest notes */
  docNoteMax: 1000,
  indicatorNameMin: 3,
  founderNameMax: 120,
  founderRoleMax: 120,
  urlMax: 255,
  commitmentNoteMax: 500,
  weightMin: 0.1,
  weightMax: 10,
  scoreMin: 1,
  scoreMax: 5,
  scaleLevels: 5,
  experienceYearsMax: 80,
  priorInvestmentsMax: 1000,
  employeesMax: 1_000_000,
  beneficiariesMax: 100_000_000,
  foundersCountMax: 50,
  percentTotal: 100,
  participantsMax: 20,
  meetingActionsMax: 30,
  heroImagesMax: 3,
  // ── opportunity form (UpdateOpportunityRequest / StoreIndicatorRequest) ──
  orgNumberMax: 30,
  licenseNumberMax: 60,
  licenseStatusMax: 40,
  cityMax: 100,
  revenueModelTypeMax: 60,
  indicatorUnitMax: 60,
  teamSizeMax: 100_000,
  fundingMax: 999_999_999_999,
  fundsRowsMax: 20,
  fundingStep: 1000,
  urlPattern: /^https?:\/\/\S+\.\S+/i,
  /** profile picture (MeController::uploadAvatar) */
  avatarMaxMb: 3,
  avatarMime: /^image\/(png|jpe?g|webp)$/,
  /** CMS images (POST /admin/content/media → WebP): covers / hero accept GIF too, logos don't */
  cmsImageMaxMb: 5,
  cmsImageMime: /^image\/(png|jpe?g|webp|gif)$/,
  /** CMS downloadable resources (POST /admin/content/files) */
  cmsFileMaxMb: 10,
  /** CmsContentRequest lengths */
  cmsUrlMax: 500,
  cmsLocationMax: 255,
  cmsAuthorNameMax: 120,
  cmsAuthorTitleMax: 160,
  cmsSectorMax: 120,
  /** resend cooldown for OTP codes (config sciis.otp.resend_cooldown_seconds) */
  otpResendSeconds: 60,
  /** investor profile (UpdateProfileRequest) + the same minimums as the registration wizard */
  investorCountryMax: 80,
  investorMentoringMax: 1000,
  investorExperienceDescMin: 10,
  investorConflictsMin: 2,
  /** IMP-03 — IRIS+ catalogue code (StoreIndicatorRequest::IRIS_CODE_PATTERN), e.g. PI1234 / OI4060 */
  irisCodePattern: /^(OD|PD|OI|PI|FP)\d{4}$/,
  irisCodeLength: 6,
} as const

// ───────────────────────────── i18n bridge ─────────────────────────────
type Translate = (key: string, params?: Record<string, unknown>) => string
/** Default: the app's i18n at call time (current locale); replaceable via installZodLocale (tests, other hosts). */
let translate: Translate = (key, params) => {
  try {
    return (useNuxtApp().$i18n as { t: (k: string, p?: Record<string, unknown>) => string }).t(key, params ?? {})
  } catch {
    return key
  }
}
/** Messages are looked up when a value is validated, so a language switch applies immediately. */
const msg = (key: string, params?: Record<string, unknown>) => translate(`validation.${key}`, params)

/**
 * Global localized error map (zod 4 API). Installed when this module is first imported — i.e. only by pages that
 * render forms — so zod and libphonenumber stay out of the bundle every page loads. Pass `t` to override i18n.
 */
export function installZodLocale(t?: Translate) {
  if (t) translate = t
  z.config({
    customError: (iss) => {
      switch (iss.code) {
        case 'invalid_type':
          return iss.input === undefined || iss.input === null || (typeof iss.input === 'number' && Number.isNaN(iss.input)) ? msg('required') : msg('invalid')
        case 'too_small':
          if (iss.origin === 'string') return Number(iss.minimum) <= 1 ? msg('required') : msg('minLength', { n: iss.minimum })
          if (iss.origin === 'number' || iss.origin === 'int') return msg('minValue', { n: iss.minimum })
          if (iss.origin === 'array' || iss.origin === 'set') return msg('selectOne')
          return msg('tooSmall')
        case 'too_big':
          if (iss.origin === 'string') return msg('maxLength', { n: iss.maximum })
          if (iss.origin === 'number' || iss.origin === 'int') return msg('maxValue', { n: iss.maximum })
          return msg('tooBig')
        case 'invalid_format':
          if (iss.format === 'email') return msg('email')
          if (iss.format === 'url') return msg('url')
          return msg('invalid')
        case 'invalid_value':
          return msg('invalidChoice')
        default:
          return undefined
      }
    },
  })
}

installZodLocale()

// ───────────────────────────── phone (libphonenumber) ─────────────────────────────
/** Any spelling (05…, 5…, 9665…, 009665…, +1…) → E.164, or '' when it isn't a valid number. */
export function normalizePhone(raw: string, country: CountryCode = RULES.defaultCountry): string {
  const v = (raw ?? '').trim()
  if (!v) return ''
  const parsed = parsePhoneNumberFromString(v.replace(/^00/, '+'), country)
  return parsed?.isValid() ? parsed.number : ''
}
export const isValidPhone = (raw: string, country: CountryCode = RULES.defaultCountry) => !!raw && isValidPhoneNumber(raw.replace(/^00/, '+'), country)

// ───────────────────────────── ready-made fields ─────────────────────────────
const nonEmpty = (key = 'required') => z.string({ error: () => msg(key) }).trim().min(1, { error: () => msg(key) })

export const nameField = nonEmpty('nameRequired')
  .min(RULES.nameMin, { error: () => msg('nameShort', { n: RULES.nameMin }) })
  .max(RULES.nameMax, { error: () => msg('maxLength', { n: RULES.nameMax }) })

export const emailField = nonEmpty('emailRequired').regex(RULES.emailPattern, { error: () => msg('email') })

export const passwordField = z
  .string({ error: () => msg('passwordRequired') })
  .min(1, { error: () => msg('passwordRequired') })
  .min(RULES.passwordMin, { error: () => msg('passwordRule') })
  .regex(RULES.upper, { error: () => msg('passwordRule') })
  .regex(RULES.lower, { error: () => msg('passwordRule') })
  .regex(RULES.digit, { error: () => msg('passwordRule') })
  .regex(RULES.symbol, { error: () => msg('passwordRule') })

/** Login only checks presence — the policy applies when a password is SET. */
export const currentPasswordField = z.string({ error: () => msg('passwordRequired') }).min(1, { error: () => msg('passwordRequired') })

export const passwordConfirmField = z.string({ error: () => msg('passwordConfirmRequired') }).min(1, { error: () => msg('passwordConfirmRequired') })

/** 6-digit OTP / TOTP code. */
export const codeField = nonEmpty('codeRequired')
  .length(RULES.codeLength, { error: () => msg('code6') })
  .regex(RULES.digitsOnly, { error: () => msg('codeDigits') })

/** Required text with a field-specific label: requiredString('auth.register.city', RULES.titleMin, RULES.titleMax). */
export const requiredString = (labelKey: string, min = 1, max?: number) => {
  const base = z
    .string({ error: () => msg('requiredNamed', { field: translate(labelKey) }) })
    .trim()
    .min(1, { error: () => msg('requiredNamed', { field: translate(labelKey) }) })
    .min(min, { error: () => msg('minLength', { n: min }) })
  return max ? base.max(max, { error: () => msg('maxLength', { n: max }) }) : base
}

/** Mobile number — validated with libphonenumber; the form stores E.164 (`+9665…`). */
export const phoneField = nonEmpty('phoneRequired').refine((v) => isValidPhone(v), { error: () => msg('phone') })
export const optionalPhoneField = z
  .string()
  .trim()
  .refine((v) => v === '' || isValidPhone(v), { error: () => msg('phone') })
  .optional()
  .or(z.literal(''))

export const optionalUrlField = z.string().trim().refine((v) => v === '' || /^https?:\/\/\S+\.\S+/.test(v), { error: () => msg('url') }).optional()
export const acceptField = z.boolean().refine((v) => v === true, { error: () => msg('mustAccept') })
export const crField = nonEmpty('required').regex(RULES.crPattern, { error: () => msg('cr') })
export const messageField = nonEmpty('required')
  .min(RULES.messageMin, { error: () => msg('minLength', { n: RULES.messageMin }) })
  .max(RULES.messageMax, { error: () => msg('maxLength', { n: RULES.messageMax }) })
export const atLeastOne = <T extends z.ZodType>(item: T) => z.array(item).min(1, { error: () => msg('selectOne') })
/** Array with `min`..`max` items (meeting participants 1..20, completion items 1..30, nominations 1..10). */
export const listOf = <T extends z.ZodType>(item: T, min: number, max: number) =>
  z
    .array(item)
    .min(min, { error: () => (min <= 1 ? msg('selectOne') : msg('minItems', { n: min })) })
    .max(max, { error: () => msg('maxItems', { n: max }) })
/** Generic text with a minimum length (non-required fields pass `0`). */
export const requiredText = (min = 1) => (min > 0 ? nonEmpty('required').min(min, { error: () => msg('minLength', { n: min }) }) : z.string())

/** `{ password, password_confirmation }` pair with the match check on the confirmation field. */
export const withPasswordConfirmation = <T extends z.ZodRawShape>(shape: T, field = 'password', confirm = 'password_confirmation') =>
  z.object({ ...shape, [field]: passwordField, [confirm]: passwordConfirmField }).refine((v) => (v as Record<string, unknown>)[field] === (v as Record<string, unknown>)[confirm], {
    path: [confirm],
    error: () => msg('passwordMatch'),
  })

// ───────────────────────────── more ready-made fields (admin / portal forms) ─────────────────────────────
/** Lazily translated `validation.*` message — for the rare page-level `.refine()` (messages must resolve at validation time). */
export const vmsg = (key: string, params?: Record<string, unknown>) => msg(key, params)

/** Optional free text with a max length; accepts '' / null / undefined. */
export const optionalText = (max: number = RULES.noteMax) =>
  z
    .string()
    .trim()
    .max(max, { error: () => msg('maxLength', { n: max }) })
    .nullish()

/** IMP-03 — normalises an IRIS+ code the way the API does ("pi 1234" → "PI1234"). */
export const normalizeIrisCode = (v: string | null | undefined): string => (v ?? '').replace(/\s+/g, '').toUpperCase()

/** IMP-03 — optional IRIS+ code; blank is fine, otherwise OD|PD|OI|PI|FP + 4 digits. */
export const irisCodeField = z
  .string()
  .nullish()
  .refine((v) => !normalizeIrisCode(v) || RULES.irisCodePattern.test(normalizeIrisCode(v)), { error: () => msg('irisCode') })

/** A required select / radio value (string or number id) with a field-specific label. */
export const requiredChoice = (labelKey?: string) =>
  z.custom<string | number>((v) => v !== '' && v !== null && v !== undefined && !(typeof v === 'number' && Number.isNaN(v)), {
    error: () => (labelKey ? msg('requiredNamed', { field: translate(labelKey) }) : msg('required')),
  })
/** Same rule as `requiredChoice`, typed for selects whose empty value is `null` (FormField select emits null). */
export const requiredSelect = (labelKey?: string) =>
  z.custom<string | number | null>((v) => v !== '' && v !== null && v !== undefined && !(typeof v === 'number' && Number.isNaN(v)), {
    error: () => (labelKey ? msg('requiredNamed', { field: translate(labelKey) }) : msg('required')),
  })

/** Optional number (FormField type="number" emits `null` when empty). */
export const optionalNumber = (opts: { min?: number; max?: number; int?: boolean } = {}) => {
  let n = z.number({ error: () => msg('number') })
  if (opts.int) n = n.int({ error: () => msg('number') })
  if (opts.min !== undefined) n = n.min(opts.min, { error: () => msg('minValue', { n: opts.min }) })
  if (opts.max !== undefined) n = n.max(opts.max, { error: () => msg('maxValue', { n: opts.max }) })
  return n.nullish()
}

/** Required number with a field-specific label. */
export const requiredNumber = (labelKey: string, opts: { min?: number; max?: number; int?: boolean } = {}) => {
  const req = () => msg('requiredNamed', { field: translate(labelKey) })
  let n = z.number({ error: (iss) => (iss.input === null || iss.input === undefined || iss.input === '' ? req() : msg('number')) })
  if (opts.int) n = n.int({ error: () => msg('number') })
  if (opts.min !== undefined) n = n.min(opts.min, { error: () => msg('minValue', { n: opts.min }) })
  if (opts.max !== undefined) n = n.max(opts.max, { error: () => msg('maxValue', { n: opts.max }) })
  return n
}

/** Required date / datetime-local value. */
export const requiredDate = (labelKey: string) => requiredString(labelKey)
/** Optional `datetime-local` / `date` value that must be in the future when set. */
export const futureDate = (labelKey?: string) =>
  (labelKey ? requiredString(labelKey) : z.string()).refine((v) => !v || new Date(v).getTime() > Date.now(), { error: () => msg('futureDate') })

/** Cross-field rule: `end` must be after `start` (both `YYYY-MM-DD[THH:mm]`, empty values are skipped). */
export const endsAfter = <S extends z.ZodType<Record<string, unknown>>>(schema: S, start: string, end: string) =>
  schema.refine((v) => !v[start] || !v[end] || new Date(String(v[end])).getTime() > new Date(String(v[start])).getTime(), { path: [end], error: () => msg('endAfterStart') })

/** Cross-field rule: two fields must differ (e.g. the two evaluators). */
export const mustDiffer = <S extends z.ZodType<Record<string, unknown>>>(schema: S, a: string, b: string) =>
  schema.refine((v) => !v[a] || !v[b] || v[a] !== v[b], { path: [b], error: () => msg('distinct') })

/** Optional CMS slug (lower-case, digits, dashes). */
export const optionalSlugField = z
  .string()
  .trim()
  .max(RULES.slugMax, { error: () => msg('maxLength', { n: RULES.slugMax }) })
  .refine((v) => v === '' || RULES.slugPattern.test(v), { error: () => msg('slug') })

/** Machine keys: taxonomy term, evaluation criterion, role name. */
export const termKeyField = nonEmpty('required')
  .max(RULES.termKeyMax, { error: () => msg('maxLength', { n: RULES.termKeyMax }) })
  .regex(RULES.termKeyPattern, { error: () => msg('key') })
export const criterionKeyField = nonEmpty('required')
  .max(RULES.criterionKeyMax, { error: () => msg('maxLength', { n: RULES.criterionKeyMax }) })
  .regex(RULES.criterionKeyPattern, { error: () => msg('keyUnderscore') })
export const roleNameField = nonEmpty('required')
  .min(RULES.roleMin, { error: () => msg('minLength', { n: RULES.roleMin }) })
  .max(RULES.roleMax, { error: () => msg('maxLength', { n: RULES.roleMax }) })
  .regex(RULES.rolePattern, { error: () => msg('roleName') })

/** Login step 2 / 2FA disable: a 6-digit TOTP OR a 10-character recovery code. */
export const twoFactorCodeField = nonEmpty('codeRequired').refine((v) => (RULES.digitsOnly.test(v) && v.length === RULES.codeLength) || RULES.recoveryCode.test(v), {
  error: () => msg('codeOrRecovery'),
})

/** Required name for staff users (StoreStaffUserRequest: 3..120). */
export const staffNameField = nonEmpty('nameRequired')
  .min(RULES.nameMin, { error: () => msg('nameShort', { n: RULES.nameMin }) })
  .max(RULES.staffNameMax, { error: () => msg('maxLength', { n: RULES.staffNameMax }) })

/** Rows of `{percent}` must add up to 100 when any row exists (use of funds). */
export const percentTotalOk = (rows: { percent?: number | string | null; item?: string }[] | undefined) => {
  const used = (rows ?? []).filter((r) => (r.item ?? '').trim() !== '' || Number(r.percent) > 0)
  return !used.length || used.reduce((a, r) => a + (Number(r.percent) || 0), 0) === RULES.percentTotal
}

/** Image upload pre-check (type + size). Returns a translated message, or '' when the file is fine. */
export function imageFileError(file: File, maxMb: number = RULES.avatarMaxMb, mime: RegExp = RULES.avatarMime): string {
  if (!mime.test(file.type)) return msg('imageType')
  if (file.size > maxMb * 1024 * 1024) return msg('fileTooLarge', { n: maxMb })
  return ''
}

/** "linkedin.com/in/x" → "https://linkedin.com/in/x" (the API validates full URLs). */
export const withProtocol = (v: string | null | undefined): string | null => {
  const s = (v ?? '').trim()
  if (!s) return null
  return RULES.httpPrefix.test(s) ? s : `https://${s}`
}
/**
 * Optional URL with a max length. `loose` accepts a missing scheme ("linkedin.com/in/x") because the payload adds
 * `https://` with `withProtocol()` before sending.
 */
export const optionalUrl = (max: number = RULES.urlMax, loose = false) =>
  z
    .string()
    .trim()
    .max(max, { error: () => msg('maxLength', { n: max }) })
    .refine((v) => v === '' || RULES.urlPattern.test(loose ? (withProtocol(v) ?? '') : v), { error: () => msg('url') })
    .nullish()

/** Display form of a URL without the scheme / www. */
export const displayUrl = (v: string | null | undefined): string => (v ?? '').replace(RULES.httpWww, '')

/** Arabic-Indic (٠-٩) / Persian (۰-۹) digits → Latin, keeping only what a code may contain (OTP / TOTP / recovery). */
export const toLatinDigits = (v: string | null | undefined): string =>
  (v ?? '').replace(/[٠-٩۰-۹]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d) >= 0 ? '٠١٢٣٤٥٦٧٨٩'.indexOf(d) : '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))).replace(/\s+/g, '')

/** Live password checklist (shown under the field while typing). */
export function passwordChecks(v: string) {
  return {
    length: (v ?? '').length >= RULES.passwordMin,
    upper: RULES.upper.test(v ?? ''),
    lower: RULES.lower.test(v ?? ''),
    number: RULES.digit.test(v ?? ''),
    symbol: RULES.symbol.test(v ?? ''),
  }
}

// ───────────────────────────── vee-validate adapter (zod 4) ─────────────────────────────
/**
 * vee-validate TypedSchema for a zod 4 schema (the official @vee-validate/zod targets zod 3 only).
 * Errors are grouped by dotted path, first message per issue order.
 */
export function toTypedSchema<S extends z.ZodType>(schema: S): TypedSchema<z.input<S>, z.output<S>> {
  return {
    __type: 'VVTypedSchema',
    async parse(values) {
      const result = await schema.safeParseAsync(values)
      if (result.success) return { value: result.data, errors: [] }
      const grouped: Record<string, string[]> = {}
      for (const issue of result.error.issues) {
        const path = issue.path.map(String).join('.')
        ;(grouped[path] ??= []).push(issue.message)
      }
      const errors: TypedSchemaError[] = Object.entries(grouped).map(([path, errs]) => ({ path, errors: errs }))
      return { errors }
    },
    cast(values) {
      return values as z.input<S>
    },
  }
}

// ───────────────────────────── server errors ─────────────────────────────
/** Kept here for existing imports; lives in utils/apiError.ts so the always-loaded API client doesn't pull in zod. */
export { parseApiError } from '~/utils/apiError'

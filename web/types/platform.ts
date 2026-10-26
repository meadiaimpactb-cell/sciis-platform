/**
 * FRM-01 (additional questions), UX-05 / BRD-04 (display preferences), ACC-09 (identity verification) — API shapes.
 */

/** FRM-01 — the three forms that carry admin-defined questions. */
export type FormKey = 'investor_profile' | 'opportunity' | 'membership_review'
export const FORM_KEYS: readonly FormKey[] = ['investor_profile', 'opportunity', 'membership_review']

export type QuestionType = 'short_text' | 'long_text' | 'number' | 'single_choice' | 'multiple_choice' | 'yes_no' | 'date'
export const QUESTION_TYPES: readonly QuestionType[] = ['short_text', 'long_text', 'number', 'single_choice', 'multiple_choice', 'yes_no', 'date']

export interface QuestionOption {
  value: string
  label_ar: string
  label_en?: string | null
}

/** One question of a published schema (FormSchemaService). `show_when` only points to an earlier question. */
export interface FormQuestion {
  id: string
  type: QuestionType
  label_ar: string
  label_en?: string | null
  help_ar?: string | null
  help_en?: string | null
  required: boolean
  active: boolean
  options: QuestionOption[]
  show_when: { question: string; equals: string } | null
}

/** GET /forms/{key} (public keys) — active questions of the published version (0 = nothing published). */
export interface PublicFormSchema {
  key: FormKey
  version: number
  questions: FormQuestion[]
}

/** GET /admin/forms/{key} — builder view (inactive questions included) + version history. */
export interface AdminFormSchema extends PublicFormSchema {
  published_at: string | null
  versions: { version: number; is_active: boolean; questions_count: number; published_at: string | null; published_by: string | null }[]
  limits: { max_questions: number; max_options: number; short_text_max: number; long_text_max: number }
}

/** GET /admin/forms — one row per form key. */
export interface AdminFormSummary {
  key: FormKey
  version: number
  questions_count: number
  active_count: number
  published_at: string | null
}

export type AnswerValue = string | number | boolean | string[] | null
export type AnswerMap = Record<string, AnswerValue>

/** Stored answers as resources return them (`extra_answers` on profile / opportunity / membership). */
export interface ExtraAnswers {
  key: FormKey
  version: number
  current_version: number
  answered_at: string | null
  answers: AnswerMap
  items: {
    id: string
    type: QuestionType
    label_ar: string
    label_en?: string | null
    value: AnswerValue
    choices: { ar: string; en?: string | null }[]
  }[]
}

/** UX-05 / BRD-04 — `preferences` on GET /me; PATCH /me/preferences. */
export type CalendarPref = 'gregorian' | 'hijri' | 'both'
export type ThemePref = 'system' | 'light' | 'dark'
export interface DisplayPreferences {
  calendar: CalendarPref
  theme: ThemePref
}

/** ACC-09 — GET /me/identity-verification. */
export interface IdentityVerificationState {
  available: boolean
  driver: string | null
  verified: boolean
  verified_at: string | null
  pending: IdentityVerificationRequest | null
}
export interface IdentityVerificationRequest {
  uuid: string
  status: 'pending' | 'approved' | 'rejected' | 'expired' | 'failed'
  /** the number to pick in the Nafath app (pending only) */
  random: string | null
  expires_at: string | null
  completed_at?: string | null
  verified?: boolean
}

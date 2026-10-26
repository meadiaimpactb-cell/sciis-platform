/** RPT-03..06, journey 11.10, ADM-08/09, EVL-04 — reporting & admin productivity contracts (Laravel API). */

export type Sensitivity = 'public' | 'internal' | 'confidential' | 'sensitive_personal'

export interface ChartPoint { label: string; value: number; key?: string; color?: string }

/** RPT-04 builder catalog (GET /admin/report-builder). */
export interface DatasetColumn { key: string; label: string; sensitivity: Sensitivity; groupable: boolean; allowed: boolean }
export interface DatasetDef {
  key: string
  label: string
  columns: DatasetColumn[]
  default_columns: string[]
  date_fields: { key: string; label: string }[]
  filters: Record<string, { value: string; label: string }[]>
  domain_access: boolean
}
export interface BuilderCatalog { datasets: DatasetDef[]; can_export: boolean; max_export_rows: number; preview_rows: number }

export interface ReportDefinition {
  columns: string[]
  filters: Record<string, string | string[] | boolean | null | undefined>
  group_by: string | null
  sort?: string | null
  dir?: 'asc' | 'desc' | null
}

export interface RecordLink { type: 'opportunity' | 'membership' | 'user'; id: string | number }
export type ResultRow = Record<string, unknown> & { _link?: RecordLink | null }

/** Builder preview / drill-down result. */
export interface DatasetResult {
  dataset: string
  title: string
  columns: { key: string; label: string }[]
  rows: ResultRow[]
  total: number
  grouped: boolean
  truncated: boolean
  series: ChartPoint[]
  report?: string
  segment?: string
  limit?: number
  name?: string
}

/** RPT-04/05 saved report. */
export interface SavedReport {
  uuid: string
  name: string
  dataset: string
  dataset_label: string
  definition: ReportDefinition
  created_by: { id: number; name: string } | null
  can_edit: boolean
  schedule: { frequency: 'weekly' | 'monthly' | null; format: 'xlsx' | 'pdf'; recipients: { id: number; name: string | null }[]; next_run_at: string | null; last_sent_at: string | null }
  updated_at: string | null
}

/** Journey 11.10 periodic report. */
export type PeriodicStatus = 'draft' | 'approved' | 'published'
export interface PeriodicKpi { key: string; title: string; kind: 'bars' | 'funnel' | 'number' | 'table'; unit: string | null; series: ChartPoint[] }
export interface PeriodicReport {
  uuid: string
  period_type: 'monthly' | 'quarterly' | 'annual'
  period_start: string
  period_end: string
  title: string
  summary: string | null
  status: PeriodicStatus
  audience: 'staff' | 'public'
  review_note: string | null
  generated_by: { id: number; name: string } | null
  generated_at: string | null
  approved_by: { id: number; name: string } | null
  approved_at: string | null
  published_by: { id: number; name: string } | null
  published_at: string | null
  kpis: PeriodicKpi[] | null
  actions: { edit: boolean; approve: boolean; return: boolean; publish: boolean; unpublish: boolean }
}

/** ADM-08 bulk result: records updated + skipped ones with a localised reason. */
export interface BulkResult { updated: number; skipped: { id: string; reason: string; label: string }[] }
export interface ReviewerOption { id: number; name: string; open: number }

/** EVL-04 advisory referral. */
export interface AdvisoryReferral {
  id: number
  opportunity: { uuid: string; reference_no: string | null; name: string } | null
  advisor: { id: number; name: string | null }
  referred_by: { id: number; name: string } | null
  question: string
  expires_at: string | null
  is_active: boolean
  opinion: string | null
  opined_at: string | null
  created_at: string | null
}

/** ADM-09 evaluator suggestion. */
export interface EvaluatorSuggestion { id: number; name: string; open_assignments: number }

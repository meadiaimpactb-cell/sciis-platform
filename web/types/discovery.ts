/** Investor discovery & engagement — OPP-07..09, OPP-12 (Laravel `SavedFilterController`, `InvestorNoteController`, `OpportunityCompareController`). */
import type { FundingDisplay, TaxonomyTerm, TermLike } from '~/types/api'
import type { ProjectStage } from '~/types/enums'

/** Catalogue facets (`filter[...]`) — taxonomy keys; `stage` is the enum value. */
export type BrowseFacet = 'sector' | 'region' | 'stage' | 'type' | 'range' | 'beneficiary' | 'sdg'
export const BROWSE_FACETS: BrowseFacet[] = ['sector', 'region', 'stage', 'type', 'range', 'beneficiary', 'sdg']

/** OPP-08 — what a saved search stores (unknown keys are dropped by the API). */
export type SavedSearchFilters = Partial<Record<BrowseFacet, string>> & { search?: string; nominated?: boolean; sort?: 'newest' | 'impact' | 'need' }

export interface SavedSearch {
  id: number
  name: string
  filters: SavedSearchFilters
  /** OPP-07 — alert when a newly listed opportunity matches */
  notify: boolean
  /** opportunities the search finds right now */
  count: number
  created_at: string | null
  updated_at: string | null
}

/** GET /investor/saved-filters */
export interface SavedSearchesResponse {
  filters: SavedSearch[]
  /** OPP-07 — "any new opportunity matching my profile" */
  profile_alerts: boolean
  max: number
}

/** OPP-09 — a private note (author only). */
export interface InvestorNote {
  id: number
  body: string
  tags: string[]
  created_at: string | null
  updated_at: string | null
}

/** OPP-12 — one comparison column (public fields only). */
export interface CompareItem {
  uuid: string
  reference_no?: string | null
  name: string
  organization_name?: string | null
  founded_year?: number | null
  sector: TaxonomyTerm | TermLike | null
  stage: ProjectStage
  regions: (TaxonomyTerm | TermLike)[]
  investment_type_keys?: string[]
  investment_types: (TaxonomyTerm | TermLike)[]
  teaser_summary: string
  short_description?: string | null
  beneficiaries_target: number | null
  beneficiaries_current: number | null
  beneficiary_groups: (TaxonomyTerm | TermLike)[]
  sdgs: (TaxonomyTerm | TermLike)[]
  primary_indicator: { name: string; unit: string | null; baseline: number | null; target: number | null } | null
  revenue_state: string | null
  revenue_state_label?: string | null
  product_stage: string | null
  product_stage_label?: string | null
  funding_display: FundingDisplay
  funding_band?: TermLike | null
  funding_amount?: number | null
  published_at?: string | null
}

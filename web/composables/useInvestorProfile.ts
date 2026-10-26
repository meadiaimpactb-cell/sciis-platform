/**
 * Investor profile normaliser (INV-01). The Laravel API is sectioned (basics / interests / experience /
 * declarations, taxonomy terms as `{id,key,label}`); the web forms (and the mock API) use a flat model with
 * `sectors: number[]`, `stages: string[]` (enum keys), `investment_types: string[]`, `regions/causes: number[]`.
 *
 *  - `fromApi()`   API (nested or already flat) → flat form model
 *  - `toPayload()` flat form model → `PUT /investor/profile` body (stage / type keys → taxonomy ids via the store)
 */
import type { InvestorProfile, InvestorProfileApi, InvestorProfilePayload } from '~/types/api'
import type { InvestmentType, ProjectStage, RiskAppetite } from '~/types/enums'

export const EMPTY_INVESTOR_PROFILE: InvestorProfile = {
  country: null,
  city: null,
  employer: null,
  job_title: null,
  linkedin_url: null,
  experience_years: null,
  experience_desc: null,
  prior_investments_count: null,
  prior_investments_desc: null,
  has_social_investments: false,
  social_investments_desc: null,
  risk_appetite: null,
  mentoring_available: false,
  mentoring_areas: null,
  investment_range_id: null,
  annual_investment_band_id: null,
  preferred_contact: null,
  how_heard_id: null,
  conflicts_disclosure: null,
  commit_meetings: false,
  sectors: [],
  stages: [],
  investment_types: [],
  regions: [],
  causes: [],
  completion_percent: 0,
  missing_items: [],
}

export const isNestedProfile = (p: unknown): p is InvestorProfileApi => !!p && typeof p === 'object' && 'basics' in (p as object) && 'interests' in (p as object)

export const useInvestorProfile = () => {
  const tax = useTaxonomyStore()

  const keysToIds = (group: 'project_stages' | 'investment_types', keys: string[] | undefined): number[] =>
    (keys ?? []).map((k) => tax.byKey(group, k)?.id).filter((id): id is number => typeof id === 'number')

  /** Nested Laravel resource (or a flat mock object) → flat form model. */
  const fromApi = (p: InvestorProfileApi | InvestorProfile | null | undefined): InvestorProfile => {
    if (!p) return { ...EMPTY_INVESTOR_PROFILE }
    if (!isNestedProfile(p)) return { ...EMPTY_INVESTOR_PROFILE, ...p }
    const { basics = {}, interests, experience = {}, declarations = {} } = p as InvestorProfileApi
    const i = interests ?? ({} as Partial<InvestorProfileApi['interests']>)
    return {
      country: basics.country ?? null,
      city: basics.city ?? null,
      employer: basics.employer ?? null,
      job_title: basics.job_title ?? null,
      linkedin_url: basics.linkedin_url ?? null,
      preferred_contact: basics.preferred_contact ?? null,
      how_heard_id: basics.how_heard?.id ?? null,
      sectors: (i.sectors ?? []).map((t) => t.id),
      stages: (i.stages ?? []).map((t) => t.key as ProjectStage),
      investment_types: (i.investment_types ?? []).map((t) => t.key as InvestmentType),
      regions: (i.regions ?? []).map((t) => t.id),
      causes: (i.causes ?? []).map((t) => t.id),
      risk_appetite: (i.risk_appetite as RiskAppetite | null | undefined) ?? null,
      investment_range_id: i.investment_range?.id ?? null,
      annual_investment_band_id: i.annual_investment_band?.id ?? null,
      experience_years: experience.experience_years ?? null,
      experience_desc: experience.experience_desc ?? null,
      prior_investments_count: experience.prior_investments_count ?? null,
      prior_investments_desc: experience.prior_investments_desc ?? null,
      has_social_investments: !!experience.has_social_investments,
      social_investments_desc: experience.social_investments_desc ?? null,
      mentoring_available: !!experience.mentoring_available,
      mentoring_areas: experience.mentoring_areas ?? null,
      conflicts_disclosure: declarations.conflicts_disclosure ?? null,
      commit_meetings: !!declarations.commit_meetings,
      completion_percent: p.completion_percent ?? 0,
      missing_items: p.missing_items ?? [],
      // INV-01 / INV-02
      impact_policy: experience.impact_policy ?? null,
      cv_document_uuid: experience.cv_document_uuid ?? null,
      data_accuracy_declared_at: declarations.data_accuracy_declared_at ?? null,
      documents: (p as InvestorProfileApi).documents ?? [],
      updated_at: (p as InvestorProfileApi).updated_at ?? null,
      extra_answers: (p as InvestorProfileApi).extra_answers ?? null, // FRM-01
    }
  }

  const num = (v: unknown): number | null => (v === '' || v === null || v === undefined ? null : Number.isFinite(Number(v)) ? Number(v) : null)
  const str = (v: unknown): string | null => (v === undefined || v === null || v === '' ? null : String(v))

  /** Flat form model → sectioned PUT payload. `sections` limits the output (e.g. only the section being edited). */
  const toPayload = (f: Partial<InvestorProfile>, sections: ('basics' | 'interests' | 'experience' | 'declarations')[] = ['basics', 'interests', 'experience', 'declarations']): InvestorProfilePayload => {
    const out: InvestorProfilePayload = {}
    if (sections.includes('basics')) {
      out.basics = {
        country: str(f.country),
        city: str(f.city),
        employer: str(f.employer),
        job_title: str(f.job_title),
        linkedin_url: str(f.linkedin_url),
        preferred_contact: (f.preferred_contact ?? null) as 'email' | 'phone' | 'whatsapp' | null,
        how_heard_id: num(f.how_heard_id),
      }
    }
    if (sections.includes('interests')) {
      out.interests = {
        sector_ids: (f.sectors ?? []).map(Number),
        stage_ids: keysToIds('project_stages', f.stages),
        investment_type_ids: keysToIds('investment_types', f.investment_types),
        region_ids: (f.regions ?? []).map(Number),
        cause_ids: (f.causes ?? []).map(Number),
        risk_appetite: (f.risk_appetite as RiskAppetite | null | undefined) ?? null,
        investment_range_id: num(f.investment_range_id),
        annual_investment_band_id: num(f.annual_investment_band_id),
      }
    }
    if (sections.includes('experience')) {
      out.experience = {
        experience_years: num(f.experience_years),
        experience_desc: str(f.experience_desc),
        prior_investments_count: num(f.prior_investments_count),
        prior_investments_desc: str(f.prior_investments_desc),
        has_social_investments: !!f.has_social_investments,
        social_investments_desc: str(f.social_investments_desc),
        mentoring_available: !!f.mentoring_available,
        mentoring_areas: str(f.mentoring_areas),
        // INV-01 — institutions only; sent only when the form carries it so other forms never clear it.
        ...('impact_policy' in f ? { impact_policy: str(f.impact_policy) } : {}),
      }
    }
    if (sections.includes('declarations')) {
      out.declarations = { conflicts_disclosure: str(f.conflicts_disclosure), commit_meetings: !!f.commit_meetings }
    }
    return out
  }

  return { fromApi, toPayload, isNestedProfile }
}

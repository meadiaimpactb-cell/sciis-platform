/**
 * PRJ-01..03 / IMP-02 / ENT-10 — follow-up form limits, mirrored 1:1 from the Laravel requests in
 * api/app/Http/Requests/FollowUp (MilestoneRequest, ProgressReportRequest, IndicatorValueRequest,
 * InvestorUpdateRequest, RemediationPlanRequest, FollowUpReviewRequest, ExecutionStatusRequest).
 */
export const FOLLOW_UP_RULES = {
  titleMin: 3,
  titleMax: 190,
  deliverableMin: 3,
  deliverableMax: 1000,
  descriptionMax: 2000,
  responsibleMax: 190,
  budgetShareMax: 100,
  noteMin: 5,
  noteMax: 2000,
  evidenceNoteMax: 2000,
  narrativeMin: 10,
  achievedMin: 5,
  longTextMax: 5000,
  milestoneNoteMax: 1000,
  spendMax: 999_999_999_999,
  reportFilesMax: 5,
  valueAbsMax: 99_999_999_999_999,
  updateBodyMin: 10,
  updateBodyMax: 5000,
  remediationMin: 10,
  remediationMax: 5000,
} as const

/** Status → CSS color token (SPEC §10). */
import type { OpportunityStatus, MembershipStatus, InterestStatus, DocumentStatus, InfoRequestStatus, MeetingStatus, EvaluationResult } from '~/types/enums'

export const opportunityStatusColor = (s: OpportunityStatus) => `var(--status-${s})`

const MAP: Record<string, string> = {
  // membership
  'membership.registered': '#94A3B8',
  'membership.under_review': '#B45309',
  'membership.needs_completion': '#D97706',
  'membership.approved': '#047857',
  'membership.suspended': '#6B7280',
  'membership.rejected': '#B91C1C',
  // interest
  'interest.sent': '#1F5BFF',
  'interest.awaiting_owner_approval': '#B45309',
  'interest.shared': '#6D28D9',
  'interest.connected': '#0F766E',
  'interest.meeting': '#047857',
  'interest.closed': '#374151',
  // document
  'document.pending_review': '#B45309',
  'document.accepted': '#047857',
  'document.required': '#D97706',
  'document.rejected': '#B91C1C',
  // info request
  'info.sent': '#1F5BFF',
  'info.processing': '#B45309',
  'info.answered': '#047857',
  'info.closed': '#374151',
  // meeting
  'meeting.scheduled': '#1F5BFF',
  'meeting.held': '#047857',
  'meeting.cancelled': '#6B7280',
  // agreement (AGR-02..05)
  'agreement.draft': '#94A3B8',
  'agreement.legal_review': '#B45309',
  'agreement.changes_requested': '#D97706',
  'agreement.approved': '#1F5BFF',
  'agreement.sent_for_signature': '#6D28D9',
  'agreement.signed': '#047857',
  'agreement.archived': '#374151',
  'agreement.cancelled': '#6B7280',
  // evaluation result
  'eval.eligible': '#047857',
  'eval.eligible_with_conditions': '#B45309',
  'eval.not_eligible_now': '#B91C1C',
  // generic
  'generic.pending': '#B45309',
  'generic.interested': '#047857',
  'generic.declined': '#6B7280',
  'generic.approved': '#047857',
  'generic.active': '#047857',
  'generic.suspended': '#6B7280',
  'generic.new': '#1F5BFF',
  'generic.answered': '#047857',
  'generic.assigned': '#1F5BFF',
  'generic.coi_declared': '#6D28D9',
  'generic.scored': '#047857',
  'generic.withdrawn': '#6B7280',
  'generic.on_track': '#047857',
  'generic.delayed': '#B45309',
  'generic.stalled': '#B91C1C',
}

export const statusColor = (
  kind: 'opportunity' | 'membership' | 'interest' | 'document' | 'info' | 'meeting' | 'eval' | 'agreement' | 'generic',
  value: OpportunityStatus | MembershipStatus | InterestStatus | DocumentStatus | InfoRequestStatus | MeetingStatus | EvaluationResult | string,
) => {
  if (kind === 'opportunity') return opportunityStatusColor(value as OpportunityStatus)
  return MAP[`${kind}.${value}`] ?? '#5B6478'
}

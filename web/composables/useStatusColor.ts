/** Status → CSS colour token (SPEC §10). The hues are theme-aware (tokens.css --hue-*: lighter on the dark palette). */
import type { OpportunityStatus, MembershipStatus, InterestStatus, DocumentStatus, InfoRequestStatus, MeetingStatus, EvaluationResult } from '~/types/enums'

export const opportunityStatusColor = (s: OpportunityStatus) => `var(--status-${s})`

const MAP: Record<string, string> = {
  // membership
  'membership.registered': 'var(--hue-slate)',
  'membership.under_review': 'var(--hue-amber)',
  'membership.needs_completion': 'var(--hue-orange)',
  'membership.approved': 'var(--hue-green)',
  'membership.suspended': 'var(--hue-gray)',
  'membership.rejected': 'var(--hue-red)',
  // interest
  'interest.sent': 'var(--hue-blue)',
  'interest.awaiting_owner_approval': 'var(--hue-amber)',
  'interest.shared': 'var(--hue-violet)',
  'interest.connected': 'var(--hue-teal)',
  'interest.meeting': 'var(--hue-green)',
  'interest.closed': 'var(--hue-charcoal)',
  // document
  'document.pending_review': 'var(--hue-amber)',
  'document.accepted': 'var(--hue-green)',
  'document.required': 'var(--hue-orange)',
  'document.rejected': 'var(--hue-red)',
  // info request
  'info.sent': 'var(--hue-blue)',
  'info.processing': 'var(--hue-amber)',
  'info.answered': 'var(--hue-green)',
  'info.closed': 'var(--hue-charcoal)',
  // meeting
  'meeting.scheduled': 'var(--hue-blue)',
  'meeting.held': 'var(--hue-green)',
  'meeting.cancelled': 'var(--hue-gray)',
  // agreement (AGR-02..05)
  'agreement.draft': 'var(--hue-slate)',
  'agreement.legal_review': 'var(--hue-amber)',
  'agreement.changes_requested': 'var(--hue-orange)',
  'agreement.approved': 'var(--hue-blue)',
  'agreement.sent_for_signature': 'var(--hue-violet)',
  'agreement.signed': 'var(--hue-green)',
  'agreement.archived': 'var(--hue-charcoal)',
  'agreement.cancelled': 'var(--hue-gray)',
  // evaluation result
  'eval.eligible': 'var(--hue-green)',
  'eval.eligible_with_conditions': 'var(--hue-amber)',
  'eval.not_eligible_now': 'var(--hue-red)',
  // generic
  'generic.pending': 'var(--hue-amber)',
  'generic.interested': 'var(--hue-green)',
  'generic.declined': 'var(--hue-gray)',
  'generic.approved': 'var(--hue-green)',
  'generic.active': 'var(--hue-green)',
  'generic.suspended': 'var(--hue-gray)',
  'generic.new': 'var(--hue-blue)',
  'generic.answered': 'var(--hue-green)',
  'generic.assigned': 'var(--hue-blue)',
  'generic.coi_declared': 'var(--hue-violet)',
  'generic.scored': 'var(--hue-green)',
  'generic.withdrawn': 'var(--hue-gray)',
  'generic.on_track': 'var(--hue-green)',
  'generic.delayed': 'var(--hue-amber)',
  'generic.stalled': 'var(--hue-red)',
}

export const statusColor = (
  kind: 'opportunity' | 'membership' | 'interest' | 'document' | 'info' | 'meeting' | 'eval' | 'agreement' | 'generic',
  value: OpportunityStatus | MembershipStatus | InterestStatus | DocumentStatus | InfoRequestStatus | MeetingStatus | EvaluationResult | string,
) => {
  if (kind === 'opportunity') return opportunityStatusColor(value as OpportunityStatus)
  return MAP[`${kind}.${value}`] ?? 'var(--hue-neutral)'
}

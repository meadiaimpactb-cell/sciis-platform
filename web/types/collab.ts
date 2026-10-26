/**
 * Collaboration types — ACC-08 (organisation team), MTG-03 (record messages), MTG-04 (event registration),
 * MTG-05 (calendar feed). JSON keys stay snake_case like the rest of `types/api.ts`.
 */
import type { UserType } from './enums'

// ---------- ACC-08 organisation team ----------
export interface TeamMember {
  id: number
  uuid: string
  name: string
  email: string
  is_representative: boolean
  is_me: boolean
  can_act: boolean
  status: 'active' | 'disabled'
  last_login_at: string | null
  joined_at: string | null
}

export interface TeamInvitation {
  id: number
  email: string
  name: string | null
  can_act: boolean
  status: 'pending' | 'expired'
  expires_at: string
  created_at: string
  invited_by: string | null
}

export interface OrganizationTeam {
  organization: { uuid: string; name: string }
  is_representative: boolean
  can_act: boolean
  members: TeamMember[]
  invitations: TeamInvitation[]
}

/** GET /auth/invitations/{token} */
export interface InvitationPreview {
  email: string
  name: string | null
  organization: { name: string }
  type: UserType
  type_label: string
  expires_at: string
}

// ---------- MTG-03 record messages ----------
export type ThreadSubjectType = 'opportunity' | 'interest' | 'nomination'

export interface ThreadSubject {
  type: ThreadSubjectType
  key: string | number
  title: string
  reference_no: string | null
  opportunity_uuid: string
  counterpart: string | null
}

export interface ThreadSummary {
  uuid: string
  subject: ThreadSubject
  last_message_at: string | null
  last_message: { excerpt: string; author_side: 'staff' | 'party'; created_at: string } | null
  unread_count: number
  messages_count: number
}

export interface ThreadAttachment {
  uuid: string
  title: string
  mime: string
  size_bytes: number
  downloadable: boolean
  scan_pending: boolean
}

export interface ThreadMessage {
  uuid: string
  body: string
  author: { name: string; side: 'staff' | 'party'; is_me: boolean }
  created_at: string
  attachments: ThreadAttachment[]
}

export interface ThreadView {
  thread: { uuid: string } | null
  subject: ThreadSubject
  can_post: boolean
  messages: ThreadMessage[]
}

// ---------- MTG-04 event registration ----------
export type RegistrationClosedReason = 'full' | 'deadline' | 'closed' | 'ended' | 'disabled'

/** GET /public/events/{slug}/registration (not cached) */
export interface EventRegistrationStatus {
  enabled: boolean
  open: boolean
  reason: RegistrationClosedReason | null
  deadline: string | null
  capacity: number | null
  seats_left: number | null
  external_url: string | null
  me: { registered: boolean; name: string; email: string; phone: string | null; organization: string | null } | null
}

export interface EventRegistrationReceipt {
  uuid: string
  status: 'registered' | 'cancelled'
  email: string
  event: { slug: string; title: string; starts_at: string | null; ends_at: string | null; location: string | null }
}

export interface AdminEventSummary {
  id: number
  slug: string
  title: string
  starts_at: string | null
  ends_at: string | null
  location: string | null
  is_published: boolean
  registration_url: string | null
  registration: {
    enabled: boolean
    capacity: number | null
    deadline: string | null
    closed: boolean
    open: boolean
    reason: RegistrationClosedReason | null
    registered_count: number
    attended_count: number
    seats_left: number | null
  }
}

export interface EventRegistration {
  id: number
  uuid: string
  name: string
  email: string
  phone: string | null
  organization: string | null
  status: 'registered' | 'cancelled'
  attended: boolean
  attended_at: string | null
  is_member: boolean
  created_at: string
  cancelled_at: string | null
}

// ---------- MTG-05 calendar feed ----------
export interface CalendarFeed {
  active: boolean
  url: string | null
  webcal_url: string | null
  google_url: string | null
  outlook_url: string | null
  outlook_live_url: string | null
  created_at: string | null
  last_accessed_at: string | null
}

/** Anything that can be added to a calendar (meeting, event). */
export interface CalendarItem {
  title: string
  starts_at: string
  ends_at?: string | null
  location?: string | null
  details?: string | null
}

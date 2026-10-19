# SCIIS Platform — Shared Engineering Specification (v3.0)

> منصة المجلس السعودي للمستثمرين الاجتماعيين — Saudi Council for Impact Investors.
> This file is the **single source of truth** that the API, the web app, the mobile app and the documentation are built against.
> It is derived, without loss, from "الوثيقة التطويرية 2.0" (14 Oct 2026) and replaces every WordPress/Elementor assumption in it.
> Requirement codes (ACC-01, OPP-03, …) are the same codes used in the document and must be referenced in code comments and tests.

---

## 0. Stack decision (final)

| Layer | Choice | Why |
|---|---|---|
| Backend API | **PHP 8.3 · Laravel 12 (API-only, no Blade UI)** | Mature batteries for exactly this domain: Sanctum (SPA cookies + mobile bearer tokens), Policies/Gates (RBAC + record-level), Queues/Horizon, Notifications (mail/SMS/database), Scheduler (SLA reminders), Events, Pest tests, Pint. The team already builds with Laravel + Vue, which is the strongest maintainability argument. **Laravel is not WordPress**: no themes, no plugins, no page builder, no shared DB with a CMS. |
| Database | **PostgreSQL 16** | Strict typing, JSONB for flexible form answers, row-level constraints, partial indexes, mature backups. Hosted inside KSA. |
| Cache / queues | **Redis 7** | Queues (notifications, document scanning, exports), rate limiting, cache. |
| Object storage | **S3-compatible** (MinIO locally; any KSA-resident S3-compatible bucket in production) | Encrypted documents, temporary signed URLs, versioning. |
| Web frontend | **Nuxt 3 (Vue 3 + TypeScript) · Tailwind CSS · Pinia · @nuxtjs/i18n** | Same Vue the team knows; SSR/prerender for the public site (SEO), client-only portal. RTL by default, English switch. |
| Mobile (phase 2+) | **Flutter 3 (Dart)** — one codebase for iOS + Android | First-class RTL/Arabic, native performance, consumes the **same `/api/v1`** with Sanctum bearer tokens. The API is designed mobile-first from day one (JSON only, versioned, token auth, push-token registration endpoint). |
| Payments | **Gateway abstraction + Moyasar driver** (Mada, Visa/MC, Apple Pay, STC Pay) behind `FEATURE_PAYMENTS=false` | Payments are **only** for membership fees / paid support services / training / sponsorship (doc §16.2). The platform never collects investor money for deals, never holds escrow, never issues securities (doc §4.1, §16.1 — licensed activities, out of scope). |
| Auth | Laravel Sanctum, TOTP 2FA (mandatory for staff), email + SMS OTP verification | ACC-01, ACC-05 |
| Observability | Structured JSON logs, health endpoint, Horizon dashboard, Sentry-compatible hook | BKP-03 |
| Dev env | Docker Compose: postgres, redis, minio, mailpit, api, web | SEC-04 (separate environments) |

Naming: PHP `PSR-12`, DB tables `snake_case` plural, enums string-backed `snake_case`, API JSON `snake_case`, TypeScript `camelCase` in the client only.

---

## 1. Actors & roles

### 1.1 Account types (`users.type`, enum `UserType`)
| value | Arabic | notes |
|---|---|---|
| `investor_individual` | مستثمر فرد | |
| `investor_institutional` | مستثمر مؤسسي | represents an `organizations` row; multi-user in P2 (ACC-08) |
| `entrepreneur_individual` | صاحب مشروع (فرد) | |
| `entrepreneur_entity` | صاحب مشروع (منشأة) | represents an `organizations` row |
| `staff` | فريق المجلس | staff roles below |

### 1.2 Staff roles (spatie/laravel-permission, guard `api`)
| role | Arabic | scope |
|---|---|---|
| `super_admin` | مدير النظام | users, roles, settings, forms, taxonomies |
| `membership_officer` | مسؤول العضوية | review investor applications, recommend approval |
| `reviewer` | مراجع | initial screening of opportunities (completeness + scope) |
| `evaluator` | مقيّم | only opportunities assigned to them (ACC-04) |
| `matching_officer` | مسؤول الربط وعلاقات المستثمرين | nominations, teaser approval, meetings, info requests routing |
| `agreements_officer` | مسؤول الاتفاقيات / المستشار القانوني | templates, versions, legal review, archive |
| `impact_officer` | مسؤول المتابعة والأثر | milestones, progress reports, indicator verification |
| `advisory_member` | عضو اللجنة الاستشارية | temporary read access to referred files only (EVL-04) |
| `reports_viewer` | مستعرض تقارير | aggregated reports, no personal data |

Permissions (spatie) are fine-grained strings: `opportunities.view`, `opportunities.transition`, `evaluations.assign`, `evaluations.score`, `evaluations.approve`, `memberships.review`, `memberships.approve`, `nominations.manage`, `teasers.approve`, `meetings.manage`, `agreements.manage`, `impact.verify`, `reports.view`, `reports.export`, `settings.manage`, `users.manage`, `audit.view`. Seeded in `RolePermissionSeeder`.

### 1.3 Access levels for investors (doc §6.6)
`visitor` → `registered` (email+phone verified) → `approved_member` (membership approved + terms accepted) → `granted` (owner approved full-file access + NDA accepted, per opportunity) → `connected` (interest status `connected`).

---

## 2. Enumerations (`app/Enums/*.php`, string-backed; mirrored in `web/types/enums.ts` and `mobile/lib/core/enums.dart`)

```
OpportunityStatus: draft, new, under_review, needs_completion, not_eligible, eligible,
                   connected, negotiating, in_execution, operating, withdrawn, closed
MembershipStatus:  registered, under_review, needs_completion, approved, suspended, rejected
InterestStatus:    sent, awaiting_owner_approval, shared, connected, meeting, closed
InfoRequestStatus: sent, processing, answered, closed
Sensitivity:       public, internal, confidential, sensitive_personal   # ع / د / س / ح
EvaluationAxis:    readiness, feasibility, impact
EvaluationResult:  eligible, eligible_with_conditions, not_eligible_now
AssignmentStatus:  assigned, coi_declared, scored, withdrawn
DocumentKind:      pitch_deck, commercial_register, license, financial_statements,
                   business_plan, team_cv, impact_evidence, authorization_letter,
                   financial_projections, agreement, progress_evidence, other
DocumentStatus:    pending_review, accepted, required, rejected
IndicatorValueStatus: entered, verified, needs_clarification
ExecutionStatus:   on_track, delayed, stalled
MeetingStatus:     scheduled, held, cancelled
AgreementStatus:   draft, legal_review, approved, signed, archived
ConsentType:       privacy_policy, terms, teaser_publication, full_file_share, nda, data_accuracy
NotificationChannel: database, mail, sms
PaymentStatus:     pending, authorized, paid, failed, refunded, cancelled
PaymentPurpose:    membership_fee, service_fee, training, sponsorship
InvestmentType (9, seeded taxonomy): equity, debt, pay_for_results, grant, guarantee,
                   patient_capital, convertible, revenue_share, blended
ProjectStage:      idea, prototype, pre_seed, seed, early_stage, later_stage
RiskAppetite:      high, medium, low
RevenueState:      none, early, regular
LegalForm:         company, establishment, nonprofit, other
InstitutionType:   company, fund, family_office, donor, nonprofit, other
```

Taxonomies that staff edit **without code** (FRM-04): `sectors` (19 seeded), `regions` (13 KSA regions + "خارج المملكة"), `investment_types`, `beneficiary_groups`, `sdgs` (17), `project_stages`, `investment_ranges` (5 bands), `decline_reasons`, `how_heard`. Table `taxonomy_terms(group, key, label_ar, label_en, sort, is_active)`.

---

## 3. Opportunity lifecycle (state machine — ADM-01)

```
draft ──submit──▶ new ──assign+screen──▶ under_review ──score+approve──▶ eligible ──interest+owner_consent──▶ connected
                    │                        │                               │
                    └──needs_completion ◀─────┘ (reviewer requests)          ├──▶ negotiating ──▶ in_execution ──▶ operating
                    │     │                                                   │
                    │     └──resubmit──▶ under_review                        └──▶ closed (admin) 
                    └──▶ not_eligible (reviewer, supervisor approval; reason required; resubmission allowed)
any (owner-initiated, before connected) ──▶ withdrawn
any (admin decision) ──▶ closed
```

Rules:
* Transitions are **only** performed through `OpportunityStateMachine::transition(Opportunity, OpportunityStatus $to, User $actor, ?string $reason)`; it validates the allowed map, checks permission, writes `opportunity_status_transitions` (from, to, actor, reason, at) and an `audit_logs` row, and fires `OpportunityStatusChanged` (notifications).
* `needs_completion` → `under_review` happens when the owner re-submits; `under_review` → `new` is **not** allowed (doc §4.3 figure note: "from needs_completion back to under_review").
* `eligible` requires: `evaluation_result ∈ {eligible, eligible_with_conditions}` **and** an approved teaser (`teasers.approved_by_council_at` + `approved_by_owner_at`).
* `connected` requires an `interests` row for this opportunity in status `connected` (dual consent satisfied).
* SLA days per status live in `settings` (`sla.review_days`, `sla.completion_days`, `sla.evaluation_days`), editable by `super_admin` (ADM-03); overdue flags computed, reminders via scheduler.
* Visibility (doc §6.6): `draft,new,under_review,needs_completion,not_eligible` → never visible to investors. `eligible` with approved teaser → visible to approved members per `settings.opportunity_access_policy` (`nomination_only` | `browse_all` | `both`; default `nomination_only`, flagged "معلّق" pending legal opinion). `connected+` → stays visible or hidden by admin flag `is_listed`; full details only to connected investors.

Membership state machine (`MembershipStatus`): `registered → under_review → (needs_completion ⇄ under_review) → approved | rejected`; `approved → suspended → approved`.

Interest state machine: `sent → awaiting_owner_approval → shared → connected → meeting → closed`; `closed` reachable from any state with `decline_reason` (taxonomy) + optional note.

---

## 4. Data model (PostgreSQL) — tables & key columns

All tables: `id bigserial`, `created_at`, `updated_at`; soft deletes only where noted. `uuid` public identifiers on externally visible rows (opportunities, documents, users) to avoid enumeration.

### Identity & access
* `users` — uuid, type(UserType), name, email(unique), email_verified_at, phone(E.164, unique), phone_verified_at, password, locale(ar|en), two_factor_secret(enc), two_factor_confirmed_at, last_login_at, status(active|suspended), organization_id(nullable FK), deleted_at.
* `organizations` — uuid, name, legal_form(LegalForm), institution_type(InstitutionType, investors), cr_number, unified_number, license_number, founded_on, employees_count, website, region_id, city, representative_user_id, authorization_letter_document_id.
* `roles`, `permissions`, `model_has_roles`, … (spatie).
* `personal_access_tokens` (Sanctum) + `device_tokens` (user_id, platform ios|android|web, token, last_seen_at) for push (mobile).
* `otp_codes` — user_id, channel(email|sms), code_hash, expires_at, consumed_at, attempts.
* `consents` — user_id, type(ConsentType), version, text_hash, accepted_at, ip, user_agent, subject_type/subject_id (polymorphic: opportunity/interest for scoped consents). **PRV-02**: never updated, only inserted.

### Investor side
* `investor_profiles` — user_id(unique), country, city, employer, job_title, linkedin_url, experience_years, experience_desc, prior_investments_count, prior_investments_desc, has_social_investments(bool)+desc, risk_appetite, mentoring_available(bool)+areas, accredited_declaration(json: status pending_legal; kept but not shown — doc §7.5), investment_range_id(FK taxonomy, confidential), annual_investment_band_id(FK, confidential, aggregated reports only), preferred_contact, how_heard_id, cv_document_id, conflicts_disclosure(text), commit_meetings(bool), completion_percent(computed), reminder_last_sent_at.
* `investor_profile_sectors`, `_stages`, `_investment_types`, `_regions`, `_causes` (pivot tables to taxonomy_terms) — used by matching.
* `memberships` — user_id, status(MembershipStatus), submitted_at, reviewed_by, reviewed_at, approved_by, approved_at, decision_note(internal), terms_accepted_at, suspended_reason.

### Opportunity (project) side
* `opportunities` — uuid, reference_no (`A-YYYY-NNNN`, unique, generated on submit), owner_user_id, organization_id(nullable), name, short_description(≤280), sector_id, stage(ProjectStage), status(OpportunityStatus), is_listed(bool), website, social_links(json), problem_statement, solution, expected_change, beneficiaries_desc, beneficiaries_current(int, null), beneficiaries_target(int), revenue_model_type + revenue_model_desc, revenue_state(RevenueState), last_year_revenue_band_id, funding_amount (numeric 14,2 SAR, **confidential**), funding_amount_display(`exact|band|hidden` per settings), use_of_funds(json [{item, percent}], confidential), prior_funding(bool)+desc(confidential), legal_disputes(bool)+desc(confidential), license_required(bool)+license_status, operational_risks(text, confidential), council_relationship(bool)+desc(internal), data_accuracy_declared_at, submitted_at, assigned_reviewer_id, review_due_at, completion_due_at, evaluation_due_at, evaluation_result(EvaluationResult,null), evaluation_approved_at, execution_status(ExecutionStatus,null), deleted_at.
* `opportunity_regions`, `opportunity_investment_types`, `opportunity_beneficiary_groups`, `opportunity_sdgs` (pivots).
* `opportunity_status_transitions` — opportunity_id, from_status, to_status, actor_user_id, reason, metadata(json), created_at. Append-only.
* `teasers` — opportunity_id(unique), summary_ar, summary_en(null), highlights(json), version, approved_by_owner_at, approved_by_council_at, approved_by_user_id. Only `public`-sensitivity fields may appear (MAT-05).
* `impact_indicators` — opportunity_id, name, definition, unit, baseline(numeric), target(numeric), data_source, frequency(monthly|quarterly|semiannual|annual), verification_method, iris_code(null, P3), sdg_id(null), is_primary(bool), is_disclosed(bool).
* `indicator_values` — impact_indicator_id, period_start, period_end, value, evidence_document_id, status(IndicatorValueStatus), entered_by, verified_by, verified_at, note.
* `completion_requests` — opportunity_id, requested_by, due_at, items(json [{field|document_kind, note}]), resolved_at. Shown in "المطلوب منك الآن" (ADM-04, ENT-04).
* `internal_notes` — notable_type/id, author_id, body. Never exposed to external users (ADM-05).

### Documents (DOC)
* `documents` — uuid, owner_user_id, documentable_type/id (opportunity|organization|user|agreement|progress_report|indicator_value), kind(DocumentKind), title, disk, path, mime, size_bytes, sha256, sensitivity(Sensitivity), status(DocumentStatus), reviewer_note, version(int), supersedes_document_id, scanned_at, scan_result(clean|infected|pending), deleted_at. Max size & allowed mimes from settings (DOC-01). Download only via signed temporary URL (DOC-02).
* `document_access_logs` — document_id, user_id, action(view|download), ip, user_agent, created_at (INV-04, AUD).
* `file_access_grants` — opportunity_id, investor_user_id, granted_by_owner_at, nda_consent_id, revoked_at (ENT-06). Full-file access requires an active grant.

### Evaluation (EVL)
* `evaluation_criteria` — axis(EvaluationAxis), key, label_ar, label_en, weight(numeric), scale_descriptions(json 1..5), version, is_active. Seeded from doc §12.5.
* `evaluation_assignments` — opportunity_id, evaluator_user_id, status(AssignmentStatus), coi_declared_at, coi_has_conflict(bool). Exactly two per opportunity in P1 (EVL-02).
* `evaluations` — assignment_id(unique), criteria_version, scores(json {criterion_key: {score:1..5, note}}), axis_totals(json), overall(numeric), recommendation(EvaluationResult), comment, submitted_at. Criteria version snapshot is stored (EVL-01).
* `evaluation_decisions` — opportunity_id, result(EvaluationResult), conditions(text), reasons(text), decided_by, decided_at, advisory_referral_id(null).
* `advisory_referrals` — opportunity_id, advisory_user_id, question, expires_at, opinion(text), opined_at (EVL-04, P2 but modelled now).

### Matching & connection (MAT / OPP / MSG)
* `saved_opportunities` — user_id, opportunity_id (OPP-04).
* `nominations` — opportunity_id, investor_user_id, nominated_by, matched_criteria(json: which of sector/stage/region/type/range matched), match_score(int = count of matched criteria — transparent rule, MAT-02), rationale(text, MAT-03), teaser_version, sent_at, response(interested|declined|null), decline_reason_id, decline_note, responded_at.
* `interests` — opportunity_id, investor_user_id, nomination_id(null), status(InterestStatus), owner_decision(approved|declined|null), owner_decided_at, decline_reason_id, note, closed_reason, closed_at. Unique (opportunity_id, investor_user_id) while open.
* `info_requests` — opportunity_id, investor_user_id, subject, body, status(InfoRequestStatus), handled_by, forwarded_to_owner_at, owner_answer, answered_at. **Investor identity is not forwarded to the owner** (OPP-05, MSG-01).
* `saved_filters` (P2), `investor_notes` (P2: user_id, opportunity_id, body, tags json — private, OPP-09).

### Meetings (MTG)
* `meetings` — opportunity_id, interest_id, title, starts_at, ends_at, location|video_url, organizer_user_id, status(MeetingStatus), minutes(text), ics_sent_at.
* `meeting_participants` — meeting_id, user_id, role(investor|owner|council), attended(bool).
* `meeting_actions` — meeting_id, description, owner_user_id, due_at, done_at.

### Agreements (AGR, P2 modelled now)
* `agreement_templates` — key, title, body(markdown with `{{placeholders}}`), version, is_active, legal_approved_at.
* `agreements` — opportunity_id, interest_id, template_id, status(AgreementStatus), council_is_party(bool, default false), current_version_id, signed_document_id, archived_at.
* `agreement_versions` — agreement_id, version, body, change_note, proposed_by, created_at; `agreement_reviews` — version_id, reviewer_id, decision(approved|changes_requested), note.

### Execution & impact (PRJ / IMP, P2 modelled now)
* `milestones` — opportunity_id, title, deliverable, due_at, completed_at, status.
* `progress_reports` — opportunity_id, period_start, period_end, achieved, delayed, risks, support_needed, execution_status(ExecutionStatus), submitted_at, reviewed_by, reviewed_at. Attachments via `documents`.
* `investor_updates` (ENT-10, P2) — opportunity_id, author_id, body, metrics(json), sent_at, reviewed_at.

### Platform
* `notifications` (Laravel database notifications) + `notification_preferences` (user_id, channel, category, enabled).
* `audit_logs` — uuid, user_id(null for system), action (e.g. `opportunity.transition`, `document.download`, `report.export`), auditable_type/id, before(json), after(json), ip, user_agent, request_id, created_at. **No update/delete ever** — enforced by DB trigger (`audit_logs_immutable`) and by the model having no update/delete methods (AUD-02).
* `settings` — key(unique), value(json), group, description, updated_by. Seeded keys listed in §7.
* `taxonomy_terms` — group, key, label_ar, label_en, sort, is_active, meta(json).
* `form_schemas` (P2 FRM-01) — key, version, schema(json), is_active.
* `pages`, `faqs`, `news_posts`, `events` (CMS-02, public content managed by staff): slug, title_ar/en, body_ar/en, is_published, published_at.
* `contact_messages` — category(investor|entrepreneur|partnership|media|other), name, email, phone, message, reference_no, assigned_to, status.
* `payments` — uuid, user_id, purpose(PaymentPurpose), amount(numeric 12,2), currency(SAR), status(PaymentStatus), gateway(moyasar), gateway_payment_id, gateway_payload(json), idempotency_key(unique), description, invoice_id, paid_at, failed_reason, refunded_at. `payment_events` — payment_id, type, payload(json), signature_valid(bool), created_at (webhook log). `invoices` — number, user_id, amount, vat_rate, vat_amount, total, issued_at, pdf_document_id.

---

## 5. Sensitivity rules (doc §6.2 / §12.1) — enforced in API Resources

| Field group | teaser/card | details (before grant) | after grant | sensitivity |
|---|---|---|---|---|
| name, organization name, sector, stage, regions, investment types, founded year | ✓ | ✓ | ✓ | public |
| teaser summary (2 lines on card), beneficiaries target & groups | ✓ | ✓ | ✓ | public |
| problem, solution, expected change, primary indicator (baseline/target) | — | ✓ | ✓ | public |
| disclosed indicators (flagged "مفصح عنها") | — | ✓ | ✓ | public |
| funding amount | band or exact per `settings.funding_display` | ✓ | ✓ | confidential→public by decision |
| use of funds | — | summary % | detailed | confidential |
| risks & disclosures | — | summary | full | confidential |
| financial statements, full deck, documents | — | locked | ✓ signed URL + access log (+watermark P2) | confidential |
| owner contact data (email, phone) | — | — | only when interest = `connected` | sensitive_personal |

`OpportunityCardResource`, `OpportunityDetailResource` (takes `$accessLevel`), `OpportunityFullResource` implement these columns. A Pest test asserts that no `confidential`/`sensitive_personal` field leaks at lower levels (MSG-01 acceptance).

---

## 6. REST API v1 (`/api/v1`, JSON, `Accept-Language: ar|en`)

Conventions: Laravel API Resources; list endpoints paginated `?page=&per_page=` (max 100) with `?filter[sector]=&filter[region]=&filter[stage]=&filter[type]=&sort=newest|impact|need&search=`; errors RFC-7807-ish `{message, errors{field:[...]}, code}`; `X-Request-Id` echoed; rate limiting per route group; all mutating requests audited.

### Auth (`/auth`)
```
POST /auth/register                 {type, name, email, phone, password, locale, consents:[privacy_policy,terms]}  → 201 user + sends OTPs
POST /auth/verify-email             {code}            POST /auth/verify-phone  {code}        POST /auth/resend-otp {channel}
POST /auth/login                    {email, password, device_name?}  → web: cookie session; mobile: {token}
POST /auth/2fa/challenge            {code}   (when enabled)         POST /auth/2fa/enable  → secret+qr; POST /auth/2fa/confirm {code}; DELETE /auth/2fa
POST /auth/logout                   POST /auth/forgot-password {email}   POST /auth/reset-password {token,email,password}
GET  /me                            → user + type + roles + membership status + completion
PATCH /me                           {name, locale, preferred_contact}
POST /me/device-tokens              {platform, token}      DELETE /me/device-tokens/{token}
GET/PUT /me/notification-preferences
POST /me/data-requests              {type: access|rectify|erase, note}   (PRV-03)
```
### Taxonomies & settings (public read)
```
GET /taxonomies                     → all active groups     GET /taxonomies/{group}
GET /settings/public                → funding_display, access_policy, supported locales, legal page versions
```
### Investor
```
GET/PUT  /investor/profile          (sections: basics, interests, experience, declarations)  → completion_percent
POST     /investor/membership/submit            GET /investor/membership
GET      /investor/dashboard        → counters {saved, interests, info_requests, meetings}, suggested[] (rule-based), updates[], next_meeting
GET      /opportunities             (approved members only; filtered by access policy)  → cards
GET      /opportunities/{uuid}      → detail at caller's access level (tabs: overview, impact, indicators, risks, documents(locked|list))
POST     /opportunities/{uuid}/save            DELETE /opportunities/{uuid}/save      GET /investor/saved
POST     /opportunities/{uuid}/interest        {note?}     → interest (status sent→awaiting_owner_approval)
GET      /investor/interests        GET /investor/interests/{id}       POST /investor/interests/{id}/close {reason_id, note}
POST     /opportunities/{uuid}/info-requests   {subject, body}         GET /investor/info-requests
GET      /investor/nominations      POST /investor/nominations/{id}/respond {response: interested|declined, decline_reason_id?, note?}
POST     /opportunities/{uuid}/nda-accept      {version}  → consent (required before full file)
GET      /opportunities/{uuid}/documents       (granted only)    GET /documents/{uuid}/download-url → {url, expires_at}
GET      /investor/meetings
P2: GET/POST/DELETE /investor/saved-filters, GET/POST /opportunities/{uuid}/notes, GET /investor/portfolio
```
### Entrepreneur (project owner)
```
GET      /entrepreneur/dashboard    → opportunities[], required_now[], access_requests[], completion
GET/POST /entrepreneur/opportunities            (draft created on POST)
GET/PATCH /entrepreneur/opportunities/{uuid}    (sections: basics, organization, impact, financials, disclosures; locked per status ENT-05)
GET      /entrepreneur/opportunities/{uuid}/checklist   → missing fields/documents (ENT-02)
POST     /entrepreneur/opportunities/{uuid}/submit      → status new, reference_no, email (ENT-03)
POST     /entrepreneur/opportunities/{uuid}/withdraw    (ENT-07)
GET/POST/DELETE /entrepreneur/opportunities/{uuid}/indicators
POST     /entrepreneur/opportunities/{uuid}/documents   multipart {kind, file}  → pending_review, queued scan
GET      /entrepreneur/opportunities/{uuid}/preview     → card + detail exactly as investors will see (ENT-09)
POST     /entrepreneur/opportunities/{uuid}/teaser/approve
GET      /entrepreneur/access-requests          POST /entrepreneur/access-requests/{interestId}/decide {decision: approve|decline, reason?}
GET      /entrepreneur/info-requests            POST /entrepreneur/info-requests/{id}/answer {answer}
GET      /entrepreneur/meetings
P2: progress reports, investor updates, milestones endpoints under /entrepreneur/opportunities/{uuid}/...
```
### Admin / staff (`/admin`, role-gated)
```
GET  /admin/dashboard                      → today counters, pipeline by status, overdue, my_tasks
GET  /admin/opportunities                  (filters: status, sector, overdue, assignee)   GET /admin/opportunities/{uuid} (full + timeline + notes)
POST /admin/opportunities/{uuid}/assign    {reviewer_user_id}
POST /admin/opportunities/{uuid}/transition {to, reason?}                       (ADM-01)
POST /admin/opportunities/{uuid}/completion-requests {items[], due_at}         (ADM-04)
POST /admin/opportunities/{uuid}/notes      {body}                              (ADM-05)
POST /admin/opportunities/{uuid}/documents/{docUuid}/review {status, note}
POST /admin/opportunities/{uuid}/evaluation/assign {evaluator_ids:[2]}          (EVL-02)
POST /admin/evaluation-assignments/{id}/coi {has_conflict}
GET/POST /admin/evaluation-assignments/{id}/score  {scores{}, recommendation, comment}
GET  /admin/opportunities/{uuid}/evaluation       → both evaluations side by side + diffs (EVL-03)
POST /admin/opportunities/{uuid}/evaluation/decide {result, conditions?, reasons?}
POST /admin/opportunities/{uuid}/teaser   {summary_ar, summary_en?, highlights[]}   POST .../teaser/approve
GET  /admin/opportunities/{uuid}/candidates       → investors ranked by match_score (MAT-02), COI excluded (MAT-04)
POST /admin/opportunities/{uuid}/nominations      {investor_user_ids[], rationale}
GET  /admin/interests  POST /admin/interests/{id}/transition {to}
GET  /admin/info-requests  POST /admin/info-requests/{id}/forward  POST /admin/info-requests/{id}/reply {answer}
GET/POST /admin/meetings  PATCH /admin/meetings/{id}  POST /admin/meetings/{id}/minutes {minutes, actions[]}
GET  /admin/memberships  GET /admin/memberships/{id}  POST /admin/memberships/{id}/transition {to, note?}
GET  /admin/users  POST /admin/users (staff)  PATCH /admin/users/{id} {roles[], status}
GET/PUT /admin/settings  GET/POST/PATCH/DELETE /admin/taxonomies/{group}
GET/POST/PATCH /admin/evaluation-criteria
GET  /admin/reports/{key}  (keys in §8)   GET /admin/reports/{key}/export.xlsx (RPT-02, audited)
GET  /admin/audit-logs  (filters user, action, type, date)  (AUD-03)
GET/POST/PATCH /admin/content/{pages|faqs|news|events}   GET /admin/contact-messages
P2: /admin/agreements..., /admin/impact/..., /admin/progress-reports...
```
### Public
```
GET  /public/pages/{slug}  GET /public/faqs  GET /public/news  GET /public/events  GET /public/stats (aggregated, non-personal)
POST /public/contact        {category, name, email, phone?, message}  → reference_no (honeypot + rate limit, CMS-05)
GET  /public/featured-opportunities  → teaser cards only if settings.featured_public=true, else []
```
### Payments (behind `FEATURE_PAYMENTS`)
```
POST /payments/intents        {purpose, amount? (server decides from settings), idempotency_key}  → {payment_uuid, gateway, publishable_key, callback_url}
GET  /payments/{uuid}         POST /payments/{uuid}/cancel
POST /webhooks/moyasar        (signature verified, idempotent on gateway_payment_id, logs payment_events, updates status, emits PaymentPaid)
GET  /admin/payments  GET /admin/payments/export.xlsx   POST /admin/payments/{uuid}/refund
```
### System
```
GET /health  → {status, db, redis, storage, queue_lag}   GET /version
```

---

## 7. Settings keys (seeded)
```
platform.name_ar, platform.name_en, platform.short_name (SCII|SCIIS → decision pending), platform.official_email, platform.official_phone, platform.whatsapp (nullable)
opportunity.access_policy = nomination_only | browse_all | both      (default nomination_only — pending legal)
opportunity.funding_display = band | exact | hidden                  (default band)
opportunity.max_nominations_per_opportunity = 5
sla.review_days=5  sla.completion_days=14  sla.evaluation_days=10  sla.owner_decision_days=7
documents.max_size_mb=25   documents.allowed_mimes=[application/pdf, image/png, image/jpeg, docx, xlsx, pptx]
privacy.retention_days.rejected=365  privacy.retention_days.withdrawn=365  privacy.retention_days.inactive=730
features.payments=false  features.english=false  features.browse_all=false  features.watermark=false  features.dark_mode=false
membership.fee_sar=0 (founding phase free — doc)   membership.requires_prior_investments=2
notifications.sms_sender="SCIIS"  reports.allowed_export_roles=[super_admin, reports_viewer]
```

---

## 8. Reports (RPT-01, computed from DB — no hard-coded targets)
`registrations_by_type`, `memberships_by_status`, `opportunities_funnel`, `opportunities_by_sector_stage_region`, `first_time_completion_rate`, `avg_review_time`, `avg_evaluation_time`, `overdue_items`, `eligibility_rate`, `interest_rate`, `connection_rate`, `acquisition_channels`; P2: `agreement_conversion`, `execution_status`, `impact_aggregates`, `meeting_attendance`.

---

## 9. Non-functional requirements mapped to implementation
| Code | Implementation |
|---|---|
| PRV-01 | `consents` table, data-request endpoint, processing-record doc in `docs/SECURITY.md` |
| PRV-02 | immutable consents with version + text hash |
| PRV-04 | `privacy:purge` scheduled command uses retention settings (anonymise, not delete, audit logs) |
| PRV-05 | all infra inside KSA; `config/filesystems` S3 endpoint env; no third-party analytics without consent |
| SEC-01 | TLS at edge; DB disk encryption; documents encrypted at rest (bucket SSE) + `sha256` integrity |
| SEC-02/03 | OWASP checks in CI (composer audit, npm audit); CSP headers middleware; pen-test gate before launch |
| SEC-04 | `.env.*` per environment; seeders produce synthetic data only |
| SEC-05 | NCA ECC checklist in `docs/SECURITY.md` |
| SCL-01/02 | stateless API, Redis sessions, S3 files; horizontal scaling via container replicas |
| SCL-03 | OpenAPI 3.1 at `docs/openapi.yaml`; versioned `/api/v1` |
| BKP-01..03 | `docs/DEPLOYMENT.md`: nightly pg_dump to a second KSA region, restore drill; `/health` for uptime monitors |
| UX-01/02 | Nuxt responsive + `dir=rtl`, logical CSS, Arabic-safe number formatting |
| UX-04 | Tailwind tokens with AA contrast; semantic components |
| ACC-05 | `pragmarx/google2fa-laravel` TOTP, enforced for `staff` via middleware |

---

## 10. Brand tokens (doc §8) — `web/assets/css/tokens.css`, mirrored in Flutter theme
```
--ink:#0A1020  --navy:#101B33  --blue:#1F5BFF  --bg:#F4F6FA  --surface:#FFFFFF
--text:#0A1020 --muted:#5B6478 --border:#E3E7EF
status: new #1F5BFF, under_review #B45309, needs_completion #D97706, not_eligible #B91C1C,
        eligible #047857, connected #0F766E, negotiating #6D28D9, in_execution #1D4ED8, operating #065F46,
        withdrawn #6B7280, closed #374151, draft #94A3B8
font: "IBM Plex Sans Arabic" (OFL) for ar/en/digits
```
Three breakpoints: mobile ≤640, tablet ≤1024, desktop.

---

## 11. Demo data (seeders, all synthetic & labelled "(افتراضي)")
8 opportunities from the prototype (سُلّم, مهارة, رفق, شكّن, حصاد, نماء, دوّر, +1), 3 investors (عبدالله فرد, مكتب عائلي, صندوق), 3 entrepreneurs, full staff set, evaluation criteria, taxonomies, settings, legal pages (placeholders marked "بانتظار الاعتماد القانوني").

---

## 12. Phase mapping (doc §9)
* **P1 (MVP)**: ACC-01..07, ENT-01..07, ENT-09, INV-01..04, ADM-01..07, FRM-02..04, EVL-01..03, MAT-01..05, MTG-01..02, DOC-01..03, RPT-01..02, NTF-01..02, AUD-01..03, CMS-01..05, OPP-01..06, MSG-01..02, BRD-01..03, PRV-01..05, SEC-01..05, SCL-01..02, BKP-01..03, UX-01..02.
* **P2**: ACC-08, ENT-08, ENT-10, INV-05..08, ADM-08..09, FRM-01, EVL-04..05, MTG-03..04, AGR-01..04, PRJ-01..03, IMP-01..02, RPT-03..06, NTF-03, CMS-06, OPP-07..11, MSG-03, SCL-03, UX-03..05, payments go-live.
* **P3**: ACC-09 (Nafath), MAT-06, MTG-05, AGR-05, IMP-03, NTF-04, OPP-12, BRD-04.

Code for P2/P3 entities is **modelled** (migrations + models) so the schema does not churn, but endpoints/UI for them are only stubbed behind feature flags unless listed in P1.

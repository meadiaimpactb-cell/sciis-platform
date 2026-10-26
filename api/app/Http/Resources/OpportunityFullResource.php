<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsOpportunity;
use App\Models\CompletionRequest;
use App\Models\ImpactIndicator;
use App\Models\InternalNote;
use App\Models\Opportunity;
use App\Models\OpportunityStatusTransition;
use App\Models\TaxonomyTerm;
use App\Services\FormSchemaService;
use App\Services\OpportunityStateMachine;
use App\Services\OpportunityTimeline;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Opportunity
 *
 * Full record for the owner (ENT) and staff (ADM): every field incl. confidential + internal.
 * Internal notes & evaluation data only for staff (ADM-05). Owner contact only for staff.
 *
 * Contract: the nested `overview/impact/financials/disclosures/team/market/motivation/teaser` blocks are kept, and every field is ALSO
 * exposed flat at the top level together with the id lists (`sector_id`, `region_ids`, `investment_type_ids`,
 * `investment_type_keys`, `beneficiary_group_ids`, `sdg_ids`, `last_year_revenue_band_id`, `funding_range_id`)
 * so the owner form can hydrate without a second mapping step (web `OpportunityFull`).
 * ENT-05: `locked_sections` (web step keys) + `reopened_fields` (from open completion requests).
 */
class OpportunityFullResource extends JsonResource
{
    use FormatsOpportunity;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $o = $this->resource;
        $o->loadMissing(['sector', 'regions', 'investmentTypes', 'beneficiaryGroups', 'sdgs', 'organization.region', 'teaser', 'fundingRange', 'lastYearRevenueBand', 'howHeard', 'indicators.sdg', 'owner', 'assignedReviewer', 'completionRequests.requester']);
        $viewer = $request->user();
        $isStaff = $viewer?->isStaff() ?? false;

        $overview = [
            'problem_statement' => $o->problem_statement,
            'solution' => $o->solution,
            'expected_change' => $o->expected_change,
        ];
        $impact = [
            'beneficiaries_desc' => $o->beneficiaries_desc,
            'beneficiaries_current' => $o->beneficiaries_current,
            'beneficiaries_target' => $o->beneficiaries_target,
            'beneficiary_groups' => $o->beneficiaryGroups->map(fn (TaxonomyTerm $t): array => $this->term($t))->values()->all(),
            'sdgs' => $o->sdgs->map(fn (TaxonomyTerm $t): array => $this->term($t))->values()->all(),
        ];
        $financials = [
            'revenue_model_type' => $o->revenue_model_type,
            'revenue_model_desc' => $o->revenue_model_desc,
            'revenue_state' => $o->revenue_state?->value,
            'revenue_state_label' => $o->revenue_state?->label(),
            'last_year_revenue_band' => $this->term($o->lastYearRevenueBand),
            'funding_amount' => $o->funding_amount !== null ? (float) $o->funding_amount : null,
            'funding_amount_display' => $o->funding_amount_display?->value,
            'funding_range' => $this->term($o->fundingRange),
            'use_of_funds' => $o->use_of_funds ?? [],
            'prior_funding' => (bool) $o->prior_funding,
            'prior_funding_desc' => $o->prior_funding_desc,
        ];
        $teaserConsent = $o->hasTeaserPublicationConsent();
        $disclosures = [
            'legal_disputes' => (bool) $o->legal_disputes,
            'legal_disputes_desc' => $o->legal_disputes_desc,
            'license_required' => (bool) $o->license_required,
            'license_status' => $o->license_status,
            'operational_risks' => $o->operational_risks,
            'council_relationship' => (bool) $o->council_relationship,
            'council_relationship_desc' => $o->council_relationship_desc,
            'data_accuracy_declared_at' => $o->data_accuracy_declared_at?->toIso8601String(),
            'teaser_publication_consent' => $teaserConsent,
        ];
        // Accelerator-grade sections (readiness axis): team (س), market & product, motivation (د).
        $team = $this->teamBlock($o);
        $market = $this->marketBlock($o);
        $motivation = $this->motivationBlock($o);

        $organization = null;
        if ($o->organization) {
            $organization = (new OrganizationResource($o->organization))->resolve($request) + [
                'id' => $o->organization->id,
                'founded_on' => $o->organization->founded_on?->format('Y-m-d'),
                'region_id' => $o->organization->region_id,
            ];
        }

        $reopened = $o->reopenedFields();
        $slaDue = $o->slaDueAt();

        $data = $this->publicIdentity($o) + [
            'id' => $this->when($isStaff, $o->id),
            'is_listed' => $o->is_listed,
            'short_description' => $o->short_description,
            // OPP-11 — optional English card fields (owner form / staff); investors get them through displayName().
            'name_en' => $o->name_en,
            'short_description_en' => $o->short_description_en,
            'social_links' => $o->social_links,
            // Flat id lists for the owner form.
            'sector_id' => $o->sector_id,
            'region_ids' => $o->regions->pluck('id')->values()->all(),
            'investment_type_ids' => $o->investmentTypes->pluck('id')->values()->all(),
            'beneficiary_group_ids' => $o->beneficiaryGroups->pluck('id')->values()->all(),
            'sdg_ids' => $o->sdgs->pluck('id')->values()->all(),
            'last_year_revenue_band_id' => $o->last_year_revenue_band_id,
            'funding_range_id' => $o->funding_range_id,
            'organization' => $organization,
            'organization_id' => $o->organization_id,
            'overview' => $overview,
            'impact' => $impact,
            'indicators' => $o->indicators->map(fn (ImpactIndicator $i): array => $this->indicator($i))->values()->all(),
            'financials' => $financials,
            'disclosures' => $disclosures,
            'team' => $team,
            'market' => $market,
            'motivation' => $motivation,
            'teaser' => $o->teaser ? [
                'summary_ar' => $o->teaser->summary_ar,
                'summary_en' => $o->teaser->summary_en,
                'summary' => $o->teaser->summary(),
                'highlights' => $o->teaser->highlights ?? [],
                'version' => $o->teaser->version,
                'approved_by_owner_at' => $o->teaser->approved_by_owner_at?->toIso8601String(),
                'approved_by_council_at' => $o->teaser->approved_by_council_at?->toIso8601String(),
            ] : null,
            // DOC-03 — every version, with uploader + previous-version link (grouped into a history by the clients).
            'documents' => DocumentResource::collection($o->documents()->with(['owner:id,name,type', 'supersedes:id,uuid'])->orderByDesc('id')->get()->each(fn ($d) => $d->setRelation('documentable', $o)))->resolve($request),
            'required_now' => $o->completionRequests->whereNull('resolved_at')->map(fn (CompletionRequest $c): array => [
                'id' => $c->id, 'items' => $c->items, 'due_at' => $c->due_at?->toIso8601String(), 'created_at' => $c->created_at?->toIso8601String(),
            ])->values()->all(),
            'completion_requests' => $o->completionRequests->sortByDesc('id')->map(fn (CompletionRequest $c): array => $this->completionRequest($c, $isStaff))->values()->all(),
            // ENT-05
            'locked_sections' => $o->lockedWebSections(),
            // FRM-01 — additional questions of the project form (owner + staff only; never in investor views).
            'extra_answers' => app(FormSchemaService::class)->present($o->extra_answers),
            'reopened_fields' => $reopened,
            'evaluation_result' => $o->evaluation_result?->value,
            'evaluation_result_label' => $o->evaluation_result?->label(),
            // EVL-03 — latest decision as the owner sees it: result, conditions / reasons, improvement plan, date.
            'evaluation_decision' => $o->latestDecision()->first()?->toOwnerArray(),
            'execution_status' => $o->execution_status?->value,
            'submitted_at' => $o->submitted_at?->toIso8601String(),
            'review_due_at' => $o->review_due_at?->toIso8601String(),
            'completion_due_at' => $o->completion_due_at?->toIso8601String(),
            'evaluation_due_at' => $o->evaluation_due_at?->toIso8601String(),
            'overdue' => $o->overdueKind(),
            'is_overdue' => $o->overdueKind() !== null,
            'sla_due_at' => $slaDue?->toIso8601String(),
            'sla_days_left' => $o->slaDaysLeft(),
            // Filtered by the viewer's permissions (ADM-07: not_eligible only for the supervisor).
            'allowed_transitions' => $this->when($isStaff, fn () => array_map(fn ($s): string => $s->value, app(OpportunityStateMachine::class)->allowedTargetsFor($o, $viewer))),
            'timeline' => $o->transitions()->with('actor')->get()->map(function (OpportunityStatusTransition $t) use ($isStaff, $o): array {
                $actorName = $isStaff ? $t->actor?->name : ($t->actor?->id === $o->owner_user_id ? $t->actor?->name : __('messages.council'));

                return [
                    'id' => $t->id,
                    'from' => $t->from_status?->value,
                    'to' => $t->to_status->value,
                    'to_label' => $t->to_status->label(),
                    'from_status' => $t->from_status?->value,
                    'to_status' => $t->to_status->value,
                    'actor' => $t->actor ? ['id' => $isStaff ? $t->actor->id : null, 'name' => $actorName] : null,
                    'actor_name' => $actorName,
                    'reason' => $t->reason,
                    'at' => $t->created_at?->toIso8601String(),
                    'created_at' => $t->created_at?->toIso8601String(),
                ];
            })->values()->all(),
            'created_at' => $o->created_at?->toIso8601String(),
            'updated_at' => $o->updated_at?->toIso8601String(),
        ] + $overview + $impact + $financials + $disclosures + $team + $market + $motivation;

        if ($isStaff) {
            $data['owner'] = [
                'id' => $o->owner?->id,
                'uuid' => $o->owner?->uuid,
                'name' => $o->owner?->name,
                'email' => $o->owner?->email,
                'phone' => $o->owner?->phone,
                'type' => $o->owner?->type?->value,
            ];
            $data['owner_name'] = $o->owner?->name;
            $data['assigned_reviewer'] = $o->assignedReviewer ? ['id' => $o->assignedReviewer->id, 'name' => $o->assignedReviewer->name] : null;
            $data['assignee'] = $data['assigned_reviewer'];
            $data['internal_notes'] = $o->internalNotes()->with('author')->latest('id')->get()->map(fn (InternalNote $n): array => [
                'id' => $n->id, 'author' => $n->author?->name, 'body' => $n->body, 'created_at' => $n->created_at?->toIso8601String(),
            ])->values()->all();
            $data['nominations'] = NominationResource::collection($o->nominations()->with(['opportunity', 'investor', 'declineReason'])->latest('id')->get())->resolve($request);
            $data['nominations_count'] = $o->nominations()->count();
            $data['interests_count'] = $o->interests()->count();
            // ADM-06 — merged typed timeline (status, documents, notes, completion, meetings, nominations); staff only,
            // and only on the file itself (not for every row of the work queue).
            if ($request->routeIs('v1.admin.opportunities.show')) {
                $data['timeline_events'] = app(OpportunityTimeline::class)->events($o);
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function completionRequest(CompletionRequest $c, bool $isStaff): array
    {
        $items = array_values(array_filter((array) $c->items, 'is_array'));
        $notes = array_values(array_filter(array_map(fn (array $i): string => trim((string) ($i['note'] ?? '')), $items)));

        return [
            'id' => $c->id,
            'items' => $items,
            'note' => $notes === [] ? null : implode(' · ', $notes),
            'due_at' => $c->due_at?->toIso8601String(),
            'requested_by' => $c->requester
                ? ['id' => $isStaff ? $c->requester->id : null, 'name' => $isStaff ? $c->requester->name : __('messages.council')]
                : null,
            'resolved_at' => $c->resolved_at?->toIso8601String(),
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }
}

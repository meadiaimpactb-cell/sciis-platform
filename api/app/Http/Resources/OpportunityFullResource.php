<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsOpportunity;
use App\Models\CompletionRequest;
use App\Models\ImpactIndicator;
use App\Models\InternalNote;
use App\Models\OpportunityStatusTransition;
use App\Models\TaxonomyTerm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Opportunity
 *
 * Full record for the owner (ENT) and staff (ADM): every field incl. confidential + internal.
 * Internal notes & evaluation data only for staff (ADM-05). Owner contact only for staff.
 */
class OpportunityFullResource extends JsonResource
{
    use FormatsOpportunity;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $o = $this->resource;
        $o->loadMissing(['sector', 'regions', 'investmentTypes', 'beneficiaryGroups', 'sdgs', 'organization.region', 'teaser', 'fundingRange', 'lastYearRevenueBand', 'indicators.sdg', 'owner', 'assignedReviewer', 'completionRequests']);
        $viewer = $request->user();
        $isStaff = $viewer?->isStaff() ?? false;

        $data = $this->publicIdentity($o) + [
            'id' => $this->when($isStaff, $o->id),
            'is_listed' => $o->is_listed,
            'short_description' => $o->short_description,
            'social_links' => $o->social_links,
            'organization' => $o->organization ? (new OrganizationResource($o->organization))->resolve($request) : null,
            'overview' => [
                'problem_statement' => $o->problem_statement,
                'solution' => $o->solution,
                'expected_change' => $o->expected_change,
            ],
            'impact' => [
                'beneficiaries_desc' => $o->beneficiaries_desc,
                'beneficiaries_current' => $o->beneficiaries_current,
                'beneficiaries_target' => $o->beneficiaries_target,
                'beneficiary_groups' => $o->beneficiaryGroups->map(fn (TaxonomyTerm $t): array => $this->term($t))->values()->all(),
                'sdgs' => $o->sdgs->map(fn (TaxonomyTerm $t): array => $this->term($t))->values()->all(),
            ],
            'indicators' => $o->indicators->map(fn (ImpactIndicator $i): array => $this->indicator($i))->values()->all(),
            'financials' => [
                'revenue_model_type' => $o->revenue_model_type,
                'revenue_model_desc' => $o->revenue_model_desc,
                'revenue_state' => $o->revenue_state?->value,
                'revenue_state_label' => $o->revenue_state?->label(),
                'last_year_revenue_band' => $this->term($o->lastYearRevenueBand),
                'funding_amount' => $o->funding_amount !== null ? (float) $o->funding_amount : null,
                'funding_amount_display' => $o->funding_amount_display?->value,
                'funding_range' => $this->term($o->fundingRange),
                'use_of_funds' => $o->use_of_funds ?? [],
                'prior_funding' => $o->prior_funding,
                'prior_funding_desc' => $o->prior_funding_desc,
            ],
            'disclosures' => [
                'legal_disputes' => $o->legal_disputes,
                'legal_disputes_desc' => $o->legal_disputes_desc,
                'license_required' => $o->license_required,
                'license_status' => $o->license_status,
                'operational_risks' => $o->operational_risks,
                'council_relationship' => $o->council_relationship,
                'council_relationship_desc' => $o->council_relationship_desc,
                'data_accuracy_declared_at' => $o->data_accuracy_declared_at?->toIso8601String(),
            ],
            'teaser' => $o->teaser ? [
                'summary_ar' => $o->teaser->summary_ar,
                'summary_en' => $o->teaser->summary_en,
                'highlights' => $o->teaser->highlights ?? [],
                'version' => $o->teaser->version,
                'approved_by_owner_at' => $o->teaser->approved_by_owner_at?->toIso8601String(),
                'approved_by_council_at' => $o->teaser->approved_by_council_at?->toIso8601String(),
            ] : null,
            'documents' => DocumentResource::collection($o->documents()->orderByDesc('id')->get())->resolve($request),
            'required_now' => $o->completionRequests->whereNull('resolved_at')->map(fn (CompletionRequest $c): array => [
                'id' => $c->id, 'items' => $c->items, 'due_at' => $c->due_at?->toIso8601String(), 'created_at' => $c->created_at?->toIso8601String(),
            ])->values()->all(),
            'evaluation_result' => $o->evaluation_result?->value,
            'evaluation_result_label' => $o->evaluation_result?->label(),
            'execution_status' => $o->execution_status?->value,
            'submitted_at' => $o->submitted_at?->toIso8601String(),
            'review_due_at' => $o->review_due_at?->toIso8601String(),
            'completion_due_at' => $o->completion_due_at?->toIso8601String(),
            'evaluation_due_at' => $o->evaluation_due_at?->toIso8601String(),
            'overdue' => $o->overdueKind(),
            'allowed_transitions' => $this->when($isStaff, fn () => array_map(fn ($s): string => $s->value, app(\App\Services\OpportunityStateMachine::class)->allowedTargets($o->status))),
            'timeline' => $o->transitions()->with('actor')->get()->map(fn (OpportunityStatusTransition $t): array => [
                'from' => $t->from_status?->value,
                'to' => $t->to_status->value,
                'to_label' => $t->to_status->label(),
                'actor' => $isStaff ? $t->actor?->name : ($t->actor?->id === $o->owner_user_id ? $t->actor?->name : __('messages.council')),
                'reason' => $t->reason,
                'at' => $t->created_at?->toIso8601String(),
            ])->values()->all(),
            'created_at' => $o->created_at?->toIso8601String(),
            'updated_at' => $o->updated_at?->toIso8601String(),
        ];

        if ($isStaff) {
            $data['owner'] = [
                'id' => $o->owner?->id,
                'uuid' => $o->owner?->uuid,
                'name' => $o->owner?->name,
                'email' => $o->owner?->email,
                'phone' => $o->owner?->phone,
            ];
            $data['assigned_reviewer'] = $o->assignedReviewer ? ['id' => $o->assignedReviewer->id, 'name' => $o->assignedReviewer->name] : null;
            $data['internal_notes'] = $o->internalNotes()->with('author')->latest('id')->get()->map(fn (InternalNote $n): array => [
                'id' => $n->id, 'author' => $n->author?->name, 'body' => $n->body, 'created_at' => $n->created_at?->toIso8601String(),
            ])->values()->all();
            $data['nominations_count'] = $o->nominations()->count();
            $data['interests_count'] = $o->interests()->count();
        }

        return $data;
    }
}

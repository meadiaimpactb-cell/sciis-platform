<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\FormSchemaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Membership */
class MembershipResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isStaff = $request->user()?->isStaff() ?? false;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'terms_accepted_at' => $this->terms_accepted_at?->toIso8601String(),
            'completion_note' => $this->completion_note,
            'suspended_reason' => $this->suspended_reason,
            'decision_note' => $this->when($isStaff, $this->decision_note),
            'user' => $this->when($isStaff, fn () => (new UserResource($this->user->loadMissing('organization')))->resolve($request)),
            'user_id' => $this->when($isStaff, $this->user_id), // ADM-06 — link to the admin user file
            'profile' => $this->when($isStaff, fn () => $this->user->investorProfile ? (new InvestorProfileResource($this->user->investorProfile))->resolve($request) : null),
            'missing_items' => $this->when($isStaff, fn () => $this->user->investorProfile ? \App\Services\InvestorProfileService::missingItems($this->user->investorProfile) : []),
            'reviewed_by' => $this->when($isStaff, fn () => $this->reviewer?->name),
            'approved_by' => $this->when($isStaff, fn () => $this->approver?->name),
            // ADM-08 — staff member the file is assigned to (bulk assignment from the queue).
            'assigned_reviewer' => $this->when($isStaff, fn () => $this->assignedReviewer ? ['id' => $this->assignedReviewer->id, 'name' => $this->assignedReviewer->name] : null),
            'allowed_transitions' => $this->when($isStaff, fn () => array_map(fn ($s) => $s->value, \App\Enums\MembershipStatus::transitions()[$this->status->value] ?? [])),
            'extra_answers' => $this->when($isStaff, fn () => app(FormSchemaService::class)->present($this->extra_answers)), // FRM-01 — review answers
        ];
    }
}

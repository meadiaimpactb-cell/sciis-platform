<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 *
 * Self / admin view of a user. Never used to expose another party's contact data to investors/owners —
 * see PublicUserResource / contact exchange in OpportunityFullResource (MSG-01).
 */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isSelf = $viewer?->id === $this->id;
        $isAdmin = $viewer?->isStaff() && $viewer->can('users.manage');

        return [
            'uuid' => $this->uuid,
            'id' => $this->when($isAdmin || $isSelf, $this->id),
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'name' => $this->name,
            'email' => $this->when($isSelf || $isAdmin, $this->email),
            'phone' => $this->when($isSelf || $isAdmin, $this->phone),
            'locale' => $this->locale,
            'status' => $this->status->value,
            'preferred_contact' => $this->when($isSelf || $isAdmin, $this->preferred_contact),
            'email_verified' => $this->email_verified_at !== null,
            'phone_verified' => $this->phone_verified_at !== null,
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'roles' => $this->when($this->isStaff(), fn () => $this->getRoleNames()->values()->all()),
            'permissions' => $this->when($isSelf && $this->isStaff(), fn () => $this->getAllPermissions()->pluck('name')->values()->all()),
            'organization' => $this->whenLoaded('organization', fn () => new OrganizationResource($this->organization)),
            'membership' => $this->when($this->isInvestor(), fn () => $this->membership ? [
                'status' => $this->membership->status->value,
                'status_label' => $this->membership->status->label(),
                'submitted_at' => $this->membership->submitted_at?->toIso8601String(),
                'approved_at' => $this->membership->approved_at?->toIso8601String(),
                'terms_accepted_at' => $this->membership->terms_accepted_at?->toIso8601String(),
                'completion_note' => $this->membership->completion_note,
            ] : null),
            'completion_percent' => $this->when($this->isInvestor(), fn () => $this->investorProfile?->completion_percent ?? 0),
            'last_login_at' => $this->when($isSelf || $isAdmin, $this->last_login_at?->toIso8601String()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

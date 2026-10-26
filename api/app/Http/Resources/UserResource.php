<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Controllers\Api\V1\Auth\PreferencesController;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

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
            'status' => $this->status?->value ?? 'active', // DB default not yet hydrated right after create()
            'preferred_contact' => $this->when($isSelf || $isAdmin, $this->preferred_contact),
            'email_verified' => $this->email_verified_at !== null,
            'phone_verified' => $this->phone_verified_at !== null,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'phone_verified_at' => $this->phone_verified_at?->toIso8601String(),
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'avatar_url' => $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null,
            'roles' => $this->when($this->isStaff(), fn () => $this->getRoleNames()->values()->all()),
            'permissions' => $this->when($isSelf && $this->isStaff(), fn () => $this->getAllPermissions()->pluck('name')->values()->all()),
            'organization' => $this->whenLoaded('organization', fn () => new OrganizationResource($this->organization)),
            // ACC-08 — organisation colleagues carry the organisation's membership.
            'membership' => $this->when($this->isInvestor(), fn () => ($m = $this->resource->effectiveMembership()) ? [
                'id' => $m->id,
                'status' => $m->status->value,
                'status_label' => $m->status->label(),
                'submitted_at' => $m->submitted_at?->toIso8601String(),
                'approved_at' => $m->approved_at?->toIso8601String(),
                'terms_accepted_at' => $m->terms_accepted_at?->toIso8601String(),
                'completion_note' => $m->completion_note,
            ] : null),
            'organization_access' => $this->when($isSelf, fn () => $this->organization_id !== null ? [
                'is_representative' => $this->resource->isOrganizationRepresentative(),
                'can_act' => $this->resource->canActForOrganization(),
            ] : null),
            'completion_percent' => $this->when($this->isInvestor(), fn () => $this->investorProfile?->completion_percent ?? 0),
            'last_login_at' => $this->when($isSelf || $isAdmin, $this->last_login_at?->toIso8601String()),
            // UX-05 / BRD-04 — display preferences; ACC-09 — national identity verified (Nafath).
            'preferences' => $this->when($isSelf, fn () => PreferencesController::of($this->resource)),
            'national_id_verified_at' => $this->when($isSelf || $isAdmin, $this->national_id_verified_at?->toIso8601String()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

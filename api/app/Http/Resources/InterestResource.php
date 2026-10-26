<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\InterestStatus;
use App\Models\Interest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Interest
 *
 * MSG-01: the owner never sees the investor identity before `connected`; staff always do.
 */
class InterestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isStaff = $viewer?->isStaff() ?? false;
        // ACC-08 — colleagues of an institutional investor read the organisation's interests as the investor side.
        $isInvestor = $viewer?->id === $this->investor_user_id
            || ($viewer !== null && $viewer->isInvestor() && $viewer->organization_id !== null && in_array($this->investor_user_id, $viewer->organizationPeerIds(), true));
        $connected = in_array($this->status, [InterestStatus::Connected, InterestStatus::Meeting], true);

        $o = $this->opportunity;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'opportunity' => ['uuid' => $o->uuid, 'name' => $o->name, 'reference_no' => $o->reference_no, 'status' => $o->status->value],
            'owner_decision' => $this->owner_decision?->value,
            'owner_decided_at' => $this->owner_decided_at?->toIso8601String(),
            'owner_decision_due_at' => $this->when(! $isInvestor, $this->owner_decision_due_at?->toIso8601String()),
            'note' => $this->note,
            'decline_reason' => $this->declineReason ? ['id' => $this->declineReason->id, 'label' => $this->declineReason->label()] : null,
            'closed_reason' => $this->closed_reason,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'nomination_id' => $this->nomination_id,
            // ENT-06 — full-file grant state (owner approved & not revoked) and whether the owner may revoke it now.
            'access_active' => $this->resource->hasOwnerGrant(),
            'access_revoked_at' => $this->resource->accessGrant()?->revoked_at?->toIso8601String(),
            'can_revoke' => $this->when(! $isInvestor && ! $isStaff, fn (): bool => $this->resource->hasOwnerGrant() && $viewer !== null && $o->isOwnedBy($viewer)),
            // Staff: the valid next states of the interest state machine (web shows one button per target).
            'allowed_transitions' => $this->when($isStaff, fn () => array_map(fn (InterestStatus $s): string => $s->value, InterestStatus::transitions()[$this->status->value] ?? [])),
            'investor' => $this->when($isStaff || $connected, fn () => [
                'id' => $this->when($isStaff, $this->investor?->id),
                'uuid' => $this->investor?->uuid,
                'name' => $this->investor?->name,
                'type' => $this->investor?->type->value,
                'email' => $this->when($isStaff || $connected, $this->investor?->email),
                'phone' => $this->when($isStaff || $connected, $this->investor?->phone),
            ]),
            'investor_profile_summary' => $this->when(! $isStaff && ! $connected && ! $isInvestor, fn () => $this->anonymousProfile()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** Anonymous context for the owner's decision: investor type + risk appetite only. @return array<string, mixed> */
    private function anonymousProfile(): array
    {
        $profile = $this->investor?->investorProfile;

        return [
            'investor_type' => $this->investor?->type->label(),
            'risk_appetite' => $profile?->risk_appetite?->label(),
            'mentoring_available' => $profile?->mentoring_available,
        ];
    }
}

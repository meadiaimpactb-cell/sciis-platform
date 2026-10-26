<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Nomination */
class NominationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isStaff = $request->user()?->isStaff() ?? false;

        return [
            'id' => $this->id,
            'opportunity' => (new OpportunityCardResource($this->opportunity))->resolve($request),
            'match_score' => $this->match_score,
            'matched_criteria' => array_map(fn (string $c): array => ['key' => $c, 'label' => __('enums.match_criteria.'.$c)], (array) $this->matched_criteria),
            'matched' => \App\Services\MatchingService::matchedFlags((array) $this->matched_criteria),
            'rationale' => $this->rationale,
            'teaser_version' => $this->teaser_version,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'response' => $this->response?->value,
            'decline_reason' => $this->declineReason ? ['id' => $this->declineReason->id, 'label' => $this->declineReason->label()] : null,
            'decline_note' => $this->decline_note,
            'responded_at' => $this->responded_at?->toIso8601String(),
            'investor' => $this->when($isStaff, fn () => ['id' => $this->investor?->id, 'uuid' => $this->investor?->uuid, 'name' => $this->investor?->name]),
        ];
    }
}

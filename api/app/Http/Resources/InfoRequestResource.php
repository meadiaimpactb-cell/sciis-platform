<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\InfoRequest — investor identity never shown to the owner (OPP-05, MSG-01). */
class InfoRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isStaff = $request->user()?->isStaff() ?? false;

        return [
            'id' => $this->id,
            'opportunity' => ['uuid' => $this->opportunity->uuid, 'name' => $this->opportunity->name, 'reference_no' => $this->opportunity->reference_no],
            'subject' => $this->subject,
            'body' => $this->body,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'forwarded_to_owner_at' => $this->forwarded_to_owner_at?->toIso8601String(),
            'owner_answer' => $this->owner_answer,
            'answered_at' => $this->answered_at?->toIso8601String(),
            'investor' => $this->when($isStaff, fn () => ['id' => $this->investor?->id, 'name' => $this->investor?->name]),
            'handled_by' => $this->when($isStaff, fn () => $this->handler?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

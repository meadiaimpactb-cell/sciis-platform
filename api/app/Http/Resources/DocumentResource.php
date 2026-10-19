<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Document — never exposes disk/path; download via signed URL endpoint (DOC-02). */
class DocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isStaffOrOwner = $viewer !== null && ($viewer->isStaff() || $viewer->id === $this->owner_user_id);

        return [
            'uuid' => $this->uuid,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'title' => $this->title,
            'mime' => $this->mime,
            'size_bytes' => $this->size_bytes,
            'sha256' => $this->sha256,
            'version' => $this->version,
            'sensitivity' => $this->sensitivity->value,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'scan_result' => $this->scan_result->value,
            'downloadable' => $this->isDownloadable(),
            'reviewer_note' => $this->when($isStaffOrOwner, $this->reviewer_note),
            'uploaded_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

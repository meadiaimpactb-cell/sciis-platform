<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Document;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Document — never exposes disk/path; download via signed URL endpoint (DOC-02).
 *
 * DOC-03 — version history: `version`, `previous_version_uuid` and `uploaded_by` + `uploaded_at` per version, for
 * staff and the owner side only. A staff uploader's name is shown to staff only; owners see "SCIIS team"
 * (same rule as `handled_by` on info requests). Investors never get uploader details.
 */
class DocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isStaff = $viewer?->isStaff() ?? false;
        $isOwnerSide = $viewer !== null && ($viewer->id === $this->owner_user_id || $this->parentOwnedBy($viewer));
        $isStaffOrOwner = $isStaff || $isOwnerSide;

        return [
            'uuid' => $this->uuid,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'title' => $this->title,
            'mime' => $this->mime,
            'size_bytes' => $this->size_bytes,
            'sha256' => $this->sha256,
            'version' => $this->version,
            'previous_version_uuid' => $this->when($isStaffOrOwner, fn () => $this->supersedes?->uuid),
            'sensitivity' => $this->sensitivity->value,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'scan_result' => $this->scan_result->value,
            'downloadable' => $this->isDownloadable(),
            'reviewer_note' => $this->when($isStaffOrOwner, $this->reviewer_note),
            'uploaded_by' => $this->when($isStaffOrOwner, fn () => $this->uploader($isStaff)),
            'uploaded_at' => $this->created_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** @return array{name: string, is_staff: bool}|null */
    private function uploader(bool $viewerIsStaff): ?array
    {
        $user = $this->owner;
        if (! $user instanceof User) {
            return null;
        }
        if ($user->isStaff() && ! $viewerIsStaff) {
            return ['name' => __('messages.documents.council_team'), 'is_staff' => true];
        }

        return ['name' => (string) $user->name, 'is_staff' => $user->isStaff()];
    }

    /** Opportunity owner viewing a version uploaded by someone else (only when the parent is already loaded). */
    private function parentOwnedBy(User $viewer): bool
    {
        if (! $this->resource->relationLoaded('documentable')) {
            return false;
        }
        $parent = $this->documentable;

        return $parent instanceof Opportunity && (int) $parent->owner_user_id === (int) $viewer->id;
    }
}

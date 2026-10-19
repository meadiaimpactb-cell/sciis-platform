<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Organization */
class OrganizationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $full = $viewer !== null && ($viewer->isStaff() || $viewer->organization_id === $this->id || $viewer->id === $this->representative_user_id);

        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'legal_form' => $this->legal_form?->value,
            'legal_form_label' => $this->legal_form?->label(),
            'institution_type' => $this->institution_type?->value,
            'founded_year' => $this->foundedYear(),
            'website' => $this->website,
            'city' => $this->city,
            'region' => $this->whenLoaded('region', fn () => new TaxonomyTermResource($this->region)),
            'cr_number' => $this->when($full, $this->cr_number),
            'unified_number' => $this->when($full, $this->unified_number),
            'license_number' => $this->when($full, $this->license_number),
            'employees_count' => $this->when($full, $this->employees_count),
        ];
    }
}

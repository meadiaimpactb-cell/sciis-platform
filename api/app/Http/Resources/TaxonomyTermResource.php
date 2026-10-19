<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TaxonomyTerm */
class TaxonomyTermResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'group' => $this->group,
            'key' => $this->key,
            'label' => $this->label(),
            'label_ar' => $this->label_ar,
            'label_en' => $this->label_en,
            'sort' => $this->sort,
            'is_active' => $this->is_active,
            'meta' => $this->meta,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AgreementTemplate;
use App\Services\Agreements\AgreementPlaceholders;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AGR-01 — one template version (Arabic + English text) and the placeholders it uses.
 *
 * @mixin AgreementTemplate
 */
class AgreementTemplateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AgreementTemplate $t */
        $t = $this->resource;

        return [
            'id' => $t->id,
            'key' => $t->key,
            'version' => $t->version,
            'title' => $t->title,
            'title_en' => $t->title_en,
            'body' => $t->body,
            'body_en' => $t->body_en,
            'description' => $t->description,
            'change_note' => $t->change_note,
            'is_active' => $t->is_active,
            'legal_approved_at' => $t->legal_approved_at?->toIso8601String(),
            'placeholders' => AgreementPlaceholders::keys($t->body, $t->body_en),
            'created_by' => $t->creator ? ['id' => $t->creator->id, 'name' => $t->creator->name] : null,
            'created_at' => $t->created_at?->toIso8601String(),
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasTaxonomyLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * FRM-04 — staff-editable taxonomies (sectors, regions, investment_types, beneficiary_groups, sdgs,
 * project_stages, investment_ranges, decline_reasons, how_heard, revenue_bands, annual_investment_bands).
 */
class TaxonomyTerm extends Model
{
    use HasFactory, HasTaxonomyLabel;

    public const GROUPS = [
        'sectors', 'regions', 'investment_types', 'beneficiary_groups', 'sdgs', 'project_stages',
        'investment_ranges', 'decline_reasons', 'how_heard', 'revenue_bands', 'annual_investment_bands', 'causes',
    ];

    protected $fillable = ['group', 'key', 'label_ar', 'label_en', 'sort', 'is_active', 'meta'];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function scopeGroup(Builder $query, string $group): Builder
    {
        return $query->where('group', $group);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }
}

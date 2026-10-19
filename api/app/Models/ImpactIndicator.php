<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IndicatorFrequency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImpactIndicator extends Model
{
    protected $fillable = [
        'opportunity_id', 'name', 'definition', 'unit', 'baseline', 'target', 'data_source', 'frequency',
        'verification_method', 'iris_code', 'sdg_id', 'is_primary', 'is_disclosed',
    ];

    protected function casts(): array
    {
        return [
            'baseline' => 'decimal:2',
            'target' => 'decimal:2',
            'frequency' => IndicatorFrequency::class,
            'is_primary' => 'boolean',
            'is_disclosed' => 'boolean',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function sdg(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'sdg_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(IndicatorValue::class);
    }
}

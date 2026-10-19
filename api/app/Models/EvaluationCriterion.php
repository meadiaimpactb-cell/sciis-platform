<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EvaluationAxis;
use App\Models\Concerns\HasTaxonomyLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** EVL-01 — versioned criteria (readiness / feasibility / impact), 5-point scale. */
class EvaluationCriterion extends Model
{
    use HasTaxonomyLabel;

    protected $table = 'evaluation_criteria';

    protected $fillable = ['axis', 'key', 'label_ar', 'label_en', 'weight', 'scale_descriptions', 'version', 'is_active', 'sort'];

    protected function casts(): array
    {
        return [
            'axis' => EvaluationAxis::class,
            'weight' => 'decimal:2',
            'scale_descriptions' => 'array',
            'version' => 'integer',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeVersion(Builder $query, int $version): Builder
    {
        return $query->where('version', $version);
    }

    public static function currentVersion(): int
    {
        return (int) (static::query()->active()->max('version') ?? 1);
    }
}

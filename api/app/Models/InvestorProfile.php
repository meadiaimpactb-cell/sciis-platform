<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RiskAppetite;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class InvestorProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'country', 'city', 'employer', 'job_title', 'linkedin_url', 'experience_years', 'experience_desc',
        'prior_investments_count', 'prior_investments_desc', 'has_social_investments', 'social_investments_desc',
        'risk_appetite', 'mentoring_available', 'mentoring_areas', 'accredited_declaration', 'investment_range_id',
        'annual_investment_band_id', 'preferred_contact', 'how_heard_id', 'cv_document_id', 'conflicts_disclosure',
        'commit_meetings', 'completion_percent', 'reminder_last_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'experience_years' => 'integer',
            'prior_investments_count' => 'integer',
            'has_social_investments' => 'boolean',
            'mentoring_available' => 'boolean',
            'commit_meetings' => 'boolean',
            'risk_appetite' => RiskAppetite::class,
            'accredited_declaration' => 'array',
            'completion_percent' => 'integer',
            'reminder_last_sent_at' => 'datetime',
        ];
    }

    /** Fields counted for completion_percent (INV-01). */
    public const COMPLETION_FIELDS = [
        'country', 'city', 'job_title', 'experience_years', 'experience_desc', 'prior_investments_count',
        'risk_appetite', 'investment_range_id', 'preferred_contact', 'how_heard_id', 'conflicts_disclosure', 'commit_meetings',
    ];

    public const COMPLETION_RELATIONS = ['sectors', 'stages', 'investmentTypes', 'regions'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sectors(): BelongsToMany
    {
        return $this->belongsToMany(TaxonomyTerm::class, 'investor_profile_sectors', 'investor_profile_id', 'sector_id');
    }

    public function stages(): BelongsToMany
    {
        return $this->belongsToMany(TaxonomyTerm::class, 'investor_profile_stages', 'investor_profile_id', 'stage_id');
    }

    public function investmentTypes(): BelongsToMany
    {
        return $this->belongsToMany(TaxonomyTerm::class, 'investor_profile_investment_types', 'investor_profile_id', 'investment_type_id');
    }

    public function regions(): BelongsToMany
    {
        return $this->belongsToMany(TaxonomyTerm::class, 'investor_profile_regions', 'investor_profile_id', 'region_id');
    }

    public function causes(): BelongsToMany
    {
        return $this->belongsToMany(TaxonomyTerm::class, 'investor_profile_causes', 'investor_profile_id', 'cause_id');
    }

    public function investmentRange(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'investment_range_id');
    }

    public function annualInvestmentBand(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'annual_investment_band_id');
    }

    public function howHeard(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'how_heard_id');
    }

    public function cvDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'cv_document_id');
    }

    /** Recomputes completion_percent from filled fields + taxonomy selections. */
    public function computeCompletion(): int
    {
        $total = count(self::COMPLETION_FIELDS) + count(self::COMPLETION_RELATIONS);
        $filled = 0;
        foreach (self::COMPLETION_FIELDS as $field) {
            $value = $this->getAttribute($field);
            if ($value !== null && $value !== '' && $value !== []) {
                $filled++;
            }
        }
        foreach (self::COMPLETION_RELATIONS as $relation) {
            if ($this->{$relation}()->exists()) {
                $filled++;
            }
        }

        return (int) round($filled / max(1, $total) * 100);
    }
}

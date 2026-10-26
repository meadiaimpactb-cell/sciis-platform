<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentKind;
use App\Enums\RiskAppetite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class InvestorProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'country', 'city', 'employer', 'job_title', 'linkedin_url', 'experience_years', 'experience_desc',
        'prior_investments_count', 'prior_investments_desc', 'has_social_investments', 'social_investments_desc',
        'risk_appetite', 'mentoring_available', 'mentoring_areas', 'impact_policy', 'accredited_declaration', 'investment_range_id',
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
            'extra_answers' => 'array', // FRM-01 — {key, version, answers, answered_at}
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

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * INV-01 — every version of the membership documents: the CV (on the profile) and, for an institution,
     * the commercial register / authorization letter / impact policy (on the organization). Newest first.
     *
     * @return Collection<int, Document>
     */
    public function membershipDocuments(): Collection
    {
        $user = $this->relationLoaded('user') ? $this->user : $this->user()->first();
        $orgKinds = array_map(fn (DocumentKind $k): string => $k->value, DocumentKind::forInvestor(true));

        return Document::query()
            ->with(['owner:id,name,type', 'supersedes:id,uuid'])
            ->where(function (Builder $q) use ($user, $orgKinds): void {
                $q->where(fn (Builder $p) => $p->where('documentable_type', $this->getMorphClass())->where('documentable_id', $this->getKey()));
                if ($user?->organization_id !== null) {
                    $q->orWhere(fn (Builder $o) => $o->where('documentable_type', (new Organization)->getMorphClass())
                        ->where('documentable_id', $user->organization_id)
                        ->whereIn('kind', $orgKinds));
                }
            })
            ->orderByDesc('id')
            ->get();
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

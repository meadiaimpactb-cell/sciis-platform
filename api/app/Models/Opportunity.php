<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccessPolicy;
use App\Enums\EvaluationResult;
use App\Enums\ExecutionStatus;
use App\Enums\FundingDisplay;
use App\Enums\InterestStatus;
use App\Enums\InvestorAccessLevel;
use App\Enums\OpportunityStatus;
use App\Enums\ProjectStage;
use App\Enums\RevenueState;
use App\Models\Concerns\HasUuid;
use App\Services\SettingsService;
use Database\Factories\OpportunityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $uuid
 * @property ?string $reference_no
 * @property int $owner_user_id
 * @property OpportunityStatus $status
 * @property ?EvaluationResult $evaluation_result
 * @property bool $is_listed
 */
class Opportunity extends Model
{
    /** @use HasFactory<OpportunityFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'reference_no', 'owner_user_id', 'organization_id', 'name', 'short_description', 'sector_id', 'stage', 'status', 'is_listed',
        'website', 'social_links', 'problem_statement', 'solution', 'expected_change', 'beneficiaries_desc',
        'beneficiaries_current', 'beneficiaries_target', 'revenue_model_type', 'revenue_model_desc', 'revenue_state',
        'last_year_revenue_band_id', 'funding_amount', 'funding_amount_display', 'funding_range_id', 'use_of_funds',
        'prior_funding', 'prior_funding_desc', 'legal_disputes', 'legal_disputes_desc', 'license_required',
        'license_status', 'operational_risks', 'council_relationship', 'council_relationship_desc',
        'data_accuracy_declared_at', 'submitted_at', 'assigned_reviewer_id', 'review_due_at', 'completion_due_at',
        'evaluation_due_at', 'evaluation_result', 'evaluation_approved_at', 'execution_status',
    ];

    /** Sections → fields map used for PATCH + checklist (ENT-02, ENT-05). */
    public const SECTIONS = [
        'basics' => ['name', 'short_description', 'sector_id', 'stage', 'website', 'social_links', 'regions', 'investment_types'],
        'organization' => ['organization_id'],
        'impact' => ['problem_statement', 'solution', 'expected_change', 'beneficiaries_desc', 'beneficiaries_current', 'beneficiaries_target', 'beneficiary_groups', 'sdgs'],
        'financials' => ['revenue_model_type', 'revenue_model_desc', 'revenue_state', 'last_year_revenue_band_id', 'funding_amount', 'funding_amount_display', 'funding_range_id', 'use_of_funds', 'prior_funding', 'prior_funding_desc'],
        'disclosures' => ['legal_disputes', 'legal_disputes_desc', 'license_required', 'license_status', 'operational_risks', 'council_relationship', 'council_relationship_desc', 'data_accuracy_declared_at'],
    ];

    /** Fields required before submit (ENT-02 checklist). */
    public const REQUIRED_FOR_SUBMIT = [
        'name', 'short_description', 'sector_id', 'stage', 'problem_statement', 'solution', 'expected_change',
        'beneficiaries_desc', 'beneficiaries_target', 'revenue_state', 'funding_amount', 'use_of_funds',
        'data_accuracy_declared_at',
    ];

    public const REQUIRED_RELATIONS_FOR_SUBMIT = ['regions', 'investmentTypes'];

    public const REQUIRED_DOCUMENT_KINDS = ['pitch_deck'];

    protected function casts(): array
    {
        return [
            'stage' => ProjectStage::class,
            'status' => OpportunityStatus::class,
            'revenue_state' => RevenueState::class,
            'evaluation_result' => EvaluationResult::class,
            'execution_status' => ExecutionStatus::class,
            'funding_amount_display' => FundingDisplay::class,
            'is_listed' => 'boolean',
            'social_links' => 'array',
            'use_of_funds' => 'array',
            'prior_funding' => 'boolean',
            'legal_disputes' => 'boolean',
            'license_required' => 'boolean',
            'council_relationship' => 'boolean',
            'beneficiaries_current' => 'integer',
            'beneficiaries_target' => 'integer',
            'funding_amount' => 'decimal:2',
            'data_accuracy_declared_at' => 'datetime',
            'submitted_at' => 'datetime',
            'review_due_at' => 'datetime',
            'completion_due_at' => 'datetime',
            'evaluation_due_at' => 'datetime',
            'evaluation_approved_at' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    protected static function newFactory(): OpportunityFactory
    {
        return OpportunityFactory::new();
    }

    // ---------------------------------------------------------------- relations

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'sector_id');
    }

    public function fundingRange(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'funding_range_id');
    }

    public function lastYearRevenueBand(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'last_year_revenue_band_id');
    }

    public function assignedReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_reviewer_id');
    }

    public function regions(): BelongsToMany
    {
        return $this->belongsToMany(TaxonomyTerm::class, 'opportunity_regions', 'opportunity_id', 'region_id');
    }

    public function investmentTypes(): BelongsToMany
    {
        return $this->belongsToMany(TaxonomyTerm::class, 'opportunity_investment_types', 'opportunity_id', 'investment_type_id');
    }

    public function beneficiaryGroups(): BelongsToMany
    {
        return $this->belongsToMany(TaxonomyTerm::class, 'opportunity_beneficiary_groups', 'opportunity_id', 'beneficiary_group_id');
    }

    public function sdgs(): BelongsToMany
    {
        return $this->belongsToMany(TaxonomyTerm::class, 'opportunity_sdgs', 'opportunity_id', 'sdg_id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(OpportunityStatusTransition::class)->orderBy('id');
    }

    public function teaser(): HasOne
    {
        return $this->hasOne(Teaser::class);
    }

    public function indicators(): HasMany
    {
        return $this->hasMany(ImpactIndicator::class);
    }

    public function primaryIndicator(): HasOne
    {
        return $this->hasOne(ImpactIndicator::class)->where('is_primary', true);
    }

    public function completionRequests(): HasMany
    {
        return $this->hasMany(CompletionRequest::class);
    }

    public function internalNotes(): MorphMany
    {
        return $this->morphMany(InternalNote::class, 'notable');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function evaluationAssignments(): HasMany
    {
        return $this->hasMany(EvaluationAssignment::class);
    }

    public function evaluationDecisions(): HasMany
    {
        return $this->hasMany(EvaluationDecision::class);
    }

    public function latestDecision(): HasOne
    {
        return $this->hasOne(EvaluationDecision::class)->latestOfMany('decided_at');
    }

    public function nominations(): HasMany
    {
        return $this->hasMany(Nomination::class);
    }

    public function interests(): HasMany
    {
        return $this->hasMany(Interest::class);
    }

    public function infoRequests(): HasMany
    {
        return $this->hasMany(InfoRequest::class);
    }

    public function fileAccessGrants(): HasMany
    {
        return $this->hasMany(FileAccessGrant::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    public function savedBy(): HasMany
    {
        return $this->hasMany(SavedOpportunity::class);
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class);
    }

    public function progressReports(): HasMany
    {
        return $this->hasMany(ProgressReport::class);
    }

    public function agreements(): HasMany
    {
        return $this->hasMany(Agreement::class);
    }

    // ---------------------------------------------------------------- scopes

    public function scopeStatus(Builder $query, OpportunityStatus ...$statuses): Builder
    {
        return $query->whereIn('status', array_map(fn (OpportunityStatus $s): string => $s->value, $statuses));
    }

    /** Has a teaser approved by both council and owner (precondition for investor visibility). */
    public function scopeWithApprovedTeaser(Builder $query): Builder
    {
        return $query->whereHas('teaser', function (Builder $q): void {
            $q->whereNotNull('approved_by_owner_at')->whereNotNull('approved_by_council_at');
        });
    }

    /**
     * Opportunities an approved investor may see (SPEC §3 visibility, doc §6.6):
     *  - never draft/new/under_review/needs_completion/not_eligible;
     *  - eligible with approved teaser, filtered by `opportunity.access_policy`:
     *      nomination_only → only those the investor was nominated to;
     *      browse_all      → all;
     *      both            → all (nominated ones are flagged in the resource);
     *  - connected+ → listed ones, or ones the investor is connected to.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $policy = app(SettingsService::class)->accessPolicy();
        $hidden = array_map(fn (OpportunityStatus $s): string => $s->value, OpportunityStatus::hiddenFromInvestors());

        return $query
            ->whereNotIn('status', $hidden)
            ->where(function (Builder $q) use ($user, $policy): void {
                // Eligible + approved teaser (+ nomination when policy demands it).
                $q->where(function (Builder $eligible) use ($user, $policy): void {
                    $eligible->where('status', OpportunityStatus::Eligible->value)
                        ->withApprovedTeaser();
                    if ($policy === AccessPolicy::NominationOnly) {
                        $eligible->whereHas('nominations', fn (Builder $n) => $n->where('investor_user_id', $user->id));
                    }
                });

                // Connected or later: listed, or the investor is party to it.
                $q->orWhere(function (Builder $later) use ($user): void {
                    $later->whereIn('status', array_map(fn (OpportunityStatus $s): string => $s->value, OpportunityStatus::connectedOrLater()))
                        ->where(function (Builder $w) use ($user): void {
                            $w->where('is_listed', true)
                                ->orWhereHas('interests', fn (Builder $i) => $i->where('investor_user_id', $user->id)
                                    ->whereIn('status', [InterestStatus::Connected->value, InterestStatus::Meeting->value]));
                        });
                });
            });
    }

    public function scopeOverdue(Builder $query): Builder
    {
        $now = now();

        return $query->where(function (Builder $q) use ($now): void {
            $q->where(fn (Builder $w) => $w->where('status', OpportunityStatus::New->value)->where('review_due_at', '<', $now))
                ->orWhere(fn (Builder $w) => $w->where('status', OpportunityStatus::NeedsCompletion->value)->where('completion_due_at', '<', $now))
                ->orWhere(fn (Builder $w) => $w->where('status', OpportunityStatus::UnderReview->value)->where('evaluation_due_at', '<', $now));
        });
    }

    // ---------------------------------------------------------------- helpers

    public function hasApprovedTeaser(): bool
    {
        $teaser = $this->relationLoaded('teaser') ? $this->teaser : $this->teaser()->first();

        return $teaser !== null && $teaser->isFullyApproved();
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_user_id === $user->id;
    }

    public function activeGrantFor(User $user): ?FileAccessGrant
    {
        return $this->fileAccessGrants()
            ->where('investor_user_id', $user->id)
            ->whereNotNull('granted_by_owner_at')
            ->whereNotNull('nda_consent_id')
            ->whereNull('revoked_at')
            ->first();
    }

    public function hasActiveGrantFor(User $user): bool
    {
        return $this->activeGrantFor($user) !== null;
    }

    public function isConnectedTo(User $user): bool
    {
        return $this->interests()
            ->where('investor_user_id', $user->id)
            ->whereIn('status', [InterestStatus::Connected->value, InterestStatus::Meeting->value])
            ->exists();
    }

    /** Resolves the caller's access level for this opportunity (SPEC §1.3). */
    public function accessLevelFor(?User $user): InvestorAccessLevel
    {
        if ($user === null) {
            return InvestorAccessLevel::Visitor;
        }
        if ($this->isConnectedTo($user)) {
            return InvestorAccessLevel::Connected;
        }
        if ($this->hasActiveGrantFor($user)) {
            return InvestorAccessLevel::Granted;
        }
        if ($user->isApprovedMember()) {
            return InvestorAccessLevel::ApprovedMember;
        }
        if ($user->isFullyVerified()) {
            return InvestorAccessLevel::Registered;
        }

        return InvestorAccessLevel::Visitor;
    }

    public function overdueKind(): ?string
    {
        $now = now();

        return match (true) {
            $this->status === OpportunityStatus::New && $this->review_due_at !== null && $this->review_due_at->lt($now) => 'review',
            $this->status === OpportunityStatus::NeedsCompletion && $this->completion_due_at !== null && $this->completion_due_at->lt($now) => 'completion',
            $this->status === OpportunityStatus::UnderReview && $this->evaluation_due_at !== null && $this->evaluation_due_at->lt($now) => 'evaluation',
            default => null,
        };
    }

    public function fundingDisplay(): FundingDisplay
    {
        return $this->funding_amount_display ?? app(SettingsService::class)->fundingDisplay();
    }
}

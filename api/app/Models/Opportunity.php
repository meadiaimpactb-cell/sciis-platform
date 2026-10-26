<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccessPolicy;
use App\Enums\ConsentType;
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
use Illuminate\Support\Carbon;

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
        'name_en', 'short_description_en', // OPP-11 — optional English card fields
        'website', 'social_links', 'problem_statement', 'solution', 'expected_change', 'beneficiaries_desc',
        'beneficiaries_current', 'beneficiaries_target', 'revenue_model_type', 'revenue_model_desc', 'revenue_state',
        'last_year_revenue_band_id', 'funding_amount', 'funding_amount_display', 'funding_range_id', 'use_of_funds',
        'prior_funding', 'prior_funding_desc', 'legal_disputes', 'legal_disputes_desc', 'license_required',
        'license_status', 'operational_risks', 'council_relationship', 'council_relationship_desc',
        'data_accuracy_declared_at', 'submitted_at', 'assigned_reviewer_id', 'review_due_at', 'completion_due_at',
        'evaluation_due_at', 'stage_due_at', 'evaluation_result', 'evaluation_approved_at', 'execution_status',
        // team (confidential)
        'founders_count', 'team_size', 'founders_commitment', 'founders_commitment_note', 'prior_startup_experience',
        'prior_startup_experience_desc', 'industry_experience', 'industry_experience_desc', 'founders_known_years',
        'has_advisory_board', 'advisory_board_desc', 'dev_team_structure', 'founders_agreement_signed', 'founders',
        // market & product
        'target_customers', 'market_size_desc', 'competitors', 'competitive_advantage', 'go_to_market', 'product_stage',
        'technology_desc', 'traction_desc', 'scalability_desc',
        // motivation (internal)
        'why_now', 'support_needed', 'program_expectations', 'how_heard_id',
    ];

    /** Team & founders (sensitivity "س" — never on the card / detail before grant). */
    public const TEAM_FIELDS = [
        'founders_count', 'team_size', 'founders_commitment', 'founders_commitment_note', 'prior_startup_experience',
        'prior_startup_experience_desc', 'industry_experience', 'industry_experience_desc', 'founders_known_years',
        'has_advisory_board', 'advisory_board_desc', 'dev_team_structure', 'founders_agreement_signed', 'founders',
    ];

    /** Market & product. Only MARKET_PUBLIC_FIELDS reach investors (detail resource); the rest are staff/owner only. */
    public const MARKET_FIELDS = [
        'target_customers', 'market_size_desc', 'competitors', 'competitive_advantage', 'go_to_market', 'product_stage',
        'technology_desc', 'traction_desc', 'scalability_desc',
    ];

    public const MARKET_PUBLIC_FIELDS = ['product_stage', 'target_customers', 'competitive_advantage', 'traction_desc'];

    /** Motivation & commitment (sensitivity "د" — council only). */
    public const MOTIVATION_FIELDS = ['why_now', 'support_needed', 'program_expectations', 'how_heard_id'];

    public const FOUNDERS_COMMITMENTS = ['full_time', 'part_time', 'transitioning'];

    public const FOUNDERS_KNOWN_YEARS = ['lt_1', '1_3', '3_5', '5_plus'];

    public const DEV_TEAM_STRUCTURES = ['in_house', 'outsourced', 'hybrid', 'none'];

    public const PRODUCT_STAGES = ['idea', 'prototype', 'mvp', 'launched', 'scaling'];

    public const SUPPORT_NEEDS = ['mentoring', 'investor_intros', 'market_access', 'technical', 'legal', 'impact_measurement'];

    public const MAX_FOUNDERS = 6;

    /** Sections → fields map used for PATCH + checklist (ENT-02, ENT-05). */
    public const SECTIONS = [
        'basics' => ['name', 'short_description', 'name_en', 'short_description_en', 'sector_id', 'stage', 'website', 'social_links', 'regions', 'investment_types'],
        'organization' => ['organization_id'],
        'team' => self::TEAM_FIELDS,
        'impact' => ['problem_statement', 'solution', 'expected_change', 'beneficiaries_desc', 'beneficiaries_current', 'beneficiaries_target', 'beneficiary_groups', 'sdgs'],
        'market' => self::MARKET_FIELDS,
        'financials' => ['revenue_model_type', 'revenue_model_desc', 'revenue_state', 'last_year_revenue_band_id', 'funding_amount', 'funding_amount_display', 'funding_range_id', 'use_of_funds', 'prior_funding', 'prior_funding_desc'],
        'motivation' => self::MOTIVATION_FIELDS,
        'disclosures' => ['legal_disputes', 'legal_disputes_desc', 'license_required', 'license_status', 'operational_risks', 'council_relationship', 'council_relationship_desc', 'data_accuracy_declared_at'],
    ];

    /**
     * Web/mobile form steps → fields (the clients split the API `impact` section into `project` + `impact`
     * and have `documents` as its own step). Used for `locked_sections` / checklist `items[].section`.
     * Order = wizard order: team after basics/organization, market after impact, motivation before disclosures.
     */
    public const WEB_SECTIONS = [
        'basics' => ['name', 'short_description', 'name_en', 'short_description_en', 'sector_id', 'stage', 'website', 'social_links', 'regions', 'investment_types', 'region_ids', 'investment_type_ids', 'investment_type_keys'],
        'organization' => ['organization_id', 'organization'],
        'team' => self::TEAM_FIELDS,
        'project' => ['problem_statement', 'solution', 'expected_change', 'beneficiaries_desc', 'beneficiaries_current', 'beneficiaries_target', 'beneficiary_groups', 'beneficiary_group_ids', 'sdgs', 'sdg_ids', 'revenue_model_type', 'revenue_model_desc', 'revenue_state'],
        'impact' => ['indicators', 'primary_indicator'],
        'market' => self::MARKET_FIELDS,
        'financials' => ['last_year_revenue_band_id', 'funding_amount', 'funding_amount_display', 'funding_range_id', 'use_of_funds', 'prior_funding', 'prior_funding_desc'],
        'documents' => ['documents'],
        'motivation' => ['why_now', 'support_needed', 'program_expectations', 'how_heard_id', 'how_heard'],
        'disclosures' => ['legal_disputes', 'legal_disputes_desc', 'license_required', 'license_status', 'operational_risks', 'council_relationship', 'council_relationship_desc', 'data_accuracy_declared_at', 'data_accuracy_declared', 'teaser_publication_consent'],
    ];

    /** Request-key ↔ column aliases so a completion item `field` may name either spelling. */
    public const FIELD_ALIASES = [
        'regions' => ['regions', 'region_ids'],
        'region_ids' => ['regions', 'region_ids'],
        'investment_types' => ['investment_types', 'investment_type_ids', 'investment_type_keys', 'investmentTypes'],
        'investment_type_ids' => ['investment_types', 'investment_type_ids', 'investment_type_keys', 'investmentTypes'],
        'investment_type_keys' => ['investment_types', 'investment_type_ids', 'investment_type_keys', 'investmentTypes'],
        'investmentTypes' => ['investment_types', 'investment_type_ids', 'investment_type_keys', 'investmentTypes'],
        'beneficiary_groups' => ['beneficiary_groups', 'beneficiary_group_ids'],
        'beneficiary_group_ids' => ['beneficiary_groups', 'beneficiary_group_ids'],
        'sdgs' => ['sdgs', 'sdg_ids'],
        'sdg_ids' => ['sdgs', 'sdg_ids'],
        'data_accuracy_declared_at' => ['data_accuracy_declared_at', 'data_accuracy_declared'],
        'data_accuracy_declared' => ['data_accuracy_declared_at', 'data_accuracy_declared'],
        'primary_indicator' => ['primary_indicator', 'indicators'],
        'indicators' => ['primary_indicator', 'indicators'],
        'how_heard' => ['how_heard', 'how_heard_id'],
        'how_heard_id' => ['how_heard', 'how_heard_id'],
    ];

    /** Fields required before submit (ENT-02 checklist) — in wizard order. */
    public const REQUIRED_FOR_SUBMIT = [
        'name', 'short_description', 'sector_id', 'stage',
        'founders_count', 'team_size', 'founders_commitment',
        'problem_statement', 'solution', 'expected_change', 'beneficiaries_desc', 'beneficiaries_target', 'revenue_state',
        'product_stage', 'target_customers', 'competitive_advantage',
        'funding_amount', 'use_of_funds',
        'why_now',
        'data_accuracy_declared_at',
    ];

    public const REQUIRED_RELATIONS_FOR_SUBMIT = ['regions', 'investmentTypes'];

    public const REQUIRED_DOCUMENT_KINDS = ['pitch_deck'];

    protected function casts(): array
    {
        return [
            'extra_answers' => 'array', // FRM-01 — {key, version, answers, answered_at}
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
            'founders_count' => 'integer',
            'team_size' => 'integer',
            'prior_startup_experience' => 'boolean',
            'industry_experience' => 'boolean',
            'has_advisory_board' => 'boolean',
            'founders_agreement_signed' => 'boolean',
            'founders' => 'array',
            'support_needed' => 'array',
            'funding_amount' => 'decimal:2',
            'data_accuracy_declared_at' => 'datetime',
            'submitted_at' => 'datetime',
            'review_due_at' => 'datetime',
            'completion_due_at' => 'datetime',
            'evaluation_due_at' => 'datetime',
            'stage_due_at' => 'datetime',
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

    /** Acquisition channel (taxonomy `how_heard`) — motivation section, internal. */
    public function howHeard(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'how_heard_id');
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

    /** Consents whose subject is this opportunity (teaser publication, NDA). PRV-02 */
    public function consents(): MorphMany
    {
        return $this->morphMany(Consent::class, 'subject');
    }

    /** PRV-02: the owner explicitly agreed to show the teaser to approved investors. */
    public function hasTeaserPublicationConsent(): bool
    {
        return $this->consents()->where('type', ConsentType::TeaserPublication->value)->exists();
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
                        $eligible->whereHas('nominations', fn (Builder $n) => $n->whereIn('investor_user_id', $user->organizationPeerIds())); // ACC-08
                    }
                });

                // Connected or later: listed, or the investor is party to it.
                $q->orWhere(function (Builder $later) use ($user): void {
                    $later->whereIn('status', array_map(fn (OpportunityStatus $s): string => $s->value, OpportunityStatus::connectedOrLater()))
                        ->where(function (Builder $w) use ($user): void {
                            $w->where('is_listed', true)
                                ->orWhereHas('interests', fn (Builder $i) => $i->whereIn('investor_user_id', $user->organizationPeerIds())
                                    ->whereIn('status', [InterestStatus::Connected->value, InterestStatus::Meeting->value]));
                        });
                });
            });
    }

    /**
     * ADM-03 — post-evaluation stages with an SLA kept in `stage_due_at`: status → overdue kind (days in `sla.<kind>_days`).
     *
     * @var array<string, string>
     */
    public const STAGE_SLA_KINDS = [
        'eligible' => 'matching',
        'connected' => 'connection',
        'negotiating' => 'negotiation',
    ];

    public function scopeOverdue(Builder $query): Builder
    {
        $now = now();

        return $query->where(function (Builder $q) use ($now): void {
            $q->where(fn (Builder $w) => $w->where('status', OpportunityStatus::New->value)->where('review_due_at', '<', $now))
                ->orWhere(fn (Builder $w) => $w->where('status', OpportunityStatus::NeedsCompletion->value)->where('completion_due_at', '<', $now))
                ->orWhere(fn (Builder $w) => $w->where('status', OpportunityStatus::UnderReview->value)->where('evaluation_due_at', '<', $now))
                ->orWhere(fn (Builder $w) => $w->whereIn('status', array_keys(self::STAGE_SLA_KINDS))->where('stage_due_at', '<', $now));
        });
    }

    // ---------------------------------------------------------------- helpers

    /** OPP-11 — investor-facing name: the English version when the locale is `en` and one was entered, else Arabic. */
    public function displayName(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return ($locale === 'en' && filled($this->name_en)) ? (string) $this->name_en : (string) $this->name;
    }

    /** OPP-11 — investor-facing short description, same fallback rule as displayName(). */
    public function displayShortDescription(?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();

        return ($locale === 'en' && filled($this->short_description_en)) ? (string) $this->short_description_en : $this->short_description;
    }

    public function hasApprovedTeaser(): bool
    {
        $teaser = $this->relationLoaded('teaser') ? $this->teaser : $this->teaser()->first();

        return $teaser !== null && $teaser->isFullyApproved();
    }

    /** ACC-08 — the owner, or a colleague of the owning entity whom the representative allowed to act. */
    public function isOwnedBy(User $user): bool
    {
        if ($this->owner_user_id === $user->id) {
            return true;
        }

        return $this->isSharedWithColleague($user) && $user->canActForOrganization();
    }

    /** ACC-08 — an active colleague of the entrepreneur entity that owns the file (read-only unless "can act"). */
    public function isSharedWithColleague(User $user): bool
    {
        return $this->organization_id !== null
            && $user->organization_id === $this->organization_id
            && $user->isEntrepreneur()
            && $user->isActive()
            && $user->organization_disabled_at === null;
    }

    /** ACC-08 — the files of the caller's account: own ones plus, for an entity colleague, the entity's. */
    public function scopeOfAccount(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user): void {
            $q->where('owner_user_id', $user->id);
            if ($user->organization_id !== null && $user->isEntrepreneur()) {
                $q->orWhere('organization_id', $user->organization_id);
            }
        });
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
            isset(self::STAGE_SLA_KINDS[$this->status->value]) && $this->stage_due_at !== null && $this->stage_due_at->lt($now) => self::STAGE_SLA_KINDS[$this->status->value],
            default => null,
        };
    }

    public function fundingDisplay(): FundingDisplay
    {
        return $this->funding_amount_display ?? app(SettingsService::class)->fundingDisplay();
    }

    /**
     * Canonical founders list `[{name, role, linkedin_url, is_full_time}]` (trimmed, empty names dropped, max 6) —
     * used when saving and when comparing a re-sent list for the ENT-05 "unchanged" check.
     *
     * @return array<int, array{name: string, role: ?string, linkedin_url: ?string, is_full_time: bool}>
     */
    public static function normalizeFounders(mixed $founders): array
    {
        $clean = static fn (mixed $v): ?string => is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;

        return collect(is_array($founders) ? $founders : [])
            ->filter(fn ($f): bool => is_array($f) && $clean($f['name'] ?? null) !== null)
            ->map(fn (array $f): array => [
                'name' => (string) $clean($f['name']),
                'role' => $clean($f['role'] ?? null),
                'linkedin_url' => $clean($f['linkedin_url'] ?? null),
                'is_full_time' => filter_var($f['is_full_time'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ])
            ->take(self::MAX_FOUNDERS)
            ->values()
            ->all();
    }

    /**
     * Support-needed keys, de-duplicated, restricted to SUPPORT_NEEDS and kept in the canonical list order.
     *
     * @return array<int, string>
     */
    public static function normalizeSupportNeeded(mixed $keys): array
    {
        $keys = array_map('strval', is_array($keys) ? $keys : []);

        return array_values(array_filter(self::SUPPORT_NEEDS, fn (string $k): bool => in_array($k, $keys, true)));
    }

    // ---------------------------------------------------------------- ENT-05 field-level lock

    /**
     * Items of the open completion requests: `[{field?, document_kind?, note?}]` (ADM-04).
     *
     * @return array<int, array<string, mixed>>
     */
    public function openCompletionItems(): array
    {
        $requests = $this->relationLoaded('completionRequests')
            ? $this->completionRequests->whereNull('resolved_at')
            : $this->completionRequests()->open()->get();

        return $requests->flatMap(fn (CompletionRequest $c): array => array_values(array_filter((array) $c->items, 'is_array')))->values()->all();
    }

    /**
     * Field keys the owner may edit while `needs_completion` (expanded with aliases; a section name opens the
     * whole section). Document kinds are returned as `document:<kind>`. Empty when nothing is reopened.
     *
     * @return array<int, string>
     */
    public function reopenedFields(): array
    {
        $keys = [];
        foreach ($this->openCompletionItems() as $item) {
            $field = isset($item['field']) ? trim((string) $item['field']) : '';
            if ($field !== '') {
                $sectionFields = array_merge(self::WEB_SECTIONS[$field] ?? [], self::SECTIONS[$field] ?? []);
                if ($sectionFields !== []) {
                    foreach ($sectionFields as $f) {
                        array_push($keys, ...(self::FIELD_ALIASES[$f] ?? [$f]));
                    }
                } else {
                    array_push($keys, ...(self::FIELD_ALIASES[$field] ?? [$field]));
                }
            }
            if (! empty($item['document_kind'])) {
                $keys[] = 'document:'.$item['document_kind'];
            }
        }

        return array_values(array_unique($keys));
    }

    /** True when the owner may edit the given request key in the current status (ENT-05). */
    public function isFieldEditable(string $field): bool
    {
        return match ($this->status) {
            OpportunityStatus::Draft => true,
            OpportunityStatus::NeedsCompletion => in_array($field, $this->reopenedFields(), true),
            default => false,
        };
    }

    /** True when the owner may upload the given document kind in the current status (ENT-05). */
    public function isDocumentKindEditable(string $kind): bool
    {
        return match ($this->status) {
            OpportunityStatus::NeedsCompletion => in_array('document:'.$kind, $this->reopenedFields(), true) || in_array('documents', $this->reopenedFields(), true),
            default => ! $this->status->isTerminal(),
        };
    }

    /**
     * Web form steps that are fully locked for the owner right now: none in draft, those without a reopened
     * field in needs_completion, all of them otherwise.
     *
     * @return array<int, string>
     */
    public function lockedWebSections(): array
    {
        if ($this->status === OpportunityStatus::Draft) {
            return [];
        }
        $sections = array_keys(self::WEB_SECTIONS);
        if ($this->status !== OpportunityStatus::NeedsCompletion) {
            return $sections;
        }
        $reopened = $this->reopenedFields();
        $hasDocs = (bool) array_filter($reopened, fn (string $k): bool => str_starts_with($k, 'document:'));

        return array_values(array_filter($sections, function (string $section) use ($reopened, $hasDocs): bool {
            if ($section === 'documents' && $hasDocs) {
                return false;
            }

            return array_intersect(self::WEB_SECTIONS[$section], $reopened) === [];
        }));
    }

    // ---------------------------------------------------------------- SLA

    /** The SLA deadline that applies to the current status (ADM-02 / ENT-04). */
    public function slaDueAt(): ?Carbon
    {
        return match ($this->status) {
            OpportunityStatus::New => $this->review_due_at,
            OpportunityStatus::NeedsCompletion => $this->completion_due_at,
            OpportunityStatus::UnderReview => $this->evaluation_due_at,
            OpportunityStatus::Eligible, OpportunityStatus::Connected, OpportunityStatus::Negotiating => $this->stage_due_at,
            default => null,
        };
    }

    /** Whole days until the SLA deadline (negative when late); null when no SLA applies. */
    public function slaDaysLeft(): ?int
    {
        $due = $this->slaDueAt();
        if ($due === null) {
            return null;
        }
        $diff = now()->startOfDay()->diffInDays($due->copy()->startOfDay(), false);

        return (int) ($diff < 0 ? ceil($diff) : floor($diff));
    }
}

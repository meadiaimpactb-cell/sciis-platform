<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

use App\Enums\FundingDisplay;
use App\Models\ImpactIndicator;
use App\Models\Opportunity;
use App\Models\TaxonomyTerm;

/** Shared formatting for the three opportunity resources (SPEC §5 sensitivity matrix). */
trait FormatsOpportunity
{
    /**
     * Public-sensitivity identity block: name, organization name, sector, stage, regions,
     * investment types, founded year — allowed on card, detail and full views.
     *
     * @return array<string, mixed>
     */
    protected function publicIdentity(Opportunity $o): array
    {
        return [
            'uuid' => $o->uuid,
            'reference_no' => $o->reference_no,
            'name' => $o->name,
            'organization_name' => $o->organization?->name,
            'founded_year' => $o->organization?->foundedYear(),
            'sector' => $this->term($o->sector),
            'stage' => $o->stage?->value,
            'stage_label' => $o->stage?->label(),
            'regions' => $o->regions->map(fn (TaxonomyTerm $t): array => $this->term($t))->values()->all(),
            'investment_types' => $o->investmentTypes->map(fn (TaxonomyTerm $t): array => $this->term($t))->values()->all(),
            // Enum-like keys (what the cards render through i18n `enums.investmentType.*`).
            'investment_type_keys' => $o->investmentTypes->map(fn (TaxonomyTerm $t): string => $t->key)->values()->all(),
            'status' => $o->status->value,
            'status_label' => $o->status->label(),
            'status_color' => $o->status->color(),
            'website' => $o->website,
        ];
    }

    /**
     * Taxonomy term: `{id, key, group, label, label_ar, label_en}` — `label` is localized to the request locale,
     * `label_ar`/`label_en` let the clients switch language without a round-trip (mobile parity).
     *
     * @return array<string, mixed>|null
     */
    protected function term(?TaxonomyTerm $term): ?array
    {
        if ($term === null) {
            return null;
        }

        return [
            'id' => $term->id,
            'key' => $term->key,
            'group' => $term->group,
            'label' => $term->label(),
            'label_ar' => $term->label_ar,
            'label_en' => $term->label_en ?: $term->label_ar,
            'is_active' => (bool) $term->is_active,
        ];
    }

    /**
     * Funding amount per `settings.funding_display` (SPEC §5): band | exact | hidden.
     * The exact value is confidential → only when display=exact, or when the viewer is granted/owner/staff.
     *
     * Emits `funding_display` as an object `{mode, band, amount, currency}` (what the clients render) and keeps the
     * plain mode string under `funding_display_mode` plus `funding_band` (term) / `funding_amount` for compatibility.
     *
     * @return array<string, mixed>
     */
    protected function fundingBlock(Opportunity $o, bool $exactAllowed): array
    {
        $mode = $o->fundingDisplay();
        $band = $this->term($o->fundingRange);

        if ($mode === FundingDisplay::Hidden && ! $exactAllowed) {
            return [
                'funding_display' => ['mode' => 'hidden', 'band' => null, 'amount' => null, 'currency' => 'SAR'],
                'funding_display_mode' => 'hidden',
                'funding_band' => null,
                'funding_amount' => null,
            ];
        }

        if ($mode === FundingDisplay::Exact || $exactAllowed) {
            $amount = $o->funding_amount !== null ? (float) $o->funding_amount : null;

            return [
                'funding_display' => ['mode' => 'exact', 'band' => $band['label'] ?? null, 'amount' => $amount, 'currency' => 'SAR'],
                'funding_display_mode' => 'exact',
                'funding_band' => $band,
                'funding_amount' => $amount,
                'currency' => 'SAR',
            ];
        }

        return [
            'funding_display' => ['mode' => 'band', 'band' => $band['label'] ?? null, 'amount' => null, 'currency' => 'SAR'],
            'funding_display_mode' => 'band',
            'funding_band' => $band,
            'funding_amount' => null,
        ];
    }

    /** Teaser block (public): localized summary + highlights, flattened for the cards. @return array<string, mixed> */
    protected function teaserBlock(Opportunity $o): array
    {
        $teaser = $o->teaser;

        return [
            'teaser' => $teaser ? [
                'summary' => $teaser->summary(),
                'highlights' => $teaser->highlights ?? [],
                'version' => $teaser->version,
            ] : null,
            // Flat aliases: fall back to the short description while no teaser exists (owner preview / drafts).
            'teaser_summary' => $teaser ? $teaser->summary() : (string) ($o->short_description ?? ''),
            'teaser_highlights' => $teaser?->highlights ?? [],
        ];
    }

    /** @return array<string, mixed>|null */
    protected function indicator(?ImpactIndicator $i): ?array
    {
        if ($i === null) {
            return null;
        }

        return [
            'id' => $i->id,
            'name' => $i->name,
            'definition' => $i->definition,
            'unit' => $i->unit,
            'baseline' => $i->baseline !== null ? (float) $i->baseline : null,
            'target' => $i->target !== null ? (float) $i->target : null,
            'data_source' => $i->data_source,
            'frequency' => $i->frequency?->value,
            'frequency_label' => $i->frequency?->label(),
            'verification_method' => $i->verification_method,
            'iris_code' => $i->iris_code, // IMP-03 — IRIS+ catalogue code
            'is_primary' => (bool) $i->is_primary,
            'is_disclosed' => (bool) $i->is_disclosed,
            'sdg_id' => $i->sdg_id,
            'sdg' => $this->term($i->sdg),
        ];
    }

    /** Summary % only (confidential → "summary %" before grant). @return array<int, array{item: string, percent: float}> */
    protected function useOfFundsSummary(Opportunity $o): array
    {
        return collect((array) $o->use_of_funds)
            ->map(fn (array $row): array => ['item' => (string) ($row['item'] ?? ''), 'percent' => (float) ($row['percent'] ?? 0)])
            ->values()
            ->all();
    }

    /** Risk & disclosure summary (yes/no flags only, no descriptions) — before grant. @return array<string, mixed> */
    protected function risksSummary(Opportunity $o): array
    {
        return [
            'prior_funding' => $o->prior_funding,
            'legal_disputes' => $o->legal_disputes,
            'license_required' => $o->license_required,
            'license_status' => $o->license_status,
            'has_operational_risks' => $o->operational_risks !== null && $o->operational_risks !== '',
        ];
    }

    /** One-line, non-confidential sentence built from the yes/no flags (shown before grant). */
    protected function risksSummaryText(Opportunity $o): ?string
    {
        $parts = [];
        $parts[] = __('messages.risks.prior_funding').': '.($o->prior_funding ? __('messages.yes') : __('messages.no'));
        $parts[] = __('messages.risks.legal_disputes').': '.($o->legal_disputes ? __('messages.yes') : __('messages.no'));
        $parts[] = __('messages.risks.license_required').': '.($o->license_required ? __('messages.yes') : __('messages.no'));
        if ($o->operational_risks !== null && $o->operational_risks !== '') {
            $parts[] = __('messages.risks.operational_declared');
        }

        return implode(' · ', $parts);
    }

    /** Full risk & disclosure text — after grant (confidential). @return array<string, mixed> */
    protected function risksFull(Opportunity $o): array
    {
        return $this->risksSummary($o) + [
            'prior_funding_desc' => $o->prior_funding_desc,
            'legal_disputes_desc' => $o->legal_disputes_desc,
            'operational_risks' => $o->operational_risks,
        ];
    }

    /** Nested after-grant shape the detail page renders. @return array<string, mixed> */
    protected function risksFullNested(Opportunity $o): array
    {
        return [
            'prior_funding' => ['has' => (bool) $o->prior_funding, 'desc' => $o->prior_funding_desc],
            'legal_disputes' => ['has' => (bool) $o->legal_disputes, 'desc' => $o->legal_disputes_desc],
            'license' => ['required' => (bool) $o->license_required, 'status' => $o->license_status],
            'operational_risks' => $o->operational_risks,
        ];
    }

    /** Localized label of a plain string enum stored on the opportunity (`lang/*\/enums.php`). */
    protected function enumLabel(string $group, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $key = "enums.{$group}.{$value}";

        return __($key) !== $key ? __($key) : $value;
    }

    /**
     * Team & founders — CONFIDENTIAL ("س"): owner, staff/evaluators and (detail resource) granted investors only.
     * Booleans stay `null` while unanswered so evaluators can tell "no" from "not filled".
     *
     * @return array<string, mixed>
     */
    protected function teamBlock(Opportunity $o): array
    {
        return [
            'founders_count' => $o->founders_count,
            'team_size' => $o->team_size,
            'founders_commitment' => $o->founders_commitment,
            'founders_commitment_label' => $this->enumLabel('founders_commitment', $o->founders_commitment),
            'founders_commitment_note' => $o->founders_commitment_note,
            'prior_startup_experience' => $o->prior_startup_experience,
            'prior_startup_experience_desc' => $o->prior_startup_experience_desc,
            'industry_experience' => $o->industry_experience,
            'industry_experience_desc' => $o->industry_experience_desc,
            'founders_known_years' => $o->founders_known_years,
            'founders_known_years_label' => $this->enumLabel('founders_known_years', $o->founders_known_years),
            'has_advisory_board' => $o->has_advisory_board,
            'advisory_board_desc' => $o->advisory_board_desc,
            'dev_team_structure' => $o->dev_team_structure,
            'dev_team_structure_label' => $this->enumLabel('dev_team_structure', $o->dev_team_structure),
            'founders_agreement_signed' => $o->founders_agreement_signed,
            'founders' => Opportunity::normalizeFounders($o->founders),
        ];
    }

    /**
     * Market & product. `$publicOnly` (investor detail) keeps ONLY product_stage, target_customers,
     * competitive_advantage and traction_desc (Opportunity::MARKET_PUBLIC_FIELDS).
     *
     * @return array<string, mixed>
     */
    protected function marketBlock(Opportunity $o, bool $publicOnly = false): array
    {
        $public = [
            'product_stage' => $o->product_stage,
            'product_stage_label' => $this->enumLabel('product_stage', $o->product_stage),
            'target_customers' => $o->target_customers,
            'competitive_advantage' => $o->competitive_advantage,
            'traction_desc' => $o->traction_desc,
        ];
        if ($publicOnly) {
            return $public;
        }

        return $public + [
            'market_size_desc' => $o->market_size_desc,
            'competitors' => $o->competitors,
            'go_to_market' => $o->go_to_market,
            'technology_desc' => $o->technology_desc,
            'scalability_desc' => $o->scalability_desc,
        ];
    }

    /** Motivation & commitment — INTERNAL ("د"): owner + staff only, never in the investor resources. @return array<string, mixed> */
    protected function motivationBlock(Opportunity $o): array
    {
        $support = Opportunity::normalizeSupportNeeded($o->support_needed);

        return [
            'why_now' => $o->why_now,
            'support_needed' => $support,
            'support_needed_labels' => array_map(fn (string $k): ?string => $this->enumLabel('support_need', $k), $support),
            'program_expectations' => $o->program_expectations,
            'how_heard_id' => $o->how_heard_id,
            'how_heard' => $this->term($o->howHeard),
        ];
    }
}

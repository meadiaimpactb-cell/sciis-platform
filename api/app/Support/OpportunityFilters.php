<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Opportunity;
use App\Models\TaxonomyTerm;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * OPP-02 / OPP-08 / OPP-10 — the investor catalogue filters, in one place for the browse API, saved filters and
 * the new-opportunity alerts (OPP-07).
 *
 * Facets: `sector`, `region`, `type` (investment type), `beneficiary` (beneficiary group), `sdg` — taxonomy key or
 * id —, `stage` (enum value) and `range` (funding-range key/id). A value may be a comma list (= any of). Plus
 * `search` (name / English name / description / reference / teaser) and `nominated` (only the caller's nominations).
 */
final class OpportunityFilters
{
    public const FACETS = ['sector', 'region', 'stage', 'type', 'range', 'beneficiary', 'sdg'];

    /** Taxonomy facets: facet → [relation, pivot table, pivot column] (sector is a column on the opportunity). */
    private const PIVOTS = [
        'region' => ['regions', 'opportunity_regions', 'region_id'],
        'type' => ['investmentTypes', 'opportunity_investment_types', 'investment_type_id'],
        'beneficiary' => ['beneficiaryGroups', 'opportunity_beneficiary_groups', 'beneficiary_group_id'],
        'sdg' => ['sdgs', 'opportunity_sdgs', 'sdg_id'],
    ];

    /**
     * Normalized filters: only known keys, trimmed strings (≤ 120 chars), empty values dropped.
     *
     * @param  array<string, mixed>  $input  `filter[...]` values, optionally with `search` / `nominated` / `sort`
     * @return array<string, string|bool>
     */
    public static function normalize(array $input): array
    {
        $out = [];
        foreach (self::FACETS as $facet) {
            $value = $input[$facet] ?? null;
            if (is_array($value)) {
                $value = implode(',', array_filter(array_map(fn ($v): string => is_scalar($v) ? trim((string) $v) : '', $value)));
            }
            $value = is_scalar($value) ? mb_substr(trim((string) $value), 0, 120) : '';
            if ($value !== '') {
                $out[$facet] = $value;
            }
        }

        $search = is_scalar($input['search'] ?? null) ? mb_substr(trim((string) $input['search']), 0, 120) : '';
        if ($search !== '') {
            $out['search'] = $search;
        }
        if (filter_var($input['nominated'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $out['nominated'] = true;
        }
        if (in_array($input['sort'] ?? null, ['newest', 'impact', 'need'], true)) {
            $out['sort'] = (string) $input['sort'];
        }

        return $out;
    }

    /** True when at least one narrowing criterion (a facet or a search) is set. */
    public static function hasCriteria(array $filters): bool
    {
        return array_intersect_key($filters, array_flip([...self::FACETS, 'search'])) !== [];
    }

    /**
     * Applies the filters to an opportunity query; `$except` skips one facet (facet counts, OPP-10).
     *
     * @param  array<string, mixed>  $filters  normalized
     */
    public static function apply(Builder $query, array $filters, User $user, ?string $except = null): Builder
    {
        foreach (self::FACETS as $facet) {
            if ($facet === $except || ! isset($filters[$facet]) || $filters[$facet] === '') {
                continue;
            }
            $values = self::values($filters[$facet]);
            match ($facet) {
                'sector' => $query->whereHas('sector', fn (Builder $s) => self::whereTerm($s, $values)),
                'stage' => $query->whereIn('stage', $values),
                'range' => $query->where(function (Builder $any) use ($values): void {
                    foreach ($values as $value) {
                        $any->orWhere(fn (Builder $one) => self::applyRange($one, $value));
                    }
                }),
                default => $query->whereHas(self::PIVOTS[$facet][0], fn (Builder $t) => self::whereTerm($t, $values)),
            };
        }

        if (! empty($filters['nominated'])) {
            $query->whereHas('nominations', fn (Builder $n) => $n->where('investor_user_id', $user->id));
        }

        if (! empty($filters['search'])) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $filters['search']).'%';
            $query->where(fn (Builder $w) => $w->where('name', 'like', $term)->orWhere('name_en', 'like', $term)
                ->orWhere('short_description', 'like', $term)->orWhere('short_description_en', 'like', $term)
                ->orWhere('reference_no', 'like', $term)
                ->orWhereHas('teaser', fn (Builder $t) => $t->where('summary_ar', 'like', $term)->orWhere('summary_en', 'like', $term)));
        }

        return $query;
    }

    /**
     * OPP-10 — facet counts that reflect the applied filters: each facet is counted over the opportunities matching
     * every OTHER filter (its own selection excluded, so the alternatives within a facet stay visible).
     *
     * @param  array<string, mixed>  $filters  normalized
     * @return array<string, array<string, int>>
     */
    public static function facetCounts(Builder $visible, array $filters, User $user): array
    {
        $memo = [];
        $counts = [];
        foreach (self::FACETS as $facet) {
            $signature = json_encode(array_diff_key($filters, [$facet => true, 'sort' => true]));
            $memo[$signature] ??= self::apply(clone $visible, $filters, $user, $facet)->pluck('opportunities.id')->map(fn ($id): int => (int) $id)->all();
            $counts[$facet] = self::countFacet($facet, $memo[$signature]);
        }

        return $counts;
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<string, int> taxonomy key (stage: enum value) → number of opportunities
     */
    private static function countFacet(string $facet, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $byKey = fn ($rows): array => $rows->mapWithKeys(fn ($r): array => [(string) $r->k => (int) $r->c])->all();

        return match ($facet) {
            'sector' => $byKey(DB::table('opportunities')->join('taxonomy_terms', 'taxonomy_terms.id', '=', 'opportunities.sector_id')
                ->whereIn('opportunities.id', $ids)->groupBy('taxonomy_terms.key')->select('taxonomy_terms.key as k', DB::raw('count(*) as c'))->get()),
            'stage' => $byKey(DB::table('opportunities')->whereIn('id', $ids)->whereNotNull('stage')->groupBy('stage')->select('stage as k', DB::raw('count(*) as c'))->get()),
            'range' => self::countRanges($ids),
            default => (function () use ($facet, $ids, $byKey): array {
                [, $pivot, $column] = self::PIVOTS[$facet];

                return $byKey(DB::table($pivot)->join('taxonomy_terms', 'taxonomy_terms.id', '=', "{$pivot}.{$column}")
                    ->whereIn("{$pivot}.opportunity_id", $ids)->groupBy('taxonomy_terms.key')
                    ->select('taxonomy_terms.key as k', DB::raw("count(distinct {$pivot}.opportunity_id) as c"))->get());
            })(),
        };
    }

    /** Range: explicit funding_range_id, else the amount bucketed by the terms' meta.min/max. @param array<int, int> $ids @return array<string, int> */
    private static function countRanges(array $ids): array
    {
        $ranges = TaxonomyTerm::query()->where('group', 'investment_ranges')->active()->ordered()->get();
        $counts = [];
        foreach (DB::table('opportunities')->whereIn('id', $ids)->select('funding_range_id', 'funding_amount')->get() as $row) {
            $term = $row->funding_range_id !== null
                ? $ranges->firstWhere('id', (int) $row->funding_range_id)
                : ($row->funding_amount !== null ? $ranges->first(function (TaxonomyTerm $t) use ($row): bool {
                    $min = $t->meta['min'] ?? null;
                    $max = $t->meta['max'] ?? null;

                    return ($min === null || (float) $row->funding_amount >= (float) $min) && ($max === null || (float) $row->funding_amount < (float) $max);
                }) : null);
            if ($term !== null) {
                $counts[$term->key] = ($counts[$term->key] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /** `range`: funding-range term key/id, falling back to the term's `meta.min/max` against `funding_amount`. */
    private static function applyRange(Builder $q, string $value): Builder
    {
        $term = TaxonomyTerm::query()->where('group', 'investment_ranges')->where(fn ($w) => $w->where('key', $value)->when(ctype_digit($value), fn ($x) => $x->orWhere('id', (int) $value)))->first();
        if ($term === null) {
            return $q->whereRaw('1 = 0');
        }
        $min = $term->meta['min'] ?? null;
        $max = $term->meta['max'] ?? null;

        return $q->where(function (Builder $w) use ($term, $min, $max): void {
            $w->where('funding_range_id', $term->id);
            if ($min !== null || $max !== null) {
                $w->orWhere(function (Builder $amt) use ($min, $max): void {
                    $amt->whereNull('funding_range_id')->whereNotNull('funding_amount');
                    if ($min !== null) {
                        $amt->where('funding_amount', '>=', (float) $min);
                    }
                    if ($max !== null) {
                        $amt->where('funding_amount', '<', (float) $max);
                    }
                });
            }
        });
    }

    /** @param array<int, string> $values taxonomy keys or numeric ids */
    private static function whereTerm(Builder $q, array $values): Builder
    {
        $ids = array_map('intval', array_filter($values, 'ctype_digit'));

        return $q->where(fn (Builder $w) => $w->whereIn('taxonomy_terms.key', $values)->when($ids !== [], fn (Builder $x) => $x->orWhereIn('taxonomy_terms.id', $ids)));
    }

    /** @return array<int, string> */
    private static function values(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn (string $v): bool => $v !== ''));
    }

    /** Opportunity ids (among `$candidateIds`) that the filters match for this investor — saved-filter alerts. @param array<int, int> $candidateIds @return array<int, int> */
    public static function matchingIds(array $filters, User $user, array $candidateIds): array
    {
        if ($candidateIds === []) {
            return [];
        }

        return self::apply(Opportunity::query()->visibleTo($user)->whereIn('opportunities.id', $candidateIds), $filters, $user)
            ->pluck('opportunities.id')->map(fn ($id): int => (int) $id)->all();
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Entrepreneur\Concerns;

use App\Enums\DocumentKind;
use App\Models\CompletionRequest;
use App\Models\Opportunity;
use App\Services\FormSchemaService;

/**
 * ENT-02 — submission checklist shared by the opportunity + dashboard controllers.
 *
 * Shape: `{complete, percent, missing[], required_now[]}` (original keys) plus the client aliases
 * `ready`, `completion_percent` and the full `items[{key, label, section, done, kind}]` list where `section`
 * is the web/mobile form step (basics | organization | team | project | impact | market | financials | documents |
 * motivation | disclosures) so the "missing items" list can jump to the right step.
 * Readiness axis (doc §12.5): founders_count, team_size, founders_commitment, product_stage, target_customers,
 * competitive_advantage and why_now are required (see Opportunity::REQUIRED_FOR_SUBMIT).
 */
trait BuildsChecklist
{
    /** @return array<string, mixed> */
    protected function buildChecklist(Opportunity $opportunity): array
    {
        $items = [];
        $missing = [];

        foreach (Opportunity::REQUIRED_FOR_SUBMIT as $field) {
            $value = $opportunity->getAttribute($field);
            $done = ! ($value === null || $value === '' || $value === []);
            if ($field === 'use_of_funds' && $done) {
                $sum = array_sum(array_map(fn ($r): float => (float) (is_array($r) ? ($r['percent'] ?? 0) : 0), (array) $value));
                $done = abs($sum - 100) <= 0.5;
            }
            $items[] = ['key' => $field, 'label' => __("validation.attributes.{$field}"), 'section' => $this->webSectionOf($field), 'done' => $done, 'kind' => 'field'];
            if (! $done) {
                $missing[] = ['key' => $field, 'section' => $this->sectionOf($field), 'label' => __("validation.attributes.{$field}")];
            }
        }

        foreach (Opportunity::REQUIRED_RELATIONS_FOR_SUBMIT as $relation) {
            $done = $opportunity->{$relation}()->exists();
            $items[] = ['key' => $relation, 'label' => __("validation.attributes.{$relation}"), 'section' => 'basics', 'done' => $done, 'kind' => 'field'];
            if (! $done) {
                $missing[] = ['key' => $relation, 'section' => 'basics', 'label' => __("validation.attributes.{$relation}")];
            }
        }

        // FRM-01 — required additional questions of the project form (one item, only when the schema has any).
        $forms = app(FormSchemaService::class);
        if (array_filter($forms->questions('opportunity'), fn (array $q): bool => (bool) ($q['required'] ?? false)) !== []) {
            $extraDone = $forms->missingRequired('opportunity', $opportunity->extra_answers) === [];
            $items[] = ['key' => 'extra_answers', 'label' => __('messages.forms.extra_answers'), 'section' => 'motivation', 'done' => $extraDone, 'kind' => 'field'];
            if (! $extraDone) {
                $missing[] = ['key' => 'extra_answers', 'section' => 'motivation', 'label' => __('messages.forms.extra_answers')];
            }
        }

        $primaryDone = $opportunity->indicators()->where('is_primary', true)->exists();
        $items[] = ['key' => 'primary_indicator', 'label' => __('validation.attributes.primary_indicator'), 'section' => 'impact', 'done' => $primaryDone, 'kind' => 'field'];
        if (! $primaryDone) {
            $missing[] = ['key' => 'primary_indicator', 'section' => 'impact', 'label' => __('validation.attributes.primary_indicator')];
        }

        foreach (Opportunity::REQUIRED_DOCUMENT_KINDS as $kind) {
            $done = $opportunity->documents()->where('kind', $kind)->where('status', '!=', 'rejected')->exists();
            $items[] = ['key' => 'document:'.$kind, 'label' => DocumentKind::from($kind)->label(), 'section' => 'documents', 'done' => $done, 'kind' => 'document'];
            if (! $done) {
                $missing[] = ['key' => 'document:'.$kind, 'section' => 'documents', 'label' => DocumentKind::from($kind)->label()];
            }
        }

        $total = count($items);
        $percent = $total === 0 ? 100 : (int) round(($total - count($missing)) / $total * 100);

        return [
            'complete' => $missing === [],
            'ready' => $missing === [],
            'percent' => $percent,
            'completion_percent' => $percent,
            'missing' => $missing,
            'items' => $items,
            'required_now' => $opportunity->completionRequests()->open()->get()->map(fn (CompletionRequest $c): array => [
                'id' => $c->id, 'items' => $c->items, 'due_at' => $c->due_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** API section (basics | organization | team | impact | market | financials | motivation | disclosures). */
    protected function sectionOf(string $field): string
    {
        foreach (Opportunity::SECTIONS as $section => $fields) {
            if (in_array($field, $fields, true)) {
                return $section;
            }
        }

        return 'basics';
    }

    /** Web/mobile form step (basics | organization | team | project | impact | market | financials | documents | motivation | disclosures). */
    protected function webSectionOf(string $field): string
    {
        foreach (Opportunity::WEB_SECTIONS as $section => $fields) {
            if (in_array($field, $fields, true)) {
                return $section;
            }
        }

        return 'basics';
    }
}

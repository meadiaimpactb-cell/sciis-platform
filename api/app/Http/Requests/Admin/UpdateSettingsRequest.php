<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\OpportunityStatus;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/** ADM-03 */
class UpdateSettingsRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array', 'min:1'],
            'settings.*' => ['nullable'],
            'settings.opportunity\.access_policy' => ['sometimes', 'string', 'in:nomination_only,browse_all,both'],
            'settings.opportunity\.funding_display' => ['sometimes', 'string', 'in:band,exact,hidden'],
            'settings.opportunity\.max_nominations_per_opportunity' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'settings.sla\.review_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'settings.sla\.completion_days' => ['sometimes', 'integer', 'min:1', 'max:180'],
            'settings.sla\.evaluation_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'settings.sla\.owner_decision_days' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'settings.sla\.matching_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'settings.sla\.connection_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'settings.sla\.negotiation_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'settings.sla\.completion_reminder_days' => ['sometimes', 'array', 'max:5'],
            'settings.sla\.completion_reminder_days.*' => ['integer', 'min:0', 'max:60'],
            'settings.sla\.completion_auto_close_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'settings.evaluation\.score_diff_threshold' => ['sometimes', 'integer', 'min:1', 'max:4'],
            // PRJ-02 / PRJ-03 / ENT-10 — follow-up & impact
            'settings.follow_up\.report_frequency' => ['sometimes', 'string', 'in:monthly,quarterly,semiannual,annual'],
            'settings.follow_up\.report_grace_days' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'settings.follow_up\.report_reminder_days' => ['sometimes', 'array', 'max:5'],
            'settings.follow_up\.report_reminder_days.*' => ['integer', 'min:0', 'max:60'],
            'settings.follow_up\.report_overdue_reminder_days' => ['sometimes', 'array', 'max:5'],
            'settings.follow_up\.report_overdue_reminder_days.*' => ['integer', 'min:1', 'max:180'],
            'settings.follow_up\.stalled_after_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'settings.follow_up\.updates_require_review' => ['sometimes', 'boolean'],
            'settings.documents\.max_size_mb' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'settings.documents\.allowed_mimes' => ['sometimes', 'array'],
            'settings.privacy\.retention_days\.rejected' => ['sometimes', 'integer', 'min:30'],
            'settings.privacy\.retention_days\.withdrawn' => ['sometimes', 'integer', 'min:30'],
            'settings.privacy\.retention_days\.inactive' => ['sometimes', 'integer', 'min:30'],
            'settings.membership\.fee_sar' => ['sometimes', 'numeric', 'min:0'],
            'settings.platform\.email_investors' => ['sometimes', 'nullable', 'email', 'max:190'],
            'settings.platform\.email_entrepreneurs' => ['sometimes', 'nullable', 'email', 'max:190'],
            'settings.platform\.social\.x' => ['sometimes', 'nullable', 'url:https', 'max:255'],
            'settings.platform\.social\.linkedin' => ['sometimes', 'nullable', 'url:https', 'max:255'],
            'settings.platform\.social\.instagram' => ['sometimes', 'nullable', 'url:https', 'max:255'],
            'settings.platform\.social\.youtube' => ['sometimes', 'nullable', 'url:https', 'max:255'],
            'settings.membership\.requires_prior_investments' => ['sometimes', 'integer', 'min:0'],
            'settings.security\.idle_timeout_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            // MAT-06 / OPP-07 / INV-07 / MSG-03
            'settings.matching\.weight_sector' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'settings.matching\.weight_stage' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'settings.matching\.weight_region' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'settings.matching\.weight_type' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'settings.matching\.weight_range' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'settings.matching\.weight_impact' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'settings.matching\.decline_penalty' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'settings.matching\.engagement_bonus' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'settings.matching\.alert_min_score' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'settings.investor\.profile_reminder_days' => ['sometimes', 'integer', 'min:0', 'max:730'],
            'settings.notifications\.weekly_digest' => ['sometimes', 'boolean'],
            'settings.analytics\.ga4_measurement_id' => ['sometimes', 'nullable', 'string', 'regex:/^G-[A-Z0-9]{4,16}$/'],
            // ADM-09 automatic reviewer assignment / RPT-04..06 export cap
            'settings.assignment\.auto_assign_reviewer' => ['sometimes', 'boolean'],
            'settings.assignment\.reviewer_strategy' => ['sometimes', 'string', 'in:least_workload,round_robin'],
            'settings.reports\.max_export_rows' => ['sometimes', 'integer', 'min:100', 'max:50000'],
            // ADM-01 — {target status: permission}; only real statuses and existing permissions.
            'settings.workflow\.transition_permissions' => ['sometimes', 'array', function (string $attribute, mixed $value, \Closure $fail): void {
                $unknown = array_diff(array_keys((array) $value), OpportunityStatus::values());
                if ($unknown !== []) {
                    $fail(__('validation.in', ['attribute' => implode(', ', $unknown)]));
                }
            }],
            'settings.workflow\.transition_permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ];
    }
}

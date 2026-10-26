<?php

declare(strict_types=1);

namespace App\Http\Requests\FollowUp;

use App\Enums\ExecutionStatus;
use App\Http\Requests\ApiRequest;

/**
 * PRJ-02 — unified progress report template: summary, achieved, delayed (and why), risks & challenges, support
 * needed, spend in the period, milestone updates and up to 5 evidence files. The period is one of the open
 * periods of the schedule (checked by the controller); a resubmission keeps its period.
 */
class ProgressReportRequest extends ApiRequest
{
    public const MAX_FILES = 5;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxKb = (int) setting('documents.max_size_mb', 25) * 1024;
        $isCreate = $this->route('report') === null;

        return [
            'period_start' => [$isCreate ? 'required' : 'prohibited', 'date_format:Y-m-d'],
            'narrative' => ['required', 'string', 'min:10', 'max:5000'],
            'achieved' => ['required', 'string', 'min:5', 'max:5000'],
            'delayed' => ['nullable', 'string', 'max:5000'],
            'risks' => ['nullable', 'string', 'max:5000'],
            'support_needed' => ['nullable', 'string', 'max:5000'],
            'spend_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'execution_status' => ['nullable', 'string', $this->enumRule(ExecutionStatus::class)],
            'milestone_updates' => ['nullable', 'array', 'max:50'],
            'milestone_updates.*.milestone_id' => ['required', 'integer'],
            'milestone_updates.*.status' => ['required', 'string', 'in:planned,in_progress'],
            'milestone_updates.*.note' => ['nullable', 'string', 'max:1000'],
            'files' => ['nullable', 'array', 'max:'.self::MAX_FILES],
            'files.*' => ['file', 'max:'.$maxKb],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\FollowUp;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Staff decisions (impact.verify): the decisions allowed depend on the endpoint; a negative decision needs a note
 * the owner reads (PRJ-01 plan, PRJ-02 report, IMP-02 value, ENT-10 update review).
 */
class FollowUpReviewRequest extends ApiRequest
{
    /** route name suffix => [positive, negative] */
    public const DECISIONS = [
        'plan.review' => ['approve', 'changes_requested'],
        'reports.review' => ['accept', 'changes_requested'],
        'indicator-values.review' => ['verify', 'needs_clarification'],
        'updates.review' => ['publish', 'reject'],
    ];

    /** @return array{0: string, 1: string} */
    public function decisions(): array
    {
        $name = (string) $this->route()?->getName();
        foreach (self::DECISIONS as $suffix => $pair) {
            if (str_ends_with($name, $suffix)) {
                return $pair;
            }
        }

        return ['approve', 'reject'];
    }

    public function isPositive(): bool
    {
        return $this->validated('decision') === $this->decisions()[0];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        [$positive, $negative] = $this->decisions();

        return [
            'decision' => ['required', 'string', Rule::in([$positive, $negative])],
            'note' => [Rule::requiredIf(fn (): bool => $this->input('decision') === $negative), 'nullable', 'string', 'min:5', 'max:2000'],
        ];
    }
}

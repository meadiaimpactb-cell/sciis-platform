<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\FormSchemaService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * FRM-01 — validates an `extra_answers` map against the active schema of a form key. Each failing question is
 * reported under `{attribute}.{question_id}` (e.g. `extra_answers.q_budget`, `profile.extra_answers.q_budget`).
 * `$enforceRequired = false` for drafts: types are checked, required questions are enforced at submission instead.
 */
class FormAnswers implements ValidationRule
{
    public function __construct(private readonly string $key, private readonly bool $enforceRequired = true) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }
        if (! is_array($value)) {
            $fail(__('validation.array', ['attribute' => __('messages.forms.extra_answers')]));

            return;
        }

        foreach (app(FormSchemaService::class)->errors($this->key, $value, $this->enforceRequired) as $id => $message) {
            $fail("{$attribute}.{$id}", $message);
        }
    }
}

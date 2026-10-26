<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FormSchema;
use App\Models\User;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * FRM-01 — "additional questions" builder (no code). Staff with `settings.manage` publish versioned question lists
 * for three forms; each publish creates a new `form_schemas` row (key, version, schema = {questions: [...]}) and
 * deactivates the previous one. Answers are validated here against the active version and stored on the record as
 * `{key, version, answers, answered_at}` so they can always be labelled with the version they were given against.
 *
 * Question: {id, type, label_ar, label_en, help_ar, help_en, required, active, options[{value, label_ar, label_en}],
 * show_when: {question, equals} | null}. `show_when` may only point to an earlier question ("show when X equals Y";
 * a multiple-choice X matches when Y is among the picks, a yes/no X uses "yes" / "no").
 */
#[Scoped]
class FormSchemaService
{
    /** form key → record it is stored on */
    public const KEYS = ['investor_profile', 'opportunity', 'membership_review'];

    /** Readable without a staff session (registration wizard, profile, project form). */
    public const PUBLIC_KEYS = ['investor_profile', 'opportunity'];

    public const TYPES = ['short_text', 'long_text', 'number', 'single_choice', 'multiple_choice', 'yes_no', 'date'];

    public const CHOICE_TYPES = ['single_choice', 'multiple_choice'];

    public const MAX_QUESTIONS = 40;

    public const MAX_OPTIONS = 30;

    public const SHORT_TEXT_MAX = 255;

    public const LONG_TEXT_MAX = 3000;

    public const NUMBER_MAX = 999999999999;

    /** @var array<string, FormSchema|null> */
    private array $active = [];

    /** @var array<string, FormSchema|null> */
    private array $versions = [];

    public function __construct(private readonly AuditLogger $audit) {}

    public static function isKey(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    public function active(string $key): ?FormSchema
    {
        if (! array_key_exists($key, $this->active)) {
            $this->active[$key] = FormSchema::query()->where('key', $key)->where('is_active', true)->orderByDesc('version')->first();
        }

        return $this->active[$key];
    }

    public function version(string $key, int $version): ?FormSchema
    {
        $memo = $key.'@'.$version;
        if (! array_key_exists($memo, $this->versions)) {
            $this->versions[$memo] = FormSchema::query()->where('key', $key)->where('version', $version)->first();
        }

        return $this->versions[$memo];
    }

    /**
     * Every question of a schema (inactive ones included — the builder edits them, old answers are labelled by them).
     *
     * @return list<array<string, mixed>>
     */
    public static function allQuestions(?FormSchema $schema): array
    {
        return array_values(array_filter((array) ($schema?->schema['questions'] ?? []), 'is_array'));
    }

    /**
     * Active questions of the published version — what the forms render and what answers are validated against.
     *
     * @return list<array<string, mixed>>
     */
    public function questions(string $key): array
    {
        return array_values(array_filter(self::allQuestions($this->active($key)), fn (array $q): bool => (bool) ($q['active'] ?? true)));
    }

    /**
     * Public shape of the active version: `{key, version, questions}` (version 0 = nothing published yet).
     *
     * @return array<string, mixed>
     */
    public function publicSchema(string $key): array
    {
        return ['key' => $key, 'version' => (int) ($this->active($key)?->version ?? 0), 'questions' => $this->questions($key)];
    }

    // ------------------------------------------------------------------ builder

    /**
     * Publish a new version of `$key` (validated, normalised, previous version deactivated, audited).
     *
     * @param  array<int, mixed>  $questions
     */
    public function publish(string $key, array $questions, User $actor): FormSchema
    {
        $normalised = $this->validateDefinition($questions);

        return DB::transaction(function () use ($key, $normalised, $actor): FormSchema {
            $previous = FormSchema::query()->where('key', $key)->lockForUpdate()->orderByDesc('version')->first();
            $next = (int) ($previous?->version ?? 0) + 1;

            FormSchema::query()->where('key', $key)->where('is_active', true)->update(['is_active' => false]);
            $schema = FormSchema::query()->forceCreate([
                'key' => $key,
                'version' => $next,
                'schema' => ['questions' => $normalised],
                'is_active' => true,
                'published_by' => $actor->id,
            ]);

            $this->audit->log('form_schema.published', $schema,
                $previous ? ['version' => $previous->version, 'questions' => count(self::allQuestions($previous))] : null,
                ['key' => $key, 'version' => $next, 'questions' => count($normalised), 'active' => count(array_filter($normalised, fn (array $q): bool => $q['active']))],
                $actor,
            );

            unset($this->active[$key]);

            return $schema;
        });
    }

    /**
     * Validate + normalise a question list from the builder. Errors are keyed `questions.{i}.{field}`.
     *
     * @param  array<int, mixed>  $questions
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function validateDefinition(array $questions): array
    {
        $validator = Validator::make(['questions' => $questions], [
            'questions' => ['present', 'array', 'max:'.self::MAX_QUESTIONS],
            'questions.*' => ['array'],
            'questions.*.id' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,39}$/', 'distinct'],
            'questions.*.type' => ['required', 'string', Rule::in(self::TYPES)],
            'questions.*.label_ar' => ['required', 'string', 'max:300'],
            'questions.*.label_en' => ['nullable', 'string', 'max:300'],
            'questions.*.help_ar' => ['nullable', 'string', 'max:500'],
            'questions.*.help_en' => ['nullable', 'string', 'max:500'],
            'questions.*.required' => ['sometimes', 'boolean'],
            'questions.*.active' => ['sometimes', 'boolean'],
            'questions.*.options' => ['nullable', 'array', 'max:'.self::MAX_OPTIONS],
            'questions.*.options.*.value' => ['required', 'string', 'regex:/^[a-z0-9_]{1,40}$/'],
            'questions.*.options.*.label_ar' => ['required', 'string', 'max:190'],
            'questions.*.options.*.label_en' => ['nullable', 'string', 'max:190'],
            'questions.*.show_when' => ['nullable', 'array'],
            'questions.*.show_when.question' => ['required_with:questions.*.show_when', 'string'],
            'questions.*.show_when.equals' => ['required_with:questions.*.show_when', 'string', 'max:190'],
        ], [], ['questions.*.label_ar' => __('messages.forms.label_ar'), 'questions.*.id' => __('messages.forms.question_id')]);

        $validator->after(function ($v) use ($questions): void {
            $seen = [];
            foreach ($questions as $i => $q) {
                if (! is_array($q)) {
                    continue;
                }
                $type = (string) ($q['type'] ?? '');
                $options = array_values(array_filter((array) ($q['options'] ?? []), 'is_array'));
                if (in_array($type, self::CHOICE_TYPES, true)) {
                    if (count($options) < 2) {
                        $v->errors()->add("questions.{$i}.options", __('messages.forms.options_required'));
                    }
                    $values = array_map(fn (array $o): string => (string) ($o['value'] ?? ''), $options);
                    if (count($values) !== count(array_unique($values))) {
                        $v->errors()->add("questions.{$i}.options", __('messages.forms.options_distinct'));
                    }
                }

                $cond = $q['show_when'] ?? null;
                if (is_array($cond) && isset($cond['question'])) {
                    $target = $seen[(string) $cond['question']] ?? null;
                    if ($target === null) {
                        // Only an earlier question can drive the display (no forward references, no cycles).
                        $v->errors()->add("questions.{$i}.show_when.question", __('messages.forms.condition_earlier'));
                    } elseif (! $this->conditionValueFits($target, (string) ($cond['equals'] ?? ''))) {
                        $v->errors()->add("questions.{$i}.show_when.equals", __('messages.forms.condition_value'));
                    }
                }

                if (isset($q['id']) && is_string($q['id'])) {
                    $seen[$q['id']] = $q;
                }
            }
        });

        $validator->validate();

        return array_values(array_map(function (array $q): array {
            $type = (string) $q['type'];
            $cond = is_array($q['show_when'] ?? null) && ($q['show_when']['question'] ?? '') !== ''
                ? ['question' => (string) $q['show_when']['question'], 'equals' => (string) $q['show_when']['equals']]
                : null;

            return [
                'id' => (string) $q['id'],
                'type' => $type,
                'label_ar' => trim((string) $q['label_ar']),
                'label_en' => self::blankToNull($q['label_en'] ?? null),
                'help_ar' => self::blankToNull($q['help_ar'] ?? null),
                'help_en' => self::blankToNull($q['help_en'] ?? null),
                'required' => (bool) ($q['required'] ?? false),
                'active' => (bool) ($q['active'] ?? true),
                'options' => in_array($type, self::CHOICE_TYPES, true)
                    ? array_values(array_map(fn (array $o): array => [
                        'value' => (string) $o['value'],
                        'label_ar' => trim((string) $o['label_ar']),
                        'label_en' => self::blankToNull($o['label_en'] ?? null),
                    ], array_filter((array) ($q['options'] ?? []), 'is_array')))
                    : [],
                'show_when' => $cond,
            ];
        }, array_filter($questions, 'is_array')));
    }

    /** @param array<string, mixed> $target */
    private function conditionValueFits(array $target, string $equals): bool
    {
        $type = (string) ($target['type'] ?? '');

        return match (true) {
            $type === 'yes_no' => in_array($equals, ['yes', 'no'], true),
            in_array($type, self::CHOICE_TYPES, true) => in_array($equals, array_map(fn ($o): string => (string) ($o['value'] ?? ''), (array) ($target['options'] ?? [])), true),
            default => $equals !== '',
        };
    }

    // ------------------------------------------------------------------ answers

    /**
     * Per-question error messages for `$answers` against the active version of `$key` (empty = valid).
     * `$enforceRequired = false` checks types only (drafts, e.g. an opportunity saved step by step).
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, string>
     */
    public function errors(string $key, array $answers, bool $enforceRequired = true): array
    {
        $questions = $this->questions($key);
        $visible = self::visibility($questions, $answers);
        $errors = [];

        foreach ($questions as $q) {
            $id = (string) $q['id'];
            if (! ($visible[$id] ?? false)) {
                continue;
            }
            $label = self::label($q);
            $raw = $answers[$id] ?? null;

            if (self::isBlank($raw)) {
                if ($enforceRequired && ($q['required'] ?? false)) {
                    $errors[$id] = __('validation.required', ['attribute' => $label]);
                }

                continue;
            }

            if (($message = $this->typeError($q, $raw, $label)) !== null) {
                $errors[$id] = $message;
            }
        }

        return $errors;
    }

    /** @param array<string, mixed> $q */
    private function typeError(array $q, mixed $raw, string $label): ?string
    {
        $options = array_map(fn ($o): string => (string) ($o['value'] ?? ''), (array) ($q['options'] ?? []));

        return match ((string) $q['type']) {
            'short_text' => ! is_string($raw) && ! is_numeric($raw) ? __('validation.string', ['attribute' => $label])
                : (mb_strlen(trim((string) $raw)) > self::SHORT_TEXT_MAX ? __('validation.max.string', ['attribute' => $label, 'max' => self::SHORT_TEXT_MAX]) : null),
            'long_text' => ! is_string($raw) && ! is_numeric($raw) ? __('validation.string', ['attribute' => $label])
                : (mb_strlen(trim((string) $raw)) > self::LONG_TEXT_MAX ? __('validation.max.string', ['attribute' => $label, 'max' => self::LONG_TEXT_MAX]) : null),
            'number' => ! is_numeric($raw) ? __('validation.numeric', ['attribute' => $label])
                : (abs((float) $raw) > self::NUMBER_MAX ? __('validation.max.numeric', ['attribute' => $label, 'max' => self::NUMBER_MAX]) : null),
            'single_choice' => is_scalar($raw) && in_array((string) $raw, $options, true) ? null : __('validation.in', ['attribute' => $label]),
            'multiple_choice' => is_array($raw) && array_diff(array_map('strval', array_filter($raw, 'is_scalar')), $options) === [] && count(array_filter($raw, 'is_scalar')) === count($raw)
                ? null : __('validation.in', ['attribute' => $label]),
            'yes_no' => self::toBool($raw) === null ? __('validation.boolean', ['attribute' => $label]) : null,
            'date' => is_string($raw) && self::isDate($raw) ? null : __('validation.date_format', ['attribute' => $label, 'format' => 'YYYY-MM-DD']),
            default => null,
        };
    }

    /**
     * The value to store: `{key, version, answers, answered_at}` — hidden / unknown / blank answers are dropped and
     * values cast to their type. Call after `errors()` came back empty (FormAnswers rule).
     *
     * Passing the currently stored value keeps it (and its `answered_at`) when nothing changed, so autosaves do not
     * rewrite the record or the audit trail.
     *
     * @param  array<string, mixed>  $answers
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    public function record(string $key, array $answers, ?array $stored = null): array
    {
        $questions = $this->questions($key);
        $visible = self::visibility($questions, $answers);
        $clean = [];

        foreach ($questions as $q) {
            $id = (string) $q['id'];
            if (! ($visible[$id] ?? false) || self::isBlank($answers[$id] ?? null)) {
                continue;
            }
            $clean[$id] = self::cast($q, $answers[$id]);
        }
        $version = (int) ($this->active($key)?->version ?? 0);

        if (is_array($stored) && ($stored['key'] ?? null) === $key && (int) ($stored['version'] ?? -1) === $version && ($stored['answers'] ?? null) === $clean) {
            return $stored;
        }

        return [
            'key' => $key,
            'version' => $version,
            'answers' => $clean,
            'answered_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Required + visible questions of the active version that `$stored` leaves unanswered (checklists / submission).
     *
     * @param  array<string, mixed>|null  $stored
     * @return list<string>
     */
    public function missingRequired(string $key, ?array $stored): array
    {
        $answers = (array) ($stored['answers'] ?? []);
        $questions = $this->questions($key);
        $visible = self::visibility($questions, $answers);

        return array_values(array_map(
            fn (array $q): string => (string) $q['id'],
            array_filter($questions, fn (array $q): bool => ($q['required'] ?? false) && ($visible[(string) $q['id']] ?? false) && self::isBlank($answers[(string) $q['id']] ?? null)),
        ));
    }

    /**
     * Resource view of stored answers: raw `answers` (to prefill a form) + labelled `items` built from the version
     * the answers were given against (so renamed / removed questions still read correctly).
     *
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>|null
     */
    public function present(?array $stored): ?array
    {
        if (! is_array($stored) || ! isset($stored['key'])) {
            return null;
        }
        $key = (string) $stored['key'];
        $version = (int) ($stored['version'] ?? 0);
        $answers = (array) ($stored['answers'] ?? []);
        $byId = collect(self::allQuestions($version > 0 ? $this->version($key, $version) : null))->keyBy('id');

        $items = [];
        foreach ($answers as $id => $value) {
            $q = $byId->get($id);
            if (! is_array($q)) {
                continue;
            }
            $optionLabels = collect((array) ($q['options'] ?? []))->keyBy('value');
            $pick = fn ($v): ?array => ($o = $optionLabels->get((string) $v)) ? ['ar' => $o['label_ar'] ?? (string) $v, 'en' => $o['label_en'] ?? null] : null;
            $items[] = [
                'id' => (string) $id,
                'type' => (string) $q['type'],
                'label_ar' => $q['label_ar'] ?? (string) $id,
                'label_en' => $q['label_en'] ?? null,
                'value' => $value,
                // choice answers carry their option labels (ar / en) for display
                'choices' => match ((string) $q['type']) {
                    'single_choice' => array_values(array_filter([$pick($value)])),
                    'multiple_choice' => array_values(array_filter(array_map($pick, (array) $value))),
                    default => [],
                },
            ];
        }

        return [
            'key' => $key,
            'version' => $version,
            'answered_at' => $stored['answered_at'] ?? null,
            'answers' => (object) $answers,
            'items' => $items,
            'current_version' => (int) ($this->active($key)?->version ?? 0),
        ];
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Which questions are shown, in order: a question with `show_when` is visible when its (earlier) driver is visible
     * and its answer matches.
     *
     * @param  list<array<string, mixed>>  $questions
     * @param  array<string, mixed>  $answers
     * @return array<string, bool>
     */
    public static function visibility(array $questions, array $answers): array
    {
        $visible = [];
        foreach ($questions as $q) {
            $id = (string) ($q['id'] ?? '');
            $cond = $q['show_when'] ?? null;
            if (! is_array($cond) || ($cond['question'] ?? '') === '') {
                $visible[$id] = true;

                continue;
            }
            $driver = (string) $cond['question'];
            $visible[$id] = ($visible[$driver] ?? false) && self::matches($answers[$driver] ?? null, (string) ($cond['equals'] ?? ''));
        }

        return $visible;
    }

    public static function matches(mixed $answer, string $equals): bool
    {
        if (self::isBlank($answer)) {
            return false;
        }
        if (is_array($answer)) {
            return in_array($equals, array_map('strval', array_filter($answer, 'is_scalar')), true);
        }
        $bool = is_bool($answer) ? $answer : null;
        if ($bool !== null || in_array($equals, ['yes', 'no'], true)) {
            $bool ??= self::toBool($answer);

            return $bool !== null && ($bool ? 'yes' : 'no') === $equals;
        }

        return (string) $answer === $equals;
    }

    /** @param array<string, mixed> $q */
    private static function cast(array $q, mixed $raw): mixed
    {
        return match ((string) $q['type']) {
            'short_text', 'long_text' => trim((string) $raw),
            'number' => (float) $raw == (int) $raw && abs((float) $raw) < PHP_INT_MAX ? (int) $raw : (float) $raw,
            'single_choice' => (string) $raw,
            'multiple_choice' => array_values(array_unique(array_map('strval', (array) $raw))),
            'yes_no' => self::toBool($raw),
            'date' => (string) $raw,
            default => $raw,
        };
    }

    /** @param array<string, mixed> $q */
    public static function label(array $q): string
    {
        $en = app()->getLocale() === 'en' ? ($q['label_en'] ?? null) : null;

        return (string) ($en ?: ($q['label_ar'] ?? $q['id'] ?? ''));
    }

    public static function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '') || $value === [];
    }

    private static function toBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return match (is_scalar($value) ? strtolower((string) $value) : null) {
            '1', 'true', 'yes' => true,
            '0', 'false', 'no' => false,
            default => null,
        };
    }

    private static function isDate(string $value): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y);
    }

    private static function blankToNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Summary row per form key for the builder index.
     *
     * @return list<array<string, mixed>>
     */
    public function summary(): array
    {
        $rows = FormSchema::query()->where('is_active', true)->get()->keyBy('key');

        return array_map(function (string $key) use ($rows): array {
            $s = $rows->get($key);
            $questions = self::allQuestions($s);

            return [
                'key' => $key,
                'version' => (int) ($s?->version ?? 0),
                'questions_count' => count($questions),
                'active_count' => count(array_filter($questions, fn (array $q): bool => (bool) Arr::get($q, 'active', true))),
                'published_at' => $s?->created_at?->toIso8601String(),
            ];
        }, self::KEYS);
    }
}

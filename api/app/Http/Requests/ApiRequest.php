<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base form request: authorization is handled by middleware/policies in controllers,
 * validation messages & attribute names come from lang/{ar,en}/validation.php.
 */
abstract class ApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return (array) __('validation.attributes');
    }

    protected function enumRule(string $enumClass): string
    {
        return 'in:'.implode(',', $enumClass::values());
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Support\PhoneNumbers;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Normalises phone inputs to E.164 before validation, so the `unique:users,phone` check and the
 * stored value always see the canonical form (`05…`, `9665…`, `009665…` → `+9665…`).
 *
 * @mixin FormRequest
 */
trait NormalizesPhone
{
    protected function normalizePhoneFields(string ...$fields): void
    {
        $merge = [];
        foreach ($fields as $field) {
            if ($this->has($field)) {
                $merge[$field] = PhoneNumbers::normalize($this->input($field));
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }
}

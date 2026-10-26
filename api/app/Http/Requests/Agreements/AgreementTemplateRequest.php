<?php

declare(strict_types=1);

namespace App\Http\Requests\Agreements;

/** AGR-01 — create a template (with its key) or a new version of one (POST …/versions: the key is kept). */
class AgreementTemplateRequest extends AgreementRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->route('template') === null;

        return [
            'key' => $creating ? ['required', 'string', 'max:80', 'regex:/^[a-z0-9_-]+$/'] : ['prohibited'],
            'title' => ['required', 'string', 'min:3', 'max:190'],
            'title_en' => ['nullable', 'string', 'min:3', 'max:190'],
            'body' => ['required', 'string', 'min:20', 'max:'.self::TEXT_MAX],
            'body_en' => ['nullable', 'string', 'min:20', 'max:'.self::TEXT_MAX],
            'description' => ['nullable', 'string', 'max:'.self::NOTE_MAX],
            'change_note' => ['nullable', 'string', 'max:'.self::NOTE_MAX],
        ];
    }
}
